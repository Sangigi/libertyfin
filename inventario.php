<?php

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}
// OBTENER EL PLAN DE LA EMPRESA Y DATOS DE TIMBRES DESDE LA BASE DE DATOS PRINCIPAL
$servername_main = "libertyfin.com.mx";
$username_main = "juanc141_alexis";
$password_main = "Alexis1997";
$dbname_main = "juanc141_ventas";

$conn_main = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

// Valores por defecto
$empresa_plan = "prueba";
$timbres_totales = 0;
$timbres_disponibles = 0;

if ($conn_main) {
    $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->bind_param("i", $_SESSION['empresa_id']);
    $stmt_empresa->execute();
    $result_empresa = $stmt_empresa->get_result();

    if ($result_empresa->num_rows > 0) {
        $empresa_data = $result_empresa->fetch_assoc();
        $empresa_plan = $empresa_data['plan'];
        $timbres_totales = $empresa_data['timbres_totales'] ?? 0;
        $timbres_disponibles = $empresa_data['timbres_disponibles'] ?? 0;
    }
    $stmt_empresa->close();
    $conn_main->close();
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Obtener sucursal seleccionada del filtro
$sucursal_filtro = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : null;

// Conectar a la base de datos de la empresa
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener colores personalizados de la configuración
    $sql_colores = "SELECT color_primario, color_secundario FROM sistema_config LIMIT 1";
    $result_colores = $conn->query($sql_colores);
    if ($result_colores->num_rows > 0) {
        $colores_config = $result_colores->fetch_assoc();
        $_SESSION['color_primario'] = $colores_config['color_primario'] ?? '#27ae60';
        $_SESSION['color_secundario'] = $colores_config['color_secundario'] ?? '#2ecc71';
    } else {
        $_SESSION['color_primario'] = '#27ae60';
        $_SESSION['color_secundario'] = '#2ecc71';
    }

    // Función para ajustar brillo de color
    function adjustBrightness($hex, $percent)
    {
        $hex = str_replace('#', '', $hex);
        if (strlen($hex) == 3) {
            $hex = str_repeat(substr($hex, 0, 1), 2) . str_repeat(substr($hex, 1, 1), 2) . str_repeat(substr($hex, 2, 1), 2);
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r + $r * $percent / 100));
        $g = max(0, min(255, $g + $g * $percent / 100));
        $b = max(0, min(255, $b + $b * $percent / 100));

        return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT)
            . str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
            . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }

    // Obtener información de la empresa
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch_assoc();

    // OBTENER LOGO DE LA EMPRESA - COMO EN CAJA.PHP
    $logo_empresa = null;
    $logo_src_base64 = null;

    if (!empty($empresa_info['logo'])) {
        $empresa_logo = $empresa_info['logo'];
        $logo_path = '';
        $rutas_posibles = [
            $empresa_logo,
            '../' . $empresa_logo,
            '../../' . $empresa_logo,
            'admin/' . $empresa_logo,
            '../admin/' . $empresa_logo,
            'logos/' . $empresa_logo,
            'img/' . $empresa_logo,
            'images/' . $empresa_logo,
            'assets/' . $empresa_logo,
            'uploads/' . $empresa_logo,
            '../logos/' . $empresa_logo,
            '../img/' . $empresa_logo,
            '../images/' . $empresa_logo,
            '../assets/' . $empresa_logo,
            '../uploads/' . $empresa_logo
        ];

        foreach ($rutas_posibles as $ruta) {
            if (file_exists($ruta) && is_file($ruta)) {
                $logo_path = $ruta;
                break;
            }
        }

        // Si encontramos el logo, convertirlo a base64
        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;

            // Obtener la extensión del archivo
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));

            // Verificar que sea una imagen válida
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                // Leer el archivo y convertirlo a base64
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
    }
    // Construir consulta base para productos
    $sql_base = "
        SELECT 
            p.*, 
            c.nombre as categoria_nombre, 
            c.activo as categoria_activa,
            COALESCE(SUM(ps.stock), 0) as stock_total,
            COALESCE(MIN(ps.stock_minimo), 0) as stock_minimo_total
    ";

    // Si hay una sucursal seleccionada, obtener el stock específico de esa sucursal
    if ($sucursal_filtro) {
        $sql_base .= ", 
            COALESCE(
                (SELECT ps2.stock FROM producto_sucursal ps2 
                 WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?), 
                0
            ) as stock_sucursal,
            COALESCE(
                (SELECT ps2.stock_minimo FROM producto_sucursal ps2 
                 WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?), 
                0
            ) as stock_minimo_sucursal
        ";
    } else {
        $sql_base .= ", 0 as stock_sucursal, 0 as stock_minimo_sucursal";
    }

    $sql_base .= "
        FROM productos p 
        LEFT JOIN categorias c ON p.categoria_id = c.id 
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
        LEFT JOIN sucursales s ON ps.sucursal_id = s.id
    ";

    // Obtener el total de registros para paginación
    $sql_count = "SELECT COUNT(DISTINCT p.id) as total 
                  FROM productos p 
                  LEFT JOIN categorias c ON p.categoria_id = c.id 
                  LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
                  LEFT JOIN sucursales s ON ps.sucursal_id = s.id";

    if ($sucursal_filtro) {
        $sql_count .= " WHERE EXISTS (SELECT 1 FROM producto_sucursal ps2 WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?)";
    }

    $stmt_count = $conn->prepare($sql_count);
    if ($sucursal_filtro) {
        $stmt_count->bind_param("i", $sucursal_filtro);
    }
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_registros = $result_count->fetch_assoc()['total'];
    $stmt_count->close();

    // Calcular total de páginas
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // Construir consulta final con GROUP BY, ORDER BY y LIMIT
    $sql_productos = $sql_base . " GROUP BY p.id ORDER BY p.fecha_actualizacion DESC LIMIT ? OFFSET ?";

    $stmt_productos = $conn->prepare($sql_productos);

    if ($sucursal_filtro) {
        $stmt_productos->bind_param("iiii", $sucursal_filtro, $sucursal_filtro, $registros_por_pagina, $offset);
    } else {
        $stmt_productos->bind_param("ii", $registros_por_pagina, $offset);
    }

    $stmt_productos->execute();
    $result_productos = $stmt_productos->get_result();
    $productos = [];
    while ($row = $result_productos->fetch_assoc()) {
        // Sanitizar valores
        $row['descripcion'] = $row['descripcion'] ?? '';

        // Si hay sucursal seleccionada, usar el stock de esa sucursal
        if ($sucursal_filtro) {
            $row['stock_mostrar'] = $row['stock_sucursal'];
            $row['stock_minimo_mostrar'] = $row['stock_minimo_sucursal'];
        } else {
            $row['stock_mostrar'] = $row['stock_total'];
            $row['stock_minimo_mostrar'] = $row['stock_minimo_total'];
        }

        $productos[] = $row;
    }
    $stmt_productos->close();

    // Obtener estadísticas del inventario (sin paginación para mostrar totales)
    $sql_stats = "
        SELECT 
            COUNT(*) as total_productos,
            SUM(CASE WHEN stock_total <= stock_minimo_total THEN 1 ELSE 0 END) as productos_bajo_stock,
            SUM(CASE WHEN stock_total = 0 THEN 1 ELSE 0 END) as productos_sin_stock,
            SUM(stock_total) as stock_total,
            SUM(p.precio * stock_total) as valor_total_inventario,
            AVG(p.precio) as precio_promedio
        FROM (
            SELECT 
                p.id,
                p.precio,
                COALESCE(SUM(ps.stock), 0) as stock_total,
                COALESCE(MIN(ps.stock_minimo), 0) as stock_minimo_total
            FROM productos p
            LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
            WHERE p.activo = 1
            GROUP BY p.id
        ) as p
    ";
    $result_stats = $conn->query($sql_stats);
    $stats_inventario = $result_stats->fetch_assoc();

    // Obtener categorías para filtros
    $sql_categorias = "SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre";
    $result_categorias = $conn->query($sql_categorias);
    $categorias = [];
    while ($row = $result_categorias->fetch_assoc()) {
        $categorias[] = $row;
    }

    // Obtener sucursales para filtros
    $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre";
    $result_sucursales = $conn->query($sql_sucursales);
    $sucursales = [];
    while ($row = $result_sucursales->fetch_assoc()) {
        $sucursales[] = $row;
    }

    // Obtener stock por sucursal para cada producto
    $stock_por_sucursal = [];
    $sql_stock = "SELECT producto_id, sucursal_id, stock, stock_minimo FROM producto_sucursal";
    $result_stock = $conn->query($sql_stock);
    while ($row = $result_stock->fetch_assoc()) {
        $stock_por_sucursal[$row['producto_id']][$row['sucursal_id']] = [
            'stock' => $row['stock'],
            'stock_minimo' => $row['stock_minimo']
        ];
    }

    $total_productos = $stats_inventario['total_productos'] ?? 0;
    $productos_bajo_stock = $stats_inventario['productos_bajo_stock'] ?? 0;
    $productos_sin_stock = $stats_inventario['productos_sin_stock'] ?? 0;
    $valor_total_inventario = $stats_inventario['valor_total_inventario'] ?? 0;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Función para registrar movimientos en el historial
function registrarMovimiento($conn, $producto_id, $sucursal_id, $tipo_movimiento, $cantidad, $stock_anterior, $stock_nuevo, $motivo)
{
    try {
        // Mapear tipos de movimiento a los valores ENUM de la tabla
        $tipos_enum = [
            'entrada' => 'entrada',
            'salida' => 'salida',
            'ajuste' => 'ajuste',
            'transferencia_entrada' => 'entrada',
            'transferencia_salida' => 'salida',
            'daño' => 'salida',
            'vencimiento' => 'salida'
        ];

        $tipo_enum = isset($tipos_enum[$tipo_movimiento]) ? $tipos_enum[$tipo_movimiento] : 'ajuste';

        // Determinar referencia_tipo
        $referencia_tipo = 'ajuste';
        if (strpos($tipo_movimiento, 'transferencia') !== false) {
            $referencia_tipo = 'ajuste';
        } elseif (in_array($tipo_movimiento, ['entrada', 'salida', 'ajuste', 'daño', 'vencimiento'])) {
            $referencia_tipo = 'ajuste';
        }

        $sql_movimiento = "INSERT INTO movimientos_inventario 
                          (producto_id, sucursal_id, tipo, cantidad, cantidad_anterior, cantidad_nueva, referencia_tipo, observaciones, usuario_id) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt_movimiento = $conn->prepare($sql_movimiento);
        $usuario_id = $_SESSION['usuario_id'] ?? 1;

        $stmt_movimiento->bind_param(
            "iisiiissi",
            $producto_id,
            $sucursal_id,
            $tipo_enum,
            $cantidad,
            $stock_anterior,
            $stock_nuevo,
            $referencia_tipo,
            $motivo,
            $usuario_id
        );

        $stmt_movimiento->execute();
        $stmt_movimiento->close();
    } catch (Exception $e) {
        error_log("Error al registrar movimiento: " . $e->getMessage());
    }
}

// Función para obtener el historial de movimientos
function obtenerHistorialMovimientos($conn, $producto_id = null, $sucursal_id = null, $limit = 50)
{
    $movimientos = [];

    try {
        $sql = "
            SELECT 
                mi.*,
                p.nombre as producto_nombre,
                s.nombre as sucursal_nombre,
                u.nombre as usuario_nombre,
                DATE_FORMAT(mi.fecha, '%d/%m/%Y %H:%i') as fecha_formateada
            FROM movimientos_inventario mi
            LEFT JOIN productos p ON mi.producto_id = p.id
            LEFT JOIN sucursales s ON mi.sucursal_id = s.id  
            LEFT JOIN usuarios u ON mi.usuario_id = u.id
            WHERE 1=1
        ";

        $params = [];
        $types = "";

        if ($producto_id) {
            $sql .= " AND mi.producto_id = ?";
            $params[] = $producto_id;
            $types .= "i";
        }

        if ($sucursal_id) {
            $sql .= " AND mi.sucursal_id = ?";
            $params[] = $sucursal_id;
            $types .= "i";
        }

        $sql .= " ORDER BY mi.fecha DESC LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $conn->prepare($sql);

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $movimientos[] = $row;
        }

        $stmt->close();
    } catch (Exception $e) {
        error_log("Error al obtener historial: " . $e->getMessage());
    }

    return $movimientos;
}

// Obtener historial para mostrar en el modal
$historial_movimientos = obtenerHistorialMovimientos($conn, null, $sucursal_filtro, 20);

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'ajustar_stock':
                ajustarStock($conn);
                break;
            case 'transferir_stock':
                transferirStock($conn);
                break;
            case 'cambiar_estado':
                cambiarEstadoProducto($conn);
                break;
        }
    }
}

function ajustarStock($conn)
{
    $producto_id = intval($_POST['producto_id']);
    $sucursal_id = intval($_POST['sucursal_id']);
    $nuevo_stock = intval($_POST['nuevo_stock']);
    $tipo_ajuste = $_POST['tipo_ajuste'];
    $motivo = trim($conn->real_escape_string($_POST['motivo']));

    try {
        // Obtener stock actual de la sucursal
        $sql_actual = "SELECT stock FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?";
        $stmt_actual = $conn->prepare($sql_actual);
        $stmt_actual->bind_param("ii", $producto_id, $sucursal_id);
        $stmt_actual->execute();
        $result_actual = $stmt_actual->get_result();

        if ($result_actual->num_rows > 0) {
            $stock_actual = $result_actual->fetch_assoc()['stock'];

            // Actualizar stock en la sucursal específica
            $sql_update = "UPDATE producto_sucursal SET stock = ? WHERE producto_id = ? AND sucursal_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("iii", $nuevo_stock, $producto_id, $sucursal_id);
        } else {
            // Insertar nueva relación producto-sucursal
            $sql_update = "INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo) VALUES (?, ?, ?, 0)";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("iii", $producto_id, $sucursal_id, $nuevo_stock);
            $stock_actual = 0;
        }

        if ($stmt_update->execute()) {
            // Actualizar fecha de actualización del producto
            $sql_update_producto = "UPDATE productos SET fecha_actualizacion = NOW() WHERE id = ?";
            $stmt_update_producto = $conn->prepare($sql_update_producto);
            $stmt_update_producto->bind_param("i", $producto_id);
            $stmt_update_producto->execute();
            $stmt_update_producto->close();

            // Registrar movimiento de inventario
            registrarMovimiento($conn, $producto_id, $sucursal_id, $tipo_ajuste, abs($nuevo_stock - $stock_actual), $stock_actual, $nuevo_stock, $motivo);

            $_SESSION['mensaje'] = "Stock actualizado exitosamente en la sucursal";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al actualizar stock: " . $stmt_update->error);
        }

        $stmt_actual->close();
        $stmt_update->close();
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: inventario.php');
    exit();
}

function transferirStock($conn)
{
    $producto_id = intval($_POST['producto_id']);
    $sucursal_origen = intval($_POST['sucursal_origen']);
    $sucursal_destino = intval($_POST['sucursal_destino']);
    $cantidad = intval($_POST['cantidad_transferencia']);
    $motivo = trim($conn->real_escape_string($_POST['motivo_transferencia']));

    // Validaciones
    if ($sucursal_origen == $sucursal_destino) {
        $_SESSION['mensaje'] = "La sucursal origen y destino no pueden ser la misma";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: inventario.php');
        exit();
    }

    if ($cantidad <= 0) {
        $_SESSION['mensaje'] = "La cantidad a transferir debe ser mayor a cero";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: inventario.php');
        exit();
    }

    try {
        $conn->begin_transaction();

        // Obtener stock actual en sucursal origen
        $sql_origen = "SELECT stock FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?";
        $stmt_origen = $conn->prepare($sql_origen);
        $stmt_origen->bind_param("ii", $producto_id, $sucursal_origen);
        $stmt_origen->execute();
        $result_origen = $stmt_origen->get_result();

        if ($result_origen->num_rows == 0) {
            throw new Exception("No existe stock del producto en la sucursal origen");
        }

        $stock_origen = $result_origen->fetch_assoc()['stock'];

        // Verificar stock suficiente
        if ($stock_origen < $cantidad) {
            throw new Exception("Stock insuficiente en la sucursal origen. Stock disponible: $stock_origen");
        }

        // Obtener stock actual en sucursal destino
        $sql_destino = "SELECT stock FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?";
        $stmt_destino = $conn->prepare($sql_destino);
        $stmt_destino->bind_param("ii", $producto_id, $sucursal_destino);
        $stmt_destino->execute();
        $result_destino = $stmt_destino->get_result();

        $stock_destino = 0;
        if ($result_destino->num_rows > 0) {
            $stock_destino = $result_destino->fetch_assoc()['stock'];
        }

        // Calcular nuevos stocks
        $nuevo_stock_origen = $stock_origen - $cantidad;
        $nuevo_stock_destino = $stock_destino + $cantidad;

        // Actualizar sucursal origen
        $sql_update_origen = "UPDATE producto_sucursal SET stock = ? WHERE producto_id = ? AND sucursal_id = ?";
        $stmt_update_origen = $conn->prepare($sql_update_origen);
        $stmt_update_origen->bind_param("iii", $nuevo_stock_origen, $producto_id, $sucursal_origen);

        // Actualizar o insertar sucursal destino
        if ($result_destino->num_rows > 0) {
            $sql_update_destino = "UPDATE producto_sucursal SET stock = ? WHERE producto_id = ? AND sucursal_id = ?";
            $stmt_update_destino = $conn->prepare($sql_update_destino);
            $stmt_update_destino->bind_param("iii", $nuevo_stock_destino, $producto_id, $sucursal_destino);
        } else {
            $sql_update_destino = "INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo) VALUES (?, ?, ?, 0)";
            $stmt_update_destino = $conn->prepare($sql_update_destino);
            $stmt_update_destino->bind_param("iii", $producto_id, $sucursal_destino, $nuevo_stock_destino);
        }

        // Ejecutar actualizaciones
        if (!$stmt_update_origen->execute()) {
            throw new Exception("Error al actualizar stock en sucursal origen");
        }

        if (!$stmt_update_destino->execute()) {
            throw new Exception("Error al actualizar stock en sucursal destino");
        }

        // Actualizar fecha del producto
        $sql_update_producto = "UPDATE productos SET fecha_actualizacion = NOW() WHERE id = ?";
        $stmt_update_producto = $conn->prepare($sql_update_producto);
        $stmt_update_producto->bind_param("i", $producto_id);
        $stmt_update_producto->execute();

        // Registrar movimientos
        registrarMovimiento($conn, $producto_id, $sucursal_origen, 'transferencia_salida', $cantidad, $stock_origen, $nuevo_stock_origen, "Transferencia a sucursal destino: $motivo");
        registrarMovimiento($conn, $producto_id, $sucursal_destino, 'transferencia_entrada', $cantidad, $stock_destino, $nuevo_stock_destino, "Transferencia desde sucursal origen: $motivo");

        $conn->commit();

        $_SESSION['mensaje'] = "Transferencia de stock realizada exitosamente";
        $_SESSION['tipo_mensaje'] = "success";

        $stmt_origen->close();
        $stmt_destino->close();
        $stmt_update_origen->close();
        $stmt_update_destino->close();
        $stmt_update_producto->close();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: inventario.php');
    exit();
}

function cambiarEstadoProducto($conn)
{
    $id = intval($_POST['id']);
    $activo = intval($_POST['activo']);

    try {
        $sql = "UPDATE productos SET activo = ?, fecha_actualizacion = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $activo, $id);

        if ($stmt->execute()) {
            $estado = $activo ? "activado" : "desactivado";
            $_SESSION['mensaje'] = "Producto $estado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al cambiar estado: " . $stmt->error);
        }

        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: inventario.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo $_SESSION['color_primario']; ?>;
            --secondary-color: <?php echo $_SESSION['color_secundario']; ?>;
            --primary-light: <?php echo adjustBrightness($_SESSION['color_primario'], 30); ?>;
            --primary-dark: <?php echo adjustBrightness($_SESSION['color_primario'], -30); ?>;
            --secondary-light: <?php echo adjustBrightness($_SESSION['color_secundario'], 30); ?>;
            --secondary-dark: <?php echo adjustBrightness($_SESSION['color_secundario'], -30); ?>;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
        }

        /* Navbar con colores personalizados */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)) !important;
        }

        .navbar-brand {
            color: white !important;
            font-weight: 600;
        }

        /* Estilos para el logo en el navbar */
        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .navbar-nav .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
        }

        .navbar-nav .nav-link:hover {
            color: white !important;
        }

        .navbar-nav .dropdown-menu {
            border: 1px solid rgba(0, 0, 0, .15);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, .1);
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: transform 0.3s ease-out;
            will-change: transform;
        }

        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        /* Botones con colores personalizados */
        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }

        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: var(--primary-dark) !important;
            border-color: var(--primary-dark) !important;
            color: white !important;
        }

        .btn-outline-primary {
            color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }

        .btn-outline-primary:hover,
        .btn-outline-primary:focus,
        .btn-outline-primary:active {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }

        .btn-secondary {
            background-color: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
            color: white !important;
        }

        .btn-secondary:hover,
        .btn-secondary:focus,
        .btn-secondary:active {
            background-color: var(--secondary-dark) !important;
            border-color: var(--secondary-dark) !important;
            color: white !important;
        }

        .btn-outline-secondary {
            color: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
        }

        .btn-outline-secondary:hover,
        .btn-outline-secondary:focus,
        .btn-outline-secondary:active {
            background-color: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
            color: white !important;
        }

        .btn-success {
            background-color: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
            color: white !important;
        }

        .btn-success:hover,
        .btn-success:focus,
        .btn-success:active {
            background-color: var(--secondary-dark) !important;
            border-color: var(--secondary-dark) !important;
            color: white !important;
        }

        .btn-outline-success {
            color: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
        }

        .btn-outline-success:hover,
        .btn-outline-success:focus,
        .btn-outline-success:active {
            background-color: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
            color: white !important;
        }

        /* Badges con colores personalizados */
        .badge-primary,
        .badge.bg-primary {
            background-color: var(--primary-color) !important;
        }

        .badge-secondary,
        .badge.bg-secondary {
            background-color: var(--secondary-color) !important;
        }

        /* Paginación con colores personalizados */
        .page-link {
            color: var(--primary-color);
        }

        .page-item.active .page-link {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }

        .page-link:hover {
            color: var(--primary-dark);
            background-color: #e9ecef;
            border-color: #dee2e6;
        }

        /* Alertas con colores personalizados */
        .alert-primary {
            background-color: rgba(var(--primary-rgb), 0.1);
            border-color: rgba(var(--primary-rgb), 0.2);
            color: var(--primary-dark);
        }

        .alert-success {
            background-color: rgba(var(--secondary-rgb), 0.1);
            border-color: rgba(var(--secondary-rgb), 0.2);
            color: var(--secondary-dark);
        }

        /* Cards y bordes */
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .stat-card {
            border-left: 4px solid var(--primary-color) !important;
        }

        .mobile-producto-card {
            border-left: 4px solid var(--primary-color) !important;
        }

        /* Tabs con colores personalizados */
        .nav-tabs .nav-link.active {
            border-bottom: 3px solid var(--primary-color) !important;
            font-weight: 600;
            color: var(--primary-color) !important;
        }

        .nav-tabs .nav-link:hover {
            border-bottom-color: var(--primary-light);
            color: var(--primary-color) !important;
        }

        /* Formularios */
        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-rgb), 0.25);
        }

        /* Botón hamburguesa para móvil */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem;
            margin-right: 1rem;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-backdrop.show {
            display: block;
            opacity: 1;
        }

        /* Estadísticas con colores personalizados */
        .metric-value.text-primary {
            color: var(--primary-color) !important;
        }

        .metric-value.text-success {
            color: var(--secondary-color) !important;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .status-active {
            background: rgba(var(--secondary-rgb), 0.15);
            color: var(--secondary-dark);
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        /* Stock badges */
        .stock-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .stock-normal {
            background: #d1ecf1;
            color: #0c5460;
        }

        .stock-warning {
            background: #fff3cd;
            color: #856404;
        }

        .stock-danger {
            background: #f8d7da;
            color: #721c24;
        }

        /* Categorías */
        .categoria-badge {
            background: rgba(var(--primary-rgb), 0.1);
            color: var(--primary-dark);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
        }

        /* Precios */
        .precio-text {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--secondary-color) !important;
        }

        /* Valor inventario */
        .valor-inventario {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--secondary-color) !important;
        }

        /* Paginación */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding: 1rem 0;
            border-top: 1px solid #dee2e6;
        }

        .pagination-info {
            color: #6c757d;
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 56px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
                transition: transform 0.3s ease-out;
            }

            body.sidebar-open main {
                transform: translateX(280px);
            }

            .pagination-container {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }

        /* Historial */
        .historial-badge-entrada {
            background: #d4edda;
            color: #155724;
        }

        .historial-badge-salida {
            background: #f8d7da;
            color: #721c24;
        }

        .historial-badge-ajuste {
            background: #fff3cd;
            color: #856404;
        }

        /* Variables RGB para opacidad */
        <?php
        // Convertir colores hex a RGB
        function hexToRgb($hex)
        {
            $hex = str_replace('#', '', $hex);
            if (strlen($hex) == 3) {
                $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
                $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
                $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
            } else {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
            }
            return "$r, $g, $b";
        }
        ?> :root {
            --primary-rgb: <?php echo hexToRgb($_SESSION['color_primario']); ?>;
            --secondary-rgb: <?php echo hexToRgb($_SESSION['color_secundario']); ?>;
        }

        /* Ajustes adicionales */
        .modal-header {
            background-color: var(--primary-color);
            color: white;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        /* Botones de acción específicos */
        .btn-group-actions .btn {
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-group-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Producto avatar */
        .producto-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1rem;
        }

        /* Form switches */
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        /* Transferencia info box */
        .transferencia-info {
            background: rgba(var(--primary-rgb), 0.1);
            border-left: 4px solid var(--primary-color);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        /* Loading spinner */
        .spinner-border.text-primary {
            color: var(--primary-color) !important;
        }

        /* Zona de swipe (solo para debug, opcional) */
        #swipeZoneIndicator {
            position: fixed;
            top: 56px;
            left: 0;
            width: 30px;
            height: calc(100vh - 56px);
            background: rgba(39, 174, 96, 0.1);
            z-index: 9999;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <!-- Mostrar logo en base64 -->
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <!-- Mostrar logo por ruta de archivo (fallback) -->
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php else: ?>
                    <!-- Mostrar icono por defecto -->
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="usuarios.php">
                                    <i class="fas fa-user-cog"></i>
                                    Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="caja.php">
                                <i class="fas fa-cash-register"></i>
                                Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="productos.php">
                                <i class="fas fa-boxes"></i>
                                Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="inventario.php">
                                <i class="fas fa-clipboard-list"></i>
                                Inventario
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="clientes.php">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ventas_lista.php">
                                <i class="fas fa-receipt"></i>
                                Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="caja_historial.php">
                                <i class="fas fa-cash-register"></i>
                                Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="gastos.php">
                                <i class="fas fa-money-bill-wave"></i>
                                Gastos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="proveedores.php">
                                <i class="fas fa-truck"></i>
                                Proveedores
                            </a>
                        </li>
                        <!-- MENÚ DE SUCURSALES CONDICIONAL -->
                        <?php if ($empresa_plan !== 'basico'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="sucursales.php">
                                    <i class="fas fa-store"></i>
                                    Sucursales
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles> 0) : ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Facturacion/inicio.php">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    Facturación
                                    <?php if ($timbres_disponibles > 0): ?>
                                        <span class="badge bg-success ms-2" style="font-size: 0.65rem;">
                                            <?php echo $timbres_disponibles; ?> timbres
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning ms-2" style="font-size: 0.65rem;">
                                            Sin timbres
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li>
                         <?php if ($empresa_plan === 'premium'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="../EmidaServicios/inicio.php">
                                <img src="../images/emidalogo.png" alt="" style="width: 20px; height: 20px; margin-right: 10px; object-fit: contain;">
                                Emida Servicios
                                <?php if ($notification_status && isset($notification_status['notification_status']) && !$notification_status['notification_status']['success']): ?>
                                    <span class="badge bg-warning ms-2" style="font-size: 0.65rem;" title="Notificaciones no configuradas">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                         <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="configuracion.php">
                                    <i class="fas fa-cogs"></i>
                                    Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-clipboard-list me-2"></i>Gestión de Inventario
                        <?php if ($sucursal_filtro): ?>
                            <?php
                            $sucursal_nombre = '';
                            foreach ($sucursales as $sucursal) {
                                if ($sucursal['id'] == $sucursal_filtro) {
                                    $sucursal_nombre = $sucursal['nombre'];
                                    break;
                                }
                            }
                            ?>
                            <small class="text-muted">- Sucursal: <?php echo htmlspecialchars($sucursal_nombre); ?></small>
                        <?php endif; ?>
                    </h1>
                    <div class="btn-group-actions">
                        <a href="productos.php" class="btn btn-outline-primary me-2">
                            <i class="fas fa-boxes me-2"></i>Gestionar Productos
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reporteModal">
                            <i class="fas fa-chart-bar me-2"></i>Reportes
                        </button>
                    </div>
                </div>

                <!-- Mensajes -->
                <?php if (isset($_SESSION['mensaje'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['mensaje']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
                <?php endif; ?>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Productos</div>
                                        <div class="metric-value text-primary"><?php echo $total_productos; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-cube fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Bajo Stock</div>
                                        <div class="metric-value text-warning"><?php echo $productos_bajo_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Sin Stock</div>
                                        <div class="metric-value text-danger"><?php echo $productos_sin_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x text-danger opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Valor Inventario</div>
                                        <div class="metric-value text-success">$<?php echo number_format($valor_total_inventario, 2); ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-dollar-sign fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros y Búsqueda -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" id="filtrosForm">
                            <div class="row align-items-center">
                                <div class="col-md-3 mb-3 mb-md-0">
                                    <div class="search-box">
                                        <i class="fas fa-search"></i>
                                        <input type="text" class="form-control" name="search" placeholder="Buscar productos..."
                                            value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" id="searchInput">
                                    </div>
                                </div>
                                <div class="col-md-2 mb-3 mb-md-0">
                                    <select class="form-select" name="categoria_id" id="categoriaFilter">
                                        <option value="">Todas las categorías</option>
                                        <?php foreach ($categorias as $categoria): ?>
                                            <option value="<?php echo $categoria['id']; ?>"
                                                <?php echo (isset($_GET['categoria_id']) && $_GET['categoria_id'] == $categoria['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3 mb-md-0">
                                    <select class="form-select" name="sucursal_id" id="sucursalFilter">
                                        <option value="">Todas las sucursales</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>"
                                                <?php echo ($sucursal_filtro == $sucursal['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3 mb-md-0">
                                    <select class="form-select" name="stock_filter" id="stockFilter">
                                        <option value="">Todo el stock</option>
                                        <option value="bajo" <?php echo (isset($_GET['stock_filter']) && $_GET['stock_filter'] == 'bajo') ? 'selected' : ''; ?>>Bajo stock</option>
                                        <option value="sin" <?php echo (isset($_GET['stock_filter']) && $_GET['stock_filter'] == 'sin') ? 'selected' : ''; ?>>Sin stock</option>
                                        <option value="normal" <?php echo (isset($_GET['stock_filter']) && $_GET['stock_filter'] == 'normal') ? 'selected' : ''; ?>>Stock normal</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_inactive" id="showInactive"
                                            <?php echo (isset($_GET['show_inactive'])) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="showInactive">Mostrar inactivos</label>
                                    </div>
                                </div>
                                <div class="col-md-1">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Vista de Tabla (Desktop) -->
                <div class="d-none d-lg-block">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Inventario de Productos
                                <?php if ($sucursal_filtro): ?>
                                    <small class="text-muted">- Stock por sucursal</small>
                                <?php else: ?>
                                    <small class="text-muted">- Stock total</small>
                                <?php endif; ?>
                            </h5>
                            <div class="d-flex align-items-center">
                                <small class="result-count me-3">
                                    Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                                </small>
                                <?php if ($total_paginas > 1): ?>
                                    <span class="badge bg-secondary">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="inventarioTable">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th>Categoría</th>
                                            <th>
                                                Stock
                                                <?php if ($sucursal_filtro): ?>
                                                    <small class="text-muted d-block">(Sucursal)</small>
                                                <?php else: ?>
                                                    <small class="text-muted d-block">(Total)</small>
                                                <?php endif; ?>
                                            </th>
                                            <th>Stock Mínimo</th>
                                            <th>Precio</th>
                                            <th>Valor en Stock</th>
                                            <th>Estado</th>
                                            <th>Última Actualización</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($productos)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    <i class="fas fa-cubes fa-3x mb-3"></i>
                                                    <p>No se encontraron productos en el inventario</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($productos as $producto):
                                                $valor_stock = $producto['precio'] * $producto['stock_mostrar'];
                                                $clase_stock = '';
                                                if ($producto['stock_mostrar'] == 0) {
                                                    $clase_stock = 'stock-danger';
                                                } elseif ($producto['stock_mostrar'] <= $producto['stock_minimo_mostrar']) {
                                                    $clase_stock = 'stock-warning';
                                                } else {
                                                    $clase_stock = 'stock-normal';
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="producto-avatar me-3">
                                                                <?php echo strtoupper(substr($producto['nombre'], 0, 2)); ?>
                                                            </div>
                                                            <div>
                                                                <div class="fw-bold"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                                                <?php if (!empty($producto['descripcion'])): ?>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($producto['descripcion']); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($producto['categoria_nombre']): ?>
                                                            <span class="categoria-badge"><?php echo htmlspecialchars($producto['categoria_nombre']); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Sin categoría</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="stock-badge <?php echo $clase_stock; ?>">
                                                            <?php echo number_format($producto['stock_mostrar']); ?>
                                                        </span>
                                                        <?php if ($sucursal_filtro): ?>
                                                            <small class="text-muted d-block">
                                                                Total: <?php echo number_format($producto['stock_total']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="text-muted"><?php echo number_format($producto['stock_minimo_mostrar']); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="precio-text">$<?php echo number_format($producto['precio'], 2); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="valor-inventario">$<?php echo number_format($valor_stock, 2); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $producto['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                            <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($producto['fecha_actualizacion'])); ?></td>
                                                    <td class="table-actions">
                                                        <button class="btn btn-sm btn-outline-primary me-1 ajustar-stock"
                                                            data-producto-id="<?php echo $producto['id']; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                            data-stock-total="<?php echo $producto['stock_mostrar']; ?>"
                                                            data-stock-minimo="<?php echo $producto['stock_minimo_mostrar']; ?>"
                                                            title="Ajustar Stock">
                                                            <i class="fas fa-edit"></i>
                                                        </button>

                                                        <!-- Botón para ver historial -->
                                                        <button class="btn btn-sm btn-outline-info me-1 ver-historial"
                                                            data-producto-id="<?php echo $producto['id']; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                            title="Ver Historial">
                                                            <i class="fas fa-history"></i>
                                                        </button>

                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id" value="<?php echo $producto['id']; ?>">
                                                            <input type="hidden" name="activo" value="<?php echo $producto['activo'] ? '0' : '1'; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-<?php echo $producto['activo'] ? 'danger' : 'success'; ?>"
                                                                title="<?php echo $producto['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                                                <i class="fas fa-<?php echo $producto['activo'] ? 'ban' : 'check'; ?>"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_paginas > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination-info">
                                        Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                                    </div>
                                    <nav>
                                        <ul class="pagination mb-0">
                                            <!-- Primera página -->
                                            <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" title="Primera página">
                                                    <i class="fas fa-angle-double-left"></i>
                                                </a>
                                            </li>

                                            <!-- Página anterior -->
                                            <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>" title="Página anterior">
                                                    <i class="fas fa-angle-left"></i>
                                                </a>
                                            </li>

                                            <!-- Números de página -->
                                            <?php
                                            $inicio = max(1, $pagina_actual - 2);
                                            $fin = min($total_paginas, $pagina_actual + 2);

                                            for ($i = $inicio; $i <= $fin; $i++):
                                            ?>
                                                <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <!-- Página siguiente -->
                                            <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>" title="Página siguiente">
                                                    <i class="fas fa-angle-right"></i>
                                                </a>
                                            </li>

                                            <!-- Última página -->
                                            <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>" title="Última página">
                                                    <i class="fas fa-angle-double-right"></i>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Vista de Tarjetas (Móvil) -->
                <div class="d-lg-none">
                    <div class="row" id="mobileInventario">
                        <?php if (empty($productos)): ?>
                            <div class="col-12">
                                <div class="card text-center py-5">
                                    <i class="fas fa-cubes fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No se encontraron productos en el inventario</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($productos as $producto):
                                $valor_stock = $producto['precio'] * $producto['stock_mostrar'];
                                $clase_stock = '';
                                if ($producto['stock_mostrar'] == 0) {
                                    $clase_stock = 'stock-danger';
                                } elseif ($producto['stock_mostrar'] <= $producto['stock_minimo_mostrar']) {
                                    $clase_stock = 'stock-warning';
                                } else {
                                    $clase_stock = 'stock-normal';
                                }
                            ?>
                                <div class="col-12 mb-3">
                                    <div class="card mobile-producto-card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="producto-avatar me-3">
                                                        <?php echo strtoupper(substr($producto['nombre'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($producto['nombre']); ?></h6>
                                                        <?php if (!empty($producto['descripcion'])): ?>
                                                            <small class="text-muted"><?php echo htmlspecialchars($producto['descripcion']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="status-badge <?php echo $producto['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                </span>
                                            </div>

                                            <div class="row text-center mb-2">
                                                <div class="col-4">
                                                    <small class="text-muted d-block">
                                                        <?php if ($sucursal_filtro): ?>
                                                            Stock (Sucursal)
                                                        <?php else: ?>
                                                            Stock (Total)
                                                        <?php endif; ?>
                                                    </small>
                                                    <span class="stock-badge <?php echo $clase_stock; ?>">
                                                        <?php echo number_format($producto['stock_mostrar']); ?>
                                                    </span>
                                                    <?php if ($sucursal_filtro): ?>
                                                        <small class="text-muted d-block">
                                                            Total: <?php echo number_format($producto['stock_total']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-4">
                                                    <small class="text-muted d-block">Mínimo</small>
                                                    <span class="text-muted"><?php echo number_format($producto['stock_minimo_mostrar']); ?></span>
                                                </div>
                                                <div class="col-4">
                                                    <small class="text-muted d-block">Precio</small>
                                                    <span class="precio-text">$<?php echo number_format($producto['precio'], 2); ?></span>
                                                </div>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <?php if ($producto['categoria_nombre']): ?>
                                                    <span class="categoria-badge"><?php echo htmlspecialchars($producto['categoria_nombre']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin categoría</span>
                                                <?php endif; ?>
                                                <span class="valor-inventario">$<?php echo number_format($valor_stock, 2); ?></span>
                                            </div>

                                            <div class="stock-info mb-2">
                                                <small>Actualizado: <?php echo date('d/m/Y H:i', strtotime($producto['fecha_actualizacion'])); ?></small>
                                            </div>

                                            <div class="mobile-actions">
                                                <button class="btn btn-outline-primary ajustar-stock"
                                                    data-producto-id="<?php echo $producto['id']; ?>"
                                                    data-producto-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                    data-stock-total="<?php echo $producto['stock_mostrar']; ?>"
                                                    data-stock-minimo="<?php echo $producto['stock_minimo_mostrar']; ?>">
                                                    <i class="fas fa-edit me-1"></i>Ajustar
                                                </button>

                                                <button class="btn btn-outline-info ver-historial"
                                                    data-producto-id="<?php echo $producto['id']; ?>"
                                                    data-producto-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>">
                                                    <i class="fas fa-history me-1"></i>Historial
                                                </button>

                                                <form method="POST" class="d-inline-flex flex-grow-1">
                                                    <input type="hidden" name="accion" value="cambiar_estado">
                                                    <input type="hidden" name="id" value="<?php echo $producto['id']; ?>">
                                                    <input type="hidden" name="activo" value="<?php echo $producto['activo'] ? '0' : '1'; ?>">
                                                    <button type="submit" class="btn btn-outline-<?php echo $producto['activo'] ? 'danger' : 'success'; ?> w-100">
                                                        <i class="fas fa-<?php echo $producto['activo'] ? 'ban' : 'check'; ?> me-1"></i>
                                                        <?php echo $producto['activo'] ? 'Desactivar' : 'Activar'; ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Paginación para móvil -->
                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-info">
                                Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
                            </div>
                            <nav>
                                <ul class="pagination mb-0">
                                    <!-- Página anterior -->
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>" title="Página anterior">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>

                                    <!-- Página siguiente -->
                                    <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>" title="Página siguiente">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para Ajustar Stock -->
    <div class="modal fade" id="ajustarStockModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white">Ajustar Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="ajustarStockForm">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="ajustar_stock" id="accionHidden">
                        <input type="hidden" name="producto_id" id="producto_id">

                        <!-- Pestañas para diferentes tipos de ajuste -->
                        <ul class="nav nav-tabs mb-3" id="ajusteTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="ajuste-simple-tab" data-bs-toggle="tab"
                                    data-bs-target="#ajuste-simple" type="button" role="tab">
                                    Ajuste Simple
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="transferencia-tab" data-bs-toggle="tab"
                                    data-bs-target="#transferencia" type="button" role="tab">
                                    Transferir entre Sucursales
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="ajusteTabsContent">
                            <!-- Pestaña de Ajuste Simple -->
                            <div class="tab-pane fade show active" id="ajuste-simple" role="tabpanel">
                                <div class="mb-3">
                                    <label class="form-label">Producto</label>
                                    <input type="text" class="form-control" id="producto_nombre" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Sucursal <span class="text-danger">*</span></label>
                                    <select class="form-select" name="sucursal_id" id="sucursal_id_select">
                                        <option value="">Seleccionar sucursal</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="stock-info-display mb-3">
                                    <div class="info-item">
                                        <span class="info-label">Stock Actual:</span>
                                        <span class="info-value" id="stock_actual_display">-</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Stock Mínimo:</span>
                                        <span class="info-value" id="stock_minimo_display">-</span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Tipo de Ajuste <span class="text-danger">*</span></label>
                                    <select class="form-select" name="tipo_ajuste" id="tipo_ajuste">
                                        <option value="entrada">Entrada de Mercancía</option>
                                        <option value="salida">Salida de Mercancía</option>
                                        <option value="ajuste">Ajuste Manual</option>
                                        <option value="daño">Pérdida por Daño</option>
                                        <option value="vencimiento">Pérdida por Vencimiento</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Nuevo Stock <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="nuevo_stock" id="nuevo_stock" min="0">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Motivo del Ajuste <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="motivo" id="motivo" rows="2"></textarea>
                                </div>
                            </div>

                            <!-- Pestaña de Transferencia entre Sucursales -->
                            <div class="tab-pane fade" id="transferencia" role="tabpanel">
                                <input type="hidden" name="tipo_operacion" id="tipo_operacion" value="ajuste">

                                <div class="mb-3">
                                    <label class="form-label">Producto</label>
                                    <input type="text" class="form-control" id="producto_nombre_transferencia" readonly>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Sucursal Origen <span class="text-danger">*</span></label>
                                        <select class="form-select" name="sucursal_origen" id="sucursal_origen">
                                            <option value="">Seleccionar sucursal origen</option>
                                            <?php foreach ($sucursales as $sucursal): ?>
                                                <option value="<?php echo $sucursal['id']; ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="mt-1">
                                            <small class="text-muted stock-info" id="stock_origen_info">Stock disponible: 0</small>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Sucursal Destino <span class="text-danger">*</span></label>
                                        <select class="form-select" name="sucursal_destino" id="sucursal_destino">
                                            <option value="">Seleccionar sucursal destino</option>
                                            <?php foreach ($sucursales as $sucursal): ?>
                                                <option value="<?php echo $sucursal['id']; ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="mt-1">
                                            <small class="text-muted stock-info" id="stock_destino_info">Stock actual: 0</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Cantidad a Transferir <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="cantidad_transferencia" id="cantidad_transferencia"
                                        min="1" placeholder="Ingrese la cantidad">
                                    <div class="form-text" id="transferencia_feedback"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Motivo de la Transferencia <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="motivo_transferencia" id="motivo_transferencia"
                                        rows="2" placeholder="Ej: Reabastecimiento, Corrección de inventario, etc."></textarea>
                                </div>

                                <div class="alert alert-info">
                                    <small>
                                        <i class="fas fa-info-circle me-1"></i>
                                        <strong>Nota:</strong> Esta operación reducirá el stock en la sucursal origen y aumentará en la sucursal destino.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success" id="submitBtn">Aplicar Ajuste</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Historial de Movimientos -->
    <div class="modal fade" id="historialModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white" id="historialModalTitle">Historial de Movimientos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <strong>Producto:</strong> <span id="historialProductoNombre"></span>
                        </div>
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="exportarHistorial">
                                <i class="fas fa-download me-1"></i>Exportar
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="refrescarHistorial">
                                <i class="fas fa-sync-alt me-1"></i>Actualizar
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover" id="tablaHistorial">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Sucursal</th>
                                    <th>Cantidad</th>
                                    <th>Stock Anterior</th>
                                    <th>Stock Nuevo</th>
                                    <th>Usuario</th>
                                    <th>Observaciones</th>
                                </tr>
                            </thead>
                            <tbody id="historialBody">
                                <!-- Los datos se cargarán via AJAX -->
                            </tbody>
                        </table>
                    </div>

                    <div class="text-center mt-3">
                        <div class="spinner-border spinner-border-sm" role="status" id="loadingHistorial" style="display: none;">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Reportes -->
    <div class="modal fade" id="reporteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white">Reportes de Inventario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Inventario de Productos -->
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-boxes fa-3x text-success mb-3"></i>
                                    <h5>Inventario de Productos</h5>
                                    <p class="text-muted">Lista completa de productos en inventario</p>

                                    <!-- Formulario para reporte de inventario completo -->
                                    <form action="reporte_inventario_completo.php" method="GET" target="_blank" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Categoría:</label>
                                            <select class="form-select" name="categoria_id">
                                                <option value="">Todas las categorías</option>
                                                <?php foreach ($categorias as $categoria): ?>
                                                    <option value="<?php echo $categoria['id']; ?>">
                                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Filtrar por Stock:</label>
                                            <select class="form-select" name="stock_filter">
                                                <option value="">Todos</option>
                                                <option value="bajo">Bajo Stock</option>
                                                <option value="sin">Sin Stock</option>
                                                <option value="normal">Stock Normal</option>
                                            </select>
                                        </div>

                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Productos Bajo Stock -->
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                                    <h5>Productos Bajo Stock</h5>
                                    <p class="text-muted">Lista de productos que requieren reabastecimiento</p>

                                    <!-- Formulario para reporte de bajo stock -->
                                    <form action="reporte_inventario_bajo_stock.php" method="GET" target="_blank" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <!-- Movimientos de Inventario -->
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-chart-line fa-3x text-primary mb-3"></i>
                                    <h5>Movimientos de Inventario</h5>
                                    <p class="text-muted">Historial de entradas y salidas</p>

                                    <!-- Formulario para reporte de movimientos -->
                                    <form action="reporte_movimientos_inventario.php" method="GET" target="_blank" class="mt-3">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Fecha Inicio:</label>
                                                <input type="date" class="form-control" name="fecha_inicio"
                                                    value="<?php echo date('Y-m-01'); ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Fecha Fin:</label>
                                                <input type="date" class="form-control" name="fecha_fin"
                                                    value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Producto (opcional):</label>
                                            <select class="form-select" name="producto_id">
                                                <option value="">Todos los productos</option>
                                                <?php foreach ($productos as $producto): ?>
                                                    <option value="<?php echo $producto['id']; ?>">
                                                        <?php echo htmlspecialchars($producto['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Estadísticas Rápidas -->
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-chart-pie fa-3x text-info mb-3"></i>
                                    <h5>Estadísticas Rápidas</h5>
                                    <p class="text-muted">Resumen del estado del inventario</p>

                                    <div class="mt-3">
                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="metric-value text-primary"><?php echo $total_productos; ?></div>
                                                <small class="text-muted">Total Productos</small>
                                            </div>
                                            <div class="col-6">
                                                <div class="metric-value text-warning"><?php echo $productos_bajo_stock; ?></div>
                                                <small class="text-muted">Bajo Stock</small>
                                            </div>
                                        </div>

                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="metric-value text-danger"><?php echo $productos_sin_stock; ?></div>
                                                <small class="text-muted">Sin Stock</small>
                                            </div>
                                            <div class="col-6">
                                                <div class="metric-value text-success">$<?php echo number_format($valor_total_inventario, 2); ?></div>
                                                <small class="text-muted">Valor Inventario</small>
                                            </div>
                                        </div>

                                        <div class="alert alert-info">
                                            <small>
                                                <i class="fas fa-info-circle me-1"></i>
                                                Estos datos reflejan el estado actual del inventario
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        $(document).ready(function() {
            // =============================================
            // CONTROL DEL SIDEBAR (Responsive)
            // =============================================

            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            // Función para abrir el sidebar
            function openSidebar() {
                if (sidebar && sidebarBackdrop) {
                    sidebar.classList.add('show');
                    sidebarBackdrop.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }

            // Función para cerrar el sidebar
            function closeSidebar() {
                if (sidebar && sidebarBackdrop) {
                    sidebar.classList.remove('show');
                    sidebarBackdrop.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }

            // Función para alternar sidebar
            function toggleSidebar() {
                if (sidebar && sidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }

            // =============================================
            // CONTROL POR BOTÓN HAMBURGUESA
            // =============================================

            // Asignar eventos del botón hamburguesa
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    toggleSidebar();
                });
            }

            // Cerrar sidebar al hacer clic en el backdrop
            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', function(e) {
                    e.preventDefault();
                    closeSidebar();
                });
            }

            // Cerrar sidebar al hacer clic en un enlace (solo en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebar();
                    }
                });
            });

            // =============================================
            // CONTROL POR SWIPE (Solo móvil)
            // =============================================

            let touchStartX = 0;
            let touchStartY = 0;
            let isSwiping = false;
            const SWIPE_THRESHOLD = 50; // Mínimo de píxeles para considerar swipe
            const EDGE_ZONE = 30; // Zona del borde donde inicia el swipe
            const VERTICAL_TOLERANCE = 50; // Tolerancia vertical máxima

            // Detectar inicio del touch
            document.addEventListener('touchstart', function(e) {
                // Solo en móvil
                if (window.innerWidth >= 768) return;

                // Evitar swipe en elementos interactivos
                const target = e.target;
                if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' ||
                    target.tagName === 'BUTTON' || target.tagName === 'SELECT' ||
                    target.closest('.modal') || target.closest('.btn-group')) {
                    return;
                }

                // Obtener coordenadas del touch
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                isSwiping = false;
            });

            // Detectar movimiento del touch
            document.addEventListener('touchmove', function(e) {
                // Solo en móvil
                if (window.innerWidth >= 768) return;

                const touchX = e.touches[0].clientX;
                const touchY = e.touches[0].clientY;

                const deltaX = touchX - touchStartX;
                const deltaY = touchY - touchStartY;

                // Determinar si es un swipe horizontal
                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                    isSwiping = true;
                    e.preventDefault(); // Prevenir scroll vertical durante swipe
                }
            }, {
                passive: false
            });

            // Detectar fin del touch
            document.addEventListener('touchend', function(e) {
                // Solo en móvil
                if (window.innerWidth >= 768) return;

                if (!isSwiping) return;

                const touchEndX = e.changedTouches[0].clientX;
                const touchEndY = e.changedTouches[0].clientY;

                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                // Verificar que sea un swipe principalmente horizontal
                if (Math.abs(deltaY) > VERTICAL_TOLERANCE) return;

                // Obtener estado actual del sidebar
                const isSidebarOpen = sidebar && sidebar.classList.contains('show');

                // SWIPE DE IZQUIERDA A DERECHA (abrir)
                if (deltaX > SWIPE_THRESHOLD && !isSidebarOpen) {
                    // Solo abrir si el swipe empezó cerca del borde izquierdo
                    if (touchStartX <= EDGE_ZONE) {
                        openSidebar();
                        e.preventDefault();
                    }
                }
                // SWIPE DE DERECHA A IZQUIERDA (cerrar)
                else if (deltaX < -SWIPE_THRESHOLD && isSidebarOpen) {
                    // Cerrar desde cualquier posición
                    closeSidebar();
                    e.preventDefault();
                }
            });

            // =============================================
            // CONTROLES ADICIONALES
            // =============================================

            // Cerrar sidebar con tecla ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeSidebar();
                }
            });

            // Cerrar sidebar automáticamente en pantallas grandes
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebar();
                }
            });

            // Cerrar sidebar al hacer clic fuera (solo en móvil)
            document.addEventListener('click', function(e) {
                if (window.innerWidth >= 768) return;

                const target = e.target;
                const isClickInsideSidebar = sidebar && sidebar.contains(target);
                const isClickOnToggle = sidebarToggle && sidebarToggle.contains(target);

                if (sidebar && sidebar.classList.contains('show') &&
                    !isClickInsideSidebar && !isClickOnToggle) {
                    closeSidebar();
                }
            });

            // =============================================
            // FUNCIONALIDAD DEL INVENTARIO
            // =============================================

            // Auto-submit del formulario de filtros
            $('#sucursalFilter, #categoriaFilter, #stockFilter, #showInactive').on('change', function() {
                $('#filtrosForm').submit();
            });

            // Ajustar stock - manejo de sucursales
            $(document).on('click', '.ajustar-stock', function() {
                const productoId = $(this).data('producto-id');
                const productoNombre = $(this).data('producto-nombre');
                const stockTotal = $(this).data('stock-total');
                const stockMinimo = $(this).data('stock-minimo');

                // Actualizar campos del modal
                $('#producto_id').val(productoId);
                $('#producto_nombre').val(productoNombre);
                $('#producto_nombre_transferencia').val(productoNombre);

                // Limpiar campos
                $('#sucursal_id_select').val('');
                $('#stock_actual_display').text('-');
                $('#stock_minimo_display').text('-');
                $('#nuevo_stock').val('');
                $('#motivo').val('');
                $('#sucursal_origen').val('');
                $('#sucursal_destino').val('');
                $('#cantidad_transferencia').val('');
                $('#motivo_transferencia').val('');
                $('#stock_origen_info').text('Stock disponible: 0');
                $('#stock_destino_info').text('Stock actual: 0');
                $('#transferencia_feedback').text('');

                // Resetear a pestaña de ajuste simple
                $('#ajuste-simple-tab').tab('show');
                $('#accionHidden').val('ajustar_stock');
                $('#submitBtn').text('Aplicar Ajuste');

                // Mostrar modal
                const modal = new bootstrap.Modal(document.getElementById('ajustarStockModal'));
                modal.show();
            });

            // Cuando se selecciona una sucursal en ajuste simple
            $('#sucursal_id_select').on('change', function() {
                const sucursalId = $(this).val();
                const productoId = $('#producto_id').val();

                if (sucursalId && productoId) {
                    $('#stock_actual_display').text('Cargando...');
                    $('#stock_minimo_display').text('Cargando...');

                    $.ajax({
                        url: 'obtener_stock_sucursal.php',
                        type: 'POST',
                        data: {
                            producto_id: productoId,
                            sucursal_id: sucursalId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#stock_actual_display').text(response.stock);
                                $('#stock_minimo_display').text(response.stock_minimo);
                                $('#nuevo_stock').val(response.stock);
                            } else {
                                $('#stock_actual_display').text('0');
                                $('#stock_minimo_display').text('0');
                                $('#nuevo_stock').val('0');
                            }
                        },
                        error: function() {
                            $('#stock_actual_display').text('Error');
                            $('#stock_minimo_display').text('Error');
                            $('#nuevo_stock').val('0');
                        }
                    });
                } else {
                    $('#stock_actual_display').text('-');
                    $('#stock_minimo_display').text('-');
                    $('#nuevo_stock').val('');
                }
            });

            // Cuando se cambia de pestaña
            $('#ajusteTabs button').on('click', function() {
                const target = $(this).data('bs-target');

                if (target === '#transferencia') {
                    $('#accionHidden').val('transferir_stock');
                    $('#submitBtn').text('Realizar Transferencia');
                } else {
                    $('#accionHidden').val('ajustar_stock');
                    $('#submitBtn').text('Aplicar Ajuste');
                }
            });

            // Cuando se selecciona sucursal origen en transferencia
            $('#sucursal_origen').on('change', function() {
                const sucursalId = $(this).val();
                const productoId = $('#producto_id').val();

                if (sucursalId && productoId) {
                    $('#stock_origen_info').text('Cargando...');
                    $.ajax({
                        url: 'obtener_stock_sucursal.php',
                        type: 'POST',
                        data: {
                            producto_id: productoId,
                            sucursal_id: sucursalId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#stock_origen_info').text('Stock disponible: ' + response.stock);
                                validarTransferencia();
                            } else {
                                $('#stock_origen_info').text('Stock disponible: 0');
                            }
                        },
                        error: function() {
                            $('#stock_origen_info').text('Stock disponible: 0');
                        }
                    });
                } else {
                    $('#stock_origen_info').text('Stock disponible: 0');
                }
            });

            // Cuando se selecciona sucursal destino en transferencia
            $('#sucursal_destino').on('change', function() {
                const sucursalId = $(this).val();
                const productoId = $('#producto_id').val();

                if (sucursalId && productoId) {
                    $('#stock_destino_info').text('Cargando...');
                    $.ajax({
                        url: 'obtener_stock_sucursal.php',
                        type: 'POST',
                        data: {
                            producto_id: productoId,
                            sucursal_id: sucursalId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#stock_destino_info').text('Stock actual: ' + response.stock);
                            } else {
                                $('#stock_destino_info').text('Stock actual: 0');
                            }
                        },
                        error: function() {
                            $('#stock_destino_info').text('Stock actual: 0');
                        }
                    });
                } else {
                    $('#stock_destino_info').text('Stock actual: 0');
                }
            });

            // Validar cantidad de transferencia en tiempo real
            $('#cantidad_transferencia').on('input', validarTransferencia);

            function validarTransferencia() {
                const sucursalOrigen = $('#sucursal_origen').val();
                const sucursalDestino = $('#sucursal_destino').val();
                const cantidad = $('#cantidad_transferencia').val();
                const stockOrigenText = $('#stock_origen_info').text();
                const stockOrigen = parseInt(stockOrigenText.replace('Stock disponible: ', '')) || 0;

                let feedback = '';

                if (!sucursalOrigen) {
                    feedback = 'Seleccione una sucursal origen';
                } else if (!sucursalDestino) {
                    feedback = 'Seleccione una sucursal destino';
                } else if (sucursalOrigen === sucursalDestino) {
                    feedback = 'Las sucursales origen y destino no pueden ser la misma';
                } else if (cantidad && cantidad > 0) {
                    if (cantidad > stockOrigen) {
                        feedback = 'Cantidad excede el stock disponible en la sucursal origen';
                    } else {
                        feedback = 'Transferencia válida';
                    }
                }

                $('#transferencia_feedback').text(feedback);

                if (feedback.includes('excede') || feedback.includes('misma')) {
                    $('#transferencia_feedback').removeClass('text-success').addClass('text-danger');
                } else if (feedback === 'Transferencia válida') {
                    $('#transferencia_feedback').removeClass('text-danger').addClass('text-success');
                } else {
                    $('#transferencia_feedback').removeClass('text-success text-danger');
                }
            }

            // Ver historial de movimientos
            $(document).on('click', '.ver-historial', function() {
                const productoId = $(this).data('producto-id');
                const productoNombre = $(this).data('producto-nombre');

                $('#historialProductoNombre').text(productoNombre);
                $('#historialModalTitle').text('Historial de Movimientos - ' + productoNombre);

                const modal = new bootstrap.Modal(document.getElementById('historialModal'));
                modal.show();

                cargarHistorial(productoId);
            });

            function cargarHistorial(productoId) {
                $('#loadingHistorial').show();
                $('#historialBody').html('');

                $.ajax({
                    url: 'obtener_historial.php',
                    type: 'GET',
                    data: {
                        producto_id: productoId
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingHistorial').hide();

                        if (response.success && response.data.length > 0) {
                            let html = '';
                            response.data.forEach(function(movimiento) {
                                let badgeClass = '';
                                let tipoText = '';

                                switch (movimiento.tipo) {
                                    case 'entrada':
                                        badgeClass = 'success';
                                        tipoText = 'Entrada';
                                        break;
                                    case 'salida':
                                        badgeClass = 'danger';
                                        tipoText = 'Salida';
                                        break;
                                    case 'ajuste':
                                        badgeClass = 'warning';
                                        tipoText = 'Ajuste';
                                        break;
                                    default:
                                        badgeClass = 'secondary';
                                        tipoText = movimiento.tipo;
                                }

                                html += `
                            <tr>
                                <td>${movimiento.fecha_formateada}</td>
                                <td><span class="badge bg-${badgeClass}">${tipoText}</span></td>
                                <td>${movimiento.sucursal_nombre || 'N/A'}</td>
                                <td class="${movimiento.tipo === 'entrada' ? 'text-success' : 'text-danger'} fw-bold">
                                    ${movimiento.tipo === 'entrada' ? '+' : '-'}${movimiento.cantidad}
                                </td>
                                <td>${movimiento.cantidad_anterior}</td>
                                <td class="fw-bold">${movimiento.cantidad_nueva}</td>
                                <td>${movimiento.usuario_nombre || 'N/A'}</td>
                                <td><small class="text-muted">${movimiento.observaciones || 'Sin observaciones'}</small></td>
                            </tr>
                        `;
                            });

                            $('#historialBody').html(html);
                        } else {
                            $('#historialBody').html(`
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="fas fa-history fa-2x mb-3"></i>
                                <p>No se encontraron movimientos para este producto</p>
                            </td>
                        </tr>
                    `);
                        }
                    },
                    error: function() {
                        $('#loadingHistorial').hide();
                        $('#historialBody').html(`
                    <tr>
                        <td colspan="8" class="text-center text-danger py-4">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                            <p>Error al cargar el historial</p>
                        </td>
                    </tr>
                `);
                    }
                });
            }

            // Refrescar historial
            $('#refrescarHistorial').on('click', function() {
                const productoId = $('.ver-historial.active').data('producto-id');
                if (productoId) {
                    cargarHistorial(productoId);
                }
            });

            // Exportar historial
            $('#exportarHistorial').on('click', function() {
                exportarHistorialCSV();
            });

            // Función para exportar historial a CSV
            function exportarHistorialCSV() {
                const productoNombre = $('#historialProductoNombre').text();
                const fecha = new Date().toLocaleDateString('es-MX');

                // Crear datos CSV
                let csv = 'Historial de Movimientos - ' + productoNombre + '\n';
                csv += 'Fecha de exportación: ' + fecha + '\n\n';
                csv += 'Fecha,Tipo,Sucursal,Cantidad,Stock Anterior,Stock Nuevo,Usuario,Observaciones\n';

                // Obtener datos de la tabla
                $('#tablaHistorial tbody tr').each(function() {
                    const cells = $(this).find('td');
                    const row = [
                        $(cells[0]).text().trim(),
                        $(cells[1]).find('.badge').text().trim(),
                        $(cells[2]).text().trim(),
                        $(cells[3]).text().trim(),
                        $(cells[4]).text().trim(),
                        $(cells[5]).text().trim(),
                        $(cells[6]).text().trim(),
                        '"' + $(cells[7]).text().trim().replace(/"/g, '""') + '"'
                    ];
                    csv += row.join(',') + '\n';
                });

                // Crear y descargar archivo
                const blob = new Blob([csv], {
                    type: 'text/csv;charset=utf-8;'
                });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);

                link.setAttribute('href', url);
                link.setAttribute('download', `historial_${productoNombre.replace(/\s+/g, '_')}_${fecha.replace(/\//g, '-')}.csv`);
                link.style.visibility = 'hidden';

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                // Mostrar mensaje
                alert('Historial exportado exitosamente como CSV');
            }

            // Sugerencias automáticas para el motivo del ajuste
            $('#tipo_ajuste').on('change', function() {
                const tipo = $(this).val();
                let sugerencia = '';

                switch (tipo) {
                    case 'entrada':
                        sugerencia = 'Compra de mercancía - Factura #';
                        break;
                    case 'salida':
                        sugerencia = 'Venta - Ticket #';
                        break;
                    case 'ajuste':
                        sugerencia = 'Ajuste de inventario - Corrección de stock';
                        break;
                    case 'daño':
                        sugerencia = 'Pérdida por producto dañado';
                        break;
                    case 'vencimiento':
                        sugerencia = 'Pérdida por producto vencido';
                        break;
                }

                $('#motivo').attr('placeholder', sugerencia);
            });

            // Búsqueda en tiempo real
            $('#searchInput').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();

                const hasFilters = $('#sucursalFilter').val() || $('#categoriaFilter').val() ||
                    $('#stockFilter').val() || $('#showInactive').is(':checked');

                if (!hasFilters) {
                    // Buscar en tabla desktop
                    $('#inventarioTable tbody tr').each(function() {
                        const text = $(this).text().toLowerCase();
                        $(this).toggle(text.includes(searchTerm));
                    });

                    // Buscar en tarjetas móviles
                    $('#mobileInventario .col-12').each(function() {
                        const text = $(this).text().toLowerCase();
                        $(this).toggle(text.includes(searchTerm));
                    });
                }
            });

            // =============================================
            // FUNCIONES ADICIONALES
            // =============================================

            // Validar formulario de ajuste de stock
            $('#ajustarStockForm').on('submit', function(e) {
                const accion = $('#accionHidden').val();
                const tabActive = $('#ajusteTabs .nav-link.active').attr('id');

                if (accion === 'ajustar_stock') {
                    const sucursalId = $('#sucursal_id_select').val();
                    const nuevoStock = $('#nuevo_stock').val();
                    const motivo = $('#motivo').val();

                    if (!sucursalId) {
                        e.preventDefault();
                        alert('Por favor seleccione una sucursal');
                        $('#sucursal_id_select').focus();
                        return false;
                    }

                    if (nuevoStock === '' || parseInt(nuevoStock) < 0) {
                        e.preventDefault();
                        alert('Por favor ingrese un stock válido (mayor o igual a 0)');
                        $('#nuevo_stock').focus();
                        return false;
                    }

                    if (!motivo.trim()) {
                        e.preventDefault();
                        alert('Por favor ingrese un motivo para el ajuste');
                        $('#motivo').focus();
                        return false;
                    }
                } else if (accion === 'transferir_stock') {
                    const sucursalOrigen = $('#sucursal_origen').val();
                    const sucursalDestino = $('#sucursal_destino').val();
                    const cantidad = $('#cantidad_transferencia').val();
                    const motivo = $('#motivo_transferencia').val();

                    if (!sucursalOrigen) {
                        e.preventDefault();
                        alert('Por favor seleccione la sucursal origen');
                        $('#sucursal_origen').focus();
                        return false;
                    }

                    if (!sucursalDestino) {
                        e.preventDefault();
                        alert('Por favor seleccione la sucursal destino');
                        $('#sucursal_destino').focus();
                        return false;
                    }

                    if (sucursalOrigen === sucursalDestino) {
                        e.preventDefault();
                        alert('La sucursal origen y destino no pueden ser la misma');
                        return false;
                    }

                    if (!cantidad || parseInt(cantidad) <= 0) {
                        e.preventDefault();
                        alert('Por favor ingrese una cantidad válida (mayor a 0)');
                        $('#cantidad_transferencia').focus();
                        return false;
                    }

                    if (!motivo.trim()) {
                        e.preventDefault();
                        alert('Por favor ingrese un motivo para la transferencia');
                        $('#motivo_transferencia').focus();
                        return false;
                    }
                }

                return true;
            });

            // Agregar clase active al botón de historial clickeado
            $(document).on('click', '.ver-historial', function() {
                $('.ver-historial').removeClass('active');
                $(this).addClass('active');
            });

            // Limpiar filtros
            $('#limpiarFiltros').on('click', function() {
                $('#searchInput').val('');
                $('#categoriaFilter').val('');
                $('#sucursalFilter').val('');
                $('#stockFilter').val('');
                $('#showInactive').prop('checked', false);
                $('#filtrosForm').submit();
            });

            // Generar reportes
            $('#generarReporteBajoStock').on('click', function() {
                generarReporteBajoStock();
            });

            $('#generarReporteMovimientos').on('click', function() {
                generarReporteMovimientos();
            });

            function generarReporteBajoStock() {
                // TODO: Implementar generación de reporte de bajo stock
                alert('Generando reporte de productos bajo stock...');
                // Aquí puedes implementar la generación del reporte
            }

            function generarReporteMovimientos() {
                // TODO: Implementar generación de reporte de movimientos
                alert('Generando reporte de movimientos de inventario...');
                // Aquí puedes implementar la generación del reporte
            }
        });
    </script>
</body>

</html>