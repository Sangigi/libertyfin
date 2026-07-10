<?php
session_start();
header('Content-Type: application/json');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Verificar que sea admin
if ($_SESSION['usuario_rol'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'No tienes permisos para eliminar productos']);
    exit();
}

$producto_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$confirmacion = isset($_POST['confirmacion']) && ($_POST['confirmacion'] === 'true' || $_POST['confirmacion'] === true);

if ($producto_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de producto inválido']);
    exit();
}

// Log para depuración
error_log("Intentando eliminar producto ID: $producto_id, Confirmación: " . ($confirmacion ? 'SI' : 'NO'));

if (!$confirmacion) {
    echo json_encode(['success' => false, 'message' => 'Se requiere confirmación para eliminar el producto']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]);
    exit();
}

// VERIFICAR NUEVAMENTE que no tenga dependencias
$tiene_dependencias = false;
$mensaje_error = '';

// Verificar ventas
$sql_ventas = "SELECT COUNT(*) as total FROM venta_detalles WHERE producto_id = ?";
$stmt = $conn->prepare($sql_ventas);
$stmt->bind_param("i", $producto_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if ($row['total'] > 0) {
    $tiene_dependencias = true;
    $mensaje_error .= "• Tiene {$row['total']} registro(s) en ventas\n";
}
$stmt->close();

// Verificar compras
$sql_compras = "SELECT COUNT(*) as total FROM compra_detalles WHERE producto_id = ?";
$stmt = $conn->prepare($sql_compras);
$stmt->bind_param("i", $producto_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if ($row['total'] > 0) {
    $tiene_dependencias = true;
    $mensaje_error .= "• Tiene {$row['total']} registro(s) en compras\n";
}
$stmt->close();

// Verificar movimientos de inventario
$sql_movimientos = "SELECT COUNT(*) as total FROM movimientos_inventario WHERE producto_id = ?";
$stmt = $conn->prepare($sql_movimientos);
$stmt->bind_param("i", $producto_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if ($row['total'] > 0) {
    $tiene_dependencias = true;
    $mensaje_error .= "• Tiene {$row['total']} registro(s) en movimientos de inventario\n";
}
$stmt->close();

if ($tiene_dependencias) {
    $conn->close();
    echo json_encode([
        'success' => false,
        'message' => "No se puede eliminar el producto porque tiene registros asociados:\n" . $mensaje_error . "\n💡 Sugerencia: Desactive el producto en lugar de eliminarlo."
    ]);
    exit();
}

// Iniciar transacción
$conn->begin_transaction();

try {
    // Obtener nombre del producto para logs
    $sql_nombre = "SELECT nombre FROM productos WHERE id = ?";
    $stmt = $conn->prepare($sql_nombre);
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $producto_nombre = $result->fetch_assoc()['nombre'] ?? 'Desconocido';
    $stmt->close();
    
    error_log("Eliminando producto: ID=$producto_id, Nombre=$producto_nombre");
    
    // 1. Eliminar imágenes de la BD
    $sql = "DELETE FROM producto_imagenes WHERE producto_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $stmt->close();
    error_log("✓ Imágenes eliminadas de BD");
    
    // 2. Eliminar precios de mayoreo
    $sql = "DELETE FROM producto_precios_mayoreo WHERE producto_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $stmt->close();
    error_log("✓ Precios de mayoreo eliminados");
    
    // 3. Eliminar relaciones con sucursales
    $sql = "DELETE FROM producto_sucursal WHERE producto_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $stmt->close();
    error_log("✓ Relaciones con sucursales eliminadas");
    
    // 4. Finalmente, eliminar el producto
    $sql = "DELETE FROM productos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception("No se encontró el producto con ID: " . $producto_id);
    }
    $stmt->close();
    error_log("✓ Producto eliminado de BD");
    
    // 5. (Opcional) Eliminar archivos de imagen físicos
    $directorios_imagenes = [
        $_SERVER['DOCUMENT_ROOT'] . "/uploads/productos/",
        dirname(__FILE__) . "/uploads/productos/",
        __DIR__ . "/uploads/productos/"
    ];
    
    $archivos_eliminados = 0;
    foreach ($directorios_imagenes as $directorio) {
        if (is_dir($directorio)) {
            $archivos = glob($directorio . "producto_{$producto_id}_*.*");
            foreach ($archivos as $archivo) {
                if (is_file($archivo) && unlink($archivo)) {
                    $archivos_eliminados++;
                    error_log("✓ Archivo eliminado: " . $archivo);
                }
            }
        }
    }
    
    if ($archivos_eliminados > 0) {
        error_log("✓ $archivos_eliminados archivos de imagen eliminados");
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Producto \"$producto_nombre\" eliminado exitosamente" . ($archivos_eliminados > 0 ? " (incluyendo $archivos_eliminados imagen(es))" : "")
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("ERROR al eliminar producto: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar producto: ' . $e->getMessage()
    ]);
}

$conn->close();
?>