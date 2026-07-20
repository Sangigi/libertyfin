<?php
session_start();

// Verificar autenticación
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

header('Content-Type: application/json');

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
    
    // Obtener datos de la solicitud
    $input = json_decode(file_get_contents('php://input'), true);
    
    $producto_id = isset($input['producto_id']) ? intval($input['producto_id']) : 0;
    $sucursal_origen_id = isset($input['sucursal_origen_id']) ? intval($input['sucursal_origen_id']) : 0;
    $sucursal_destino_id = isset($input['sucursal_destino_id']) ? intval($input['sucursal_destino_id']) : 0;
    $cantidad = isset($input['cantidad']) ? floatval($input['cantidad']) : 0;
    $observaciones = isset($input['observaciones']) ? trim($input['observaciones']) : '';
    $usuario_id = $_SESSION['usuario_id'] ?? 1;
    
    // Validaciones básicas
    if ($producto_id <= 0) {
        throw new Exception("ID de producto inválido");
    }
    
    if ($sucursal_origen_id <= 0 || $sucursal_destino_id <= 0) {
        throw new Exception("IDs de sucursal inválidos");
    }
    
    if ($sucursal_origen_id == $sucursal_destino_id) {
        throw new Exception("No se puede transferir a la misma sucursal");
    }
    
    if ($cantidad <= 0) {
        throw new Exception("La cantidad debe ser mayor a 0");
    }
    
    // Verificar que el producto existe y obtener su unidad de medida
    $sql_producto = "SELECT id, nombre, codigo, unidad_medida FROM productos WHERE id = ? AND activo = 1";
    $stmt_producto = $conn->prepare($sql_producto);
    $stmt_producto->bind_param("i", $producto_id);
    $stmt_producto->execute();
    $result_producto = $stmt_producto->get_result();
    
    if ($result_producto->num_rows == 0) {
        throw new Exception("Producto no encontrado o inactivo");
    }
    
    $producto = $result_producto->fetch_assoc();
    $stmt_producto->close();
    
    // Verificar stock en sucursal origen
    $sql_stock_origen = "SELECT stock, stock_minimo, sucursal_id FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?";
    $stmt_stock_origen = $conn->prepare($sql_stock_origen);
    $stmt_stock_origen->bind_param("ii", $producto_id, $sucursal_origen_id);
    $stmt_stock_origen->execute();
    $result_stock_origen = $stmt_stock_origen->get_result();
    
    if ($result_stock_origen->num_rows == 0) {
        throw new Exception("El producto no existe en la sucursal de origen");
    }
    
    $stock_origen = $result_stock_origen->fetch_assoc();
    $stock_actual_origen = floatval($stock_origen['stock']);
    $stmt_stock_origen->close();
    
    if ($stock_actual_origen < $cantidad) {
        throw new Exception("Stock insuficiente en sucursal de origen. Disponible: " . number_format($stock_actual_origen, 2) . " " . $producto['unidad_medida']);
    }
    
    // Verificar si el producto existe en sucursal destino
    $sql_stock_destino = "SELECT id, stock FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?";
    $stmt_stock_destino = $conn->prepare($sql_stock_destino);
    $stmt_stock_destino->bind_param("ii", $producto_id, $sucursal_destino_id);
    $stmt_stock_destino->execute();
    $result_stock_destino = $stmt_stock_destino->get_result();
    
    $existe_en_destino = $result_stock_destino->num_rows > 0;
    $stock_actual_destino = 0;
    
    if ($existe_en_destino) {
        $stock_destino = $result_stock_destino->fetch_assoc();
        $stock_actual_destino = floatval($stock_destino['stock']);
    }
    $stmt_stock_destino->close();
    
    // Iniciar transacción
    $conn->begin_transaction();
    
    // 1. Actualizar stock en sucursal origen (restar)
    $nuevo_stock_origen = $stock_actual_origen - $cantidad;
    $sql_update_origen = "UPDATE producto_sucursal SET stock = ? WHERE producto_id = ? AND sucursal_id = ?";
    $stmt_update_origen = $conn->prepare($sql_update_origen);
    $stmt_update_origen->bind_param("dii", $nuevo_stock_origen, $producto_id, $sucursal_origen_id);
    
    if (!$stmt_update_origen->execute()) {
        throw new Exception("Error al actualizar stock en sucursal origen: " . $stmt_update_origen->error);
    }
    $stmt_update_origen->close();
    
    // Registrar movimiento de SALIDA en la sucursal origen
    $sql_movimiento_origen = "INSERT INTO movimientos_inventario (producto_id, sucursal_id, tipo, cantidad, cantidad_anterior, cantidad_nueva, referencia_tipo, observaciones, usuario_id) VALUES (?, ?, 'salida', ?, ?, ?, 'ajuste', ?, ?)";
    $stmt_mov_origen = $conn->prepare($sql_movimiento_origen);
    // Cast a int para columnas int(11)
    $c_orig = intval($cantidad); $ca_orig = intval($stock_actual_origen); $cn_orig = intval($nuevo_stock_origen);
    $stmt_mov_origen->bind_param("iiiiisi", $producto_id, $sucursal_origen_id, $c_orig, $ca_orig, $cn_orig, $observaciones, $usuario_id);
    
    if (!$stmt_mov_origen->execute()) {
        throw new Exception("Error al registrar movimiento en origen: " . $stmt_mov_origen->error);
    }
    $stmt_mov_origen->close();
    
    // 2. Actualizar stock en sucursal destino (sumar)
    $nuevo_stock_destino = $stock_actual_destino + $cantidad;
    
    if ($existe_en_destino) {
        $sql_update_destino = "UPDATE producto_sucursal SET stock = ? WHERE producto_id = ? AND sucursal_id = ?";
        $stmt_update_destino = $conn->prepare($sql_update_destino);
        $stmt_update_destino->bind_param("dii", $nuevo_stock_destino, $producto_id, $sucursal_destino_id);
        
        if (!$stmt_update_destino->execute()) {
            throw new Exception("Error al actualizar stock en sucursal destino: " . $stmt_update_destino->error);
        }
        $stmt_update_destino->close();
    } else {
        // Crear registro en sucursal destino con stock mínimo por defecto
        $stock_minimo = 5; // Valor por defecto
        $sql_insert_destino = "INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo) VALUES (?, ?, ?, ?)";
        $stmt_insert_destino = $conn->prepare($sql_insert_destino);
        $stmt_insert_destino->bind_param("iidd", $producto_id, $sucursal_destino_id, $nuevo_stock_destino, $stock_minimo);
        
        if (!$stmt_insert_destino->execute()) {
            throw new Exception("Error al crear registro en sucursal destino: " . $stmt_insert_destino->error);
        }
        $stmt_insert_destino->close();
    }
    
    // Registrar movimiento de ENTRADA en la sucursal destino
    $sql_movimiento_destino = "INSERT INTO movimientos_inventario (producto_id, sucursal_id, tipo, cantidad, cantidad_anterior, cantidad_nueva, referencia_tipo, observaciones, usuario_id) VALUES (?, ?, 'entrada', ?, ?, ?, 'ajuste', ?, ?)";
    $stmt_mov_destino = $conn->prepare($sql_movimiento_destino);
    // Cast a int para columnas int(11)
    $c_dest = intval($cantidad); $ca_dest = intval($stock_actual_destino); $cn_dest = intval($nuevo_stock_destino);
    $stmt_mov_destino->bind_param("iiiiisi", $producto_id, $sucursal_destino_id, $c_dest, $ca_dest, $cn_dest, $observaciones, $usuario_id);
    
    if (!$stmt_mov_destino->execute()) {
        throw new Exception("Error al registrar movimiento en destino: " . $stmt_mov_destino->error);
    }
    $stmt_mov_destino->close();
    
    // Confirmar transacción
    $conn->commit();
    
    // Obtener información de las sucursales
    $sql_sucursal = "SELECT id, nombre FROM sucursales WHERE id IN (?, ?)";
    $stmt_sucursal = $conn->prepare($sql_sucursal);
    $stmt_sucursal->bind_param("ii", $sucursal_origen_id, $sucursal_destino_id);
    $stmt_sucursal->execute();
    $result_sucursal = $stmt_sucursal->get_result();
    
    $sucursales_info = [];
    while ($row = $result_sucursal->fetch_assoc()) {
        $sucursales_info[$row['id']] = $row['nombre'];
    }
    $stmt_sucursal->close();
    
    // Obtener información actualizada del stock
    $sql_stock_actualizado = "SELECT producto_id, sucursal_id, stock FROM producto_sucursal WHERE producto_id = ? AND sucursal_id IN (?, ?)";
    $stmt_stock_act = $conn->prepare($sql_stock_actualizado);
    $stmt_stock_act->bind_param("iii", $producto_id, $sucursal_origen_id, $sucursal_destino_id);
    $stmt_stock_act->execute();
    $result_stock_act = $stmt_stock_act->get_result();
    
    $stocks_actualizados = [];
    while ($row = $result_stock_act->fetch_assoc()) {
        $stocks_actualizados[$row['sucursal_id']] = floatval($row['stock']);
    }
    $stmt_stock_act->close();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => "✅ Transferencia exitosa: {$cantidad} " . $producto['unidad_medida'] . " de '{$producto['nombre']}' de {$sucursales_info[$sucursal_origen_id]} a {$sucursales_info[$sucursal_destino_id]}",
        'data' => [
            'producto' => $producto,
            'cantidad' => $cantidad,
            'sucursal_origen' => [
                'id' => $sucursal_origen_id,
                'nombre' => $sucursales_info[$sucursal_origen_id] ?? 'Desconocida',
                'stock_anterior' => $stock_actual_origen,
                'stock_nuevo' => $nuevo_stock_origen
            ],
            'sucursal_destino' => [
                'id' => $sucursal_destino_id,
                'nombre' => $sucursales_info[$sucursal_destino_id] ?? 'Desconocida',
                'stock_anterior' => $stock_actual_destino,
                'stock_nuevo' => $nuevo_stock_destino
            ],
            'stocks_actualizados' => $stocks_actualizados
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($conn) && $conn->connect_error === false) {
        $conn->rollback();
        $conn->close();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>