<?php
// eliminar_venta.php
session_start();

// Verificar si el usuario está logueado y es admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Se requieren permisos de administrador']);
    exit();
}

// Verificar que se recibió el ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de venta no válido']);
    exit();
}

$venta_id = intval($_POST['id']);

try {
    // Cargar configuración y funciones
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/env_loader.php';
    
    // Conectar a la base de datos de la empresa
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    
    // Verificar que la venta existe
    $sql_check = "SELECT id, estado, total FROM ventas WHERE id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([$venta_id]);
    $venta = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if (!$venta) {
        echo json_encode(['success' => false, 'message' => 'La venta no existe']);
        exit();
    }
    
    // Iniciar transacción
    $conn->beginTransaction();
    
    // 1. Restaurar inventario (si la venta estaba completada)
    if ($venta['estado'] === 'completada') {
        // Obtener los detalles de la venta
        $sql_detalles = "SELECT producto_id, cantidad FROM venta_detalles WHERE venta_id = ?";
        $stmt_detalles = $conn->prepare($sql_detalles);
        $stmt_detalles->execute([$venta_id]);
        $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
        
        // Restaurar stock de cada producto
        foreach ($detalles as $detalle) {
            $sql_stock = "UPDATE productos SET stock = stock + ? WHERE id = ?";
            $stmt_stock = $conn->prepare($sql_stock);
            $stmt_stock->execute([$detalle['cantidad'], $detalle['producto_id']]);
        }
    }
    
    // 2. Eliminar los detalles de la venta
    $sql_delete_detalles = "DELETE FROM venta_detalles WHERE venta_id = ?";
    $stmt_delete_detalles = $conn->prepare($sql_delete_detalles);
    $stmt_delete_detalles->execute([$venta_id]);

    // 2.1 Eliminar el gasto automatico (costo de mercancia) ligado a esta venta
    $sql_delete_gasto = "DELETE FROM gastos WHERE venta_id = ? AND tipo = 'automatico'";
    $stmt_delete_gasto = $conn->prepare($sql_delete_gasto);
    $stmt_delete_gasto->execute([$venta_id]);

    // 3. Eliminar la venta
    $sql_delete_venta = "DELETE FROM ventas WHERE id = ?";
    $stmt_delete_venta = $conn->prepare($sql_delete_venta);
    $stmt_delete_venta->execute([$venta_id]);
    
    // Confirmar transacción
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Venta eliminada correctamente']);
    
} catch (Exception $e) {
    // Revertir transacción en caso de error
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error al eliminar: ' . $e->getMessage()]);
}
?>