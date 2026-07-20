<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'] ?? '';

if (empty($dbname)) {
    echo json_encode(['success' => false, 'message' => 'Error de configuración de base de datos']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$producto_id = intval($_POST['producto_id'] ?? 0);
$cantidad = floatval($_POST['cantidad'] ?? 1);

if ($producto_id <= 0 || $cantidad <= 0) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
    
    // Obtener información del producto
    $sql_producto = "
        SELECT 
            p.id,
            p.codigo,
            p.nombre,
            p.precio as precio_sin_iva,
            p.costo,
            p.unidad_medida,
            p.peso_kg,
            p.permite_fracciones,
            COALESCE(ps.stock, 0) as stock_sucursal
        FROM productos p 
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
        WHERE p.id = ? 
        AND p.activo = 1
    ";
    
    $stmt = $conn->prepare($sql_producto);
    $stmt->bind_param("ii", $_SESSION['sucursal_id'], $producto_id);
    $stmt->execute();
    $producto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$producto) {
        echo json_encode(['success' => false, 'message' => 'Producto no encontrado']);
        exit;
    }
    
    $stock_disponible = $producto['stock_sucursal'] ?? 0;
    
    // Validar stock
    if ($producto['permite_fracciones'] == 0) {
        // Productos sin fracciones - validar stock entero
        $cantidad = intval($cantidad);
        if ($cantidad > $stock_disponible) {
            echo json_encode(['success' => false, 'message' => "Stock insuficiente para: " . $producto['nombre'] . " (Stock disponible: $stock_disponible)"]);
            exit;
        }
    } else {
        // Productos con fracciones - validar que haya stock
        if ($stock_disponible <= 0) {
            echo json_encode(['success' => false, 'message' => 'Producto sin stock: ' . $producto['nombre']]);
            exit;
        }
    }
    
    // Determinar tipo de venta
    $tipo_venta = 'unidad';
    if ($producto['permite_fracciones'] == 1 && $producto['unidad_medida'] != 'unidad') {
        $tipo_venta = $producto['unidad_medida'];
    }
    
    // Inicializar carrito si no existe
    if (!isset($_SESSION['carrito'])) {
        $_SESSION['carrito'] = [];
    }
    
    // Buscar si el producto ya está en el carrito
    $encontrado = false;
    foreach ($_SESSION['carrito'] as &$item) {
        if ($item['id'] == $producto['id']) {
            $nueva_cantidad = $item['cantidad'] + $cantidad;
            
            // Validar stock nuevamente para la cantidad acumulada
            if ($producto['permite_fracciones'] == 0 && $nueva_cantidad > $stock_disponible) {
                echo json_encode(['success' => false, 'message' => "Stock insuficiente para: " . $producto['nombre'] . " (Stock disponible: $stock_disponible)"]);
                exit;
            }
            
            $item['cantidad'] = $nueva_cantidad;
            $item['subtotal'] = $nueva_cantidad * $item['precio'];
            $encontrado = true;
            break;
        }
    }
    
    if (!$encontrado) {
        $_SESSION['carrito'][] = [
            'id' => $producto['id'],
            'codigo' => $producto['codigo'],
            'nombre' => $producto['nombre'],
            'precio' => floatval($producto['precio_sin_iva']),
            'precio_sin_iva' => floatval($producto['precio_sin_iva']),
            'costo' => floatval($producto['costo'] ?? 0),
            'cantidad' => $cantidad,
            'subtotal' => floatval($producto['precio_sin_iva']) * $cantidad,
            'tipo_venta' => $tipo_venta,
            'unidad_medida' => $producto['unidad_medida'],
            'peso_kg' => $producto['peso_kg'],
            'permite_fracciones' => $producto['permite_fracciones']
        ];
    }
    
    // Calcular totales actualizados
    $subtotal_carrito = 0;
    foreach ($_SESSION['carrito'] as $item) {
        $subtotal_carrito += $item['subtotal'];
    }
    $total_carrito = $subtotal_carrito;
    
    echo json_encode([
        'success' => true,
        'message' => '✅ ' . $producto['nombre'] . ' agregado al carrito',
        'carrito' => [
            'cantidad_productos' => count($_SESSION['carrito']),
            'total_carrito' => $total_carrito
        ],
        'producto_agregado' => $producto
    ]);
    
} catch (Exception $e) {
    error_log("Error en agregar_al_carrito.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del sistema. Contacte al administrador.']);
}
?>