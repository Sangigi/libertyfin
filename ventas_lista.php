<?php
// ventas_lista.php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Conectar a la base de datos de la empresa
try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // === OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL ===
    $conn_main = getDBConnection();

    // Valores por defecto
    $empresa_plan = "prueba";
    $timbres_totales = 0;
    $timbres_disponibles = 0;

    if ($conn_main) {
        $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
        $stmt_empresa = $conn_main->prepare($sql_empresa);
        $stmt_empresa->execute([$_SESSION['empresa_id']]);
        $result_empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);

        if ($result_empresa) {
            $empresa_plan = $result_empresa['plan'];
            $timbres_totales = $result_empresa['timbres_totales'] ?? 0;
            $timbres_disponibles = $result_empresa['timbres_disponibles'] ?? 0;
        }
        $stmt_empresa = null;
        $conn_main = null;
    }

    // Guardar el plan en la sesión
    $_SESSION['empresa_plan'] = $empresa_plan;
    // === FIN DEL CÓDIGO AGREGADO ===

    // Obtener información de la empresa y colores personalizados
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch(PDO::FETCH_ASSOC);

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

    // Si no hay colores configurados, usar valores por defecto
    $color_primario = $empresa_info['color_primario'] ?? '#27ae60';
    $color_secundario = $empresa_info['color_secundario'] ?? '#2ecc71';

    // Parámetros de filtro
    $filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
    $filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';
    $filtro_estado = $_GET['estado'] ?? '';
    $filtro_sucursal = $_GET['sucursal'] ?? '';
    $filtro_metodo_pago = $_GET['metodo_pago'] ?? '';
    $filtro_categoria = $_GET['categoria'] ?? '';
    $filtro_orden = $_GET['orden'] ?? 'desc';
    if (!in_array($filtro_orden, ['asc', 'desc'])) {
        $filtro_orden = 'desc';
    }

    // Construir WHERE clause
    $where_conditions = [];
    $params = [];

    if (!empty($filtro_fecha_desde)) {
        $where_conditions[] = "DATE(v.fecha) >= ?";
        $params[] = $filtro_fecha_desde;
    }

    if (!empty($filtro_fecha_hasta)) {
        $where_conditions[] = "DATE(v.fecha) <= ?";
        $params[] = $filtro_fecha_hasta;
    }

    if (!empty($filtro_estado)) {
        $where_conditions[] = "v.estado = ?";
        $params[] = $filtro_estado;
    }

    if (!empty($filtro_sucursal)) {
        $where_conditions[] = "v.sucursal_id = ?";
        $params[] = $filtro_sucursal;
    }

    if (!empty($filtro_metodo_pago)) {
        $where_conditions[] = "v.metodo_pago = ?";
        $params[] = $filtro_metodo_pago;
    }

    if (!empty($filtro_categoria)) {
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM venta_detalles vd 
            INNER JOIN productos p ON vd.producto_id = p.id 
            WHERE vd.venta_id = v.id AND p.categoria_id = ?
        )";
        $params[] = $filtro_categoria;
    }

    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }

    // Obtener el total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM ventas v $where_clause";
    $stmt_count = $conn->prepare($sql_count);
    if (!empty($params)) {
        $stmt_count->execute($params);
    } else {
        $stmt_count->execute();
    }
    $result_count = $stmt_count->fetch(PDO::FETCH_ASSOC);
    $total_registros = $result_count['total'];
    $stmt_count = null;

    // Calcular total de páginas
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // Obtener ventas con productos agrupados - MODIFICADO
    $sql_ventas = "
        SELECT 
            v.*,
            c.nombre as cliente_nombre,
            c.telefono as cliente_telefono,
            u.nombre as usuario_nombre,
            s.nombre as sucursal_nombre,
            GROUP_CONCAT(
                DISTINCT CONCAT(p.nombre, ' (', vd.cantidad, ')') 
                SEPARATOR ', '
            ) as productos_resumen
        FROM ventas v 
        LEFT JOIN clientes c ON v.cliente_id = c.id
        LEFT JOIN usuarios u ON v.usuario_id = u.id
        LEFT JOIN sucursales s ON v.sucursal_id = s.id
        LEFT JOIN venta_detalles vd ON v.id = vd.venta_id
        LEFT JOIN productos p ON vd.producto_id = p.id
        $where_clause
        GROUP BY v.id
        ORDER BY v.fecha $filtro_orden, v.id $filtro_orden
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql_ventas);

    // Preparar parámetros para la consulta con paginación
    $all_params = $params;
    $all_params[] = $registros_por_pagina;
    $all_params[] = $offset;
    
    $stmt->execute($all_params);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    
    // Sanitizar valores nulos
    foreach ($ventas as &$venta) {
        $venta['cliente_nombre'] = $venta['cliente_nombre'] ?? 'Cliente no especificado';
        $venta['sucursal_nombre'] = $venta['sucursal_nombre'] ?? 'Sucursal no especificada';
        $venta['usuario_nombre'] = $venta['usuario_nombre'] ?? 'Usuario no especificado';
        $venta['estado'] = $venta['estado'] ?? 'completada';
        $venta['efectivo_recibido'] = $venta['efectivo_recibido'] ?? 0;
        $venta['cambio'] = $venta['cambio'] ?? 0;
        $venta['descuento'] = $venta['descuento'] ?? 0;
        $venta['productos_resumen'] = $venta['productos_resumen'] ?? 'Sin productos';
    }
    unset($venta);
    $stmt = null;

    // Obtener estadísticas de ventas (sin paginación para mostrar totales)
    $sql_stats = "
        SELECT 
            COUNT(*) as total_ventas,
            SUM(total) as monto_total,
            AVG(total) as promedio_venta,
            SUM(descuento) as total_descuentos,
            SUM(CASE WHEN estado = 'completada' THEN 1 ELSE 0 END) as ventas_completadas,
            SUM(CASE WHEN estado = 'cancelada' THEN 1 ELSE 0 END) as ventas_canceladas,
            SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as ventas_pendientes
        FROM ventas v
        $where_clause
    ";

    $stmt_stats = $conn->prepare($sql_stats);
    if (!empty($params)) {
        $stmt_stats->execute($params);
    } else {
        $stmt_stats->execute();
    }
    $stats_ventas = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    $stmt_stats = null;

    // Obtener sucursales para filtro
    $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre";
    $result_sucursales = $conn->query($sql_sucursales);
    $sucursales = $result_sucursales->fetchAll(PDO::FETCH_ASSOC);

    // Obtener categorías para filtro
    $sql_categorias = "SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre";
    $result_categorias = $conn->query($sql_categorias);
    $categorias = $result_categorias->fetchAll(PDO::FETCH_ASSOC);

    // Métodos de pago predefinidos
    $metodos_pago = ['efectivo', 'tarjeta', 'transferencia'];

    // Inicializar variables para detalles de venta
    $detalles_venta = [];
    $venta_especifica = null;

    // Obtener detalles de ventas para el modal
    if (isset($_GET['ver_venta'])) {
        $venta_id = intval($_GET['ver_venta']);
        $sql_detalles = "
            SELECT 
                vd.*,
                p.nombre as producto_nombre,
                p.codigo as producto_codigo,
                p.precio as precio_unitario,
                c.nombre as categoria_nombre
            FROM venta_detalles vd
            LEFT JOIN productos p ON vd.producto_id = p.id
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE vd.venta_id = ?
        ";
        $stmt_detalles = $conn->prepare($sql_detalles);
        $stmt_detalles->execute([$venta_id]);
        $detalles_venta = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
        $stmt_detalles = null;

        // Obtener información de la venta específica
        $sql_venta_especifica = "
            SELECT v.*, c.nombre as cliente_nombre, s.nombre as sucursal_nombre, u.nombre as usuario_nombre
            FROM ventas v
            LEFT JOIN clientes c ON v.cliente_id = c.id
            LEFT JOIN sucursales s ON v.sucursal_id = s.id
            LEFT JOIN usuarios u ON v.usuario_id = u.id
            WHERE v.id = ?
        ";
        $stmt_venta = $conn->prepare($sql_venta_especifica);
        $stmt_venta->execute([$venta_id]);
        $venta_especifica = $stmt_venta->fetch(PDO::FETCH_ASSOC);
        $stmt_venta = null;

        // Calcular el número secuencial de esta venta, respetando los MISMOS
        // filtros y orden que se usan en el listado, para que coincida
        // exactamente con el número mostrado en la tabla/tarjetas.
        $numero_venta_detalle = null;
        if ($venta_especifica) {
            $condicion_posicion = ($filtro_orden === 'desc')
                ? "(v.fecha > ? OR (v.fecha = ? AND v.id > ?))"
                : "(v.fecha < ? OR (v.fecha = ? AND v.id < ?))";

            $sql_posicion = "SELECT COUNT(*) as posicion FROM ventas v "
                . ($where_clause !== '' ? $where_clause . " AND " : "WHERE ")
                . $condicion_posicion;

            $params_posicion = array_merge(
                $params,
                [$venta_especifica['fecha'], $venta_especifica['fecha'], $venta_id]
            );

            $stmt_posicion = $conn->prepare($sql_posicion);
            $stmt_posicion->execute($params_posicion);
            $resultado_posicion = $stmt_posicion->fetch(PDO::FETCH_ASSOC);
            $stmt_posicion = null;

            $numero_venta_detalle = intval($resultado_posicion['posicion']) + 1;
        }
    }
    
    // Cerrar conexión
    $conn = null;
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Función auxiliar para sanitizar valores antes de htmlspecialchars
function safe_html($value)
{
    if ($value === null || $value === '') {
        return 'Cliente General';
    }
    return htmlspecialchars($value);
}

// Función para truncar texto
function truncate_text($text, $max_length = 50) {
    if (strlen($text) <= $max_length) {
        return $text;
    }
    return substr($text, 0, $max_length) . '...';
}

// Determinar si el usuario es admin
$is_admin = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Historial de Ventas - <?php echo safe_html($_SESSION['empresa_nombre'] ?? ''); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars($color_primario); ?>;
            --secondary-color: <?php echo htmlspecialchars($color_secundario); ?>;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 56px;
            overflow-x: hidden;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .navbar-brand img {
            height: 35px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .navbar-brand span {
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }

        @media (max-width: 576px) {
            .navbar-brand span {
                max-width: 100px;
                font-size: 0.8rem;
            }
            
            .navbar-brand img {
                height: 30px;
            }
        }

        /* Sidebar Desktop */
        .sidebar-desktop {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            position: fixed;
            top: 56px;
            left: 0;
            width: 260px;
            z-index: 100;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        /* Sidebar Mobile */
        .sidebar-mobile {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            position: fixed;
            top: 56px;
            left: 0;
            width: 280px;
            z-index: 1000;
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
            overflow-y: auto;
        }

        .sidebar-mobile.show {
            transform: translateX(0);
        }

        .sidebar-desktop .nav-link,
        .sidebar-mobile .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .sidebar-desktop .nav-link:hover,
        .sidebar-mobile .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar-desktop .nav-link.active,
        .sidebar-mobile .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar-desktop .nav-link i,
        .sidebar-mobile .nav-link i {
            width: 24px;
            margin-right: 10px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            transition: margin-left 0.3s ease;
            width: 100%;
        }

        @media (min-width: 992px) {
            .main-content {
                margin-left: 260px !important;
                width: calc(100% - 260px) !important;
            }
        }

        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* Cards y estadísticas */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease;
            margin-bottom: 1rem;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .stat-card {
            border-left: 4px solid var(--primary-color);
            background: white;
        }

        .stat-card .card-body {
            padding: 1.25rem;
        }

        .stat-card h3 {
            font-size: 1.5rem;
            margin-bottom: 0;
        }

        @media (max-width: 576px) {
            .stat-card h3 {
                font-size: 1.2rem;
            }
            
            .stat-card .card-body {
                padding: 1rem;
            }
            
            .stat-card h6 {
                font-size: 0.75rem;
            }
        }

        /* Badges de estado */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-completada {
            background: #d4edda;
            color: #155724;
        }

        .status-pendiente {
            background: #fff3cd;
            color: #856404;
        }

        .status-cancelada {
            background: #f8d7da;
            color: #721c24;
        }

        /* Método de pago badges */
        .metodo-pago-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-block;
        }

        .metodo-pago-color-efectivo {
            background: #d4edda;
            color: #155724;
        }

        .metodo-pago-color-tarjeta {
            background: #cce7ff;
            color: #004085;
        }

        .metodo-pago-color-transferencia {
            background: #e2e3e5;
            color: #383d41;
        }

        /* Productos badge */
        .productos-badge {
            background: #f0f0f0;
            color: #2c3e50;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            display: inline-block;
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Tabla responsive */
        .table-responsive-custom {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table th,
        .table td {
            white-space: nowrap;
            vertical-align: middle;
        }

        @media (max-width: 768px) {
            .table th,
            .table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
        }

        /* Tarjetas móviles */
        .mobile-venta-card {
            border-left: 4px solid var(--primary-color);
            margin-bottom: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .mobile-venta-card.venta-grande {
            border-left-color: #e74c3c;
        }

        .mobile-venta-card:active {
            transform: scale(0.98);
        }

        .venta-avatar {
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
            flex-shrink: 0;
        }

        /* Botones */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .btn-success {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-success:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .btn-danger-venta {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }

        .btn-danger-venta:hover {
            background-color: #c82333;
            border-color: #bd2130;
            color: white;
        }

        @media (max-width: 576px) {
            .btn {
                padding: 0.375rem 0.75rem;
                font-size: 0.875rem;
            }
            
            .mobile-actions {
                display: flex;
                gap: 0.5rem;
                margin-top: 0.75rem;
                flex-wrap: wrap;
            }
            
            .mobile-actions .btn {
                flex: 1;
                padding: 0.375rem 0.5rem;
                font-size: 0.75rem;
                min-width: 80px;
            }
            
            .modal-footer {
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .modal-footer .btn {
                flex: 1;
                min-width: 100px;
                font-size: 0.8rem;
            }
        }

        /* Filtros */
        .filtros-avanzados {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filtros-mobile {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filtros-activos {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
        }

        /* Paginación */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
            flex-wrap: wrap;
            gap: 1rem;
        }

        @media (max-width: 576px) {
            .pagination-container {
                flex-direction: column;
                text-align: center;
            }
            
            .pagination {
                margin-bottom: 0;
            }
        }

        /* Overlay y área de swipe */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        .swipe-sensitive-area {
            position: fixed;
            top: 56px;
            left: 0;
            width: 15px;
            height: calc(100vh - 56px);
            z-index: 1100;
        }

        @media (min-width: 992px) {
            .swipe-sensitive-area {
                display: none;
            }
        }

        /* Botón hamburguesa */
        .hamburger-swipe-area {
            position: relative;
            width: 28px;
            height: 28px;
            background: transparent;
            border: none;
            padding: 0;
            cursor: pointer;
        }

        .hamburger-swipe-area span {
            display: block;
            width: 100%;
            height: 2px;
            background: white;
            margin: 5px 0;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .hamburger-swipe-area.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 5px);
        }

        .hamburger-swipe-area.active span:nth-child(2) {
            opacity: 0;
        }

        .hamburger-swipe-area.active span:nth-child(3) {
            transform: rotate(-45deg) translate(5px, -5px);
        }

        /* Utilidades */
        .info-text {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .monto-venta {
            font-weight: 700;
            color: var(--primary-color);
        }

        .cliente-badge {
            background: #e8f4fd;
            color: #2c3e50;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            display: inline-block;
        }

        .ticket-number {
            font-family: monospace;
            font-size: 0.7rem;
            color: #6c757d;
        }

        .descuento-text {
            color: #dc3545;
            font-size: 0.7rem;
        }

        .clickable-row,
        .clickable-card {
            cursor: pointer;
        }

        .clickable-row:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }

        /* Scroll suave */
        html {
            scroll-behavior: smooth;
        }

        /* Ajustes para dispositivos muy pequeños */
        @media (max-width: 380px) {
            .stats-row .col-6 {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
            
            .mobile-venta-card .card-body {
                padding: 0.75rem;
            }
            
            .venta-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }
        }

        /* Botón eliminar en modal */
        .btn-eliminar-venta {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
            transition: all 0.2s ease;
        }

        .btn-eliminar-venta:hover {
            background-color: #c82333;
            border-color: #bd2130;
            color: white;
            transform: scale(1.02);
        }

        .btn-eliminar-venta:active {
            transform: scale(0.95);
        }

        .btn-eliminar-venta:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Estilo para la descripción en el modal */
        .descripcion-badge {
            background: #e8f4fd;
            color: #2c3e50;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            white-space: normal;
            word-wrap: break-word;
            max-width: 100%;
            display: inline-block;
            border-left: 3px solid var(--primary-color);
        }

        /* Productos en móvil */
        .productos-mobile {
            font-size: 0.75rem;
            color: #6c757d;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <!-- Overlay para sidebar móvil -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Área sensible al swipe -->
    <div class="swipe-sensitive-area" id="swipeArea"></div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container-fluid px-2 px-sm-3">
            <!-- Botón hamburguesa solo en móviles -->
            <button class="navbar-toggler me-2 d-lg-none" type="button" id="sidebarToggleMobile">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span><?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span><?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                    <span><?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></span>
                <?php endif; ?>
            </a>

            <div class="ms-auto">
                <div class="dropdown">
                    <button class="btn btn-link text-white text-decoration-none dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <span class="d-none d-sm-inline"><?php echo safe_html($_SESSION['usuario_nombre'] ?? ''); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo safe_html($_SESSION['empresa_nombre'] ?? ''); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo safe_html($_SESSION['usuario_rol'] ?? ''); ?></small>
                            </span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar Desktop -->
            <div class="sidebar-desktop d-none d-lg-block">
                <div class="pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Inicio
                            </a>
                        </li>
                        <?php if (($_SESSION['usuario_rol'] ?? '') === 'admin'): ?>
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
                            <a class="nav-link" href="clientes.php">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="ventas_lista.php">
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
                        <?php if ($empresa_plan !== 'basico' && $_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="sucursales.php">
                                    <i class="fas fa-store"></i>
                                    Sucursales
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles > 0) : ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Facturacion/inicio.php">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    Facturación
                                    <?php if ($timbres_disponibles > 0): ?>
                                        <span class="badge bg-success ms-2"><?php echo $timbres_disponibles; ?></span>
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
                                    <img src="../images/emidalogo.png" alt="" style="width: 20px; height: 20px; margin-right: 10px;">
                                    Emida Servicios
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (($_SESSION['usuario_rol'] ?? '') === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i>
                                    Comisiones
                                </a>
                            </li>
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

            <!-- Sidebar Mobile -->
            <div class="sidebar-mobile d-lg-none" id="sidebarMobile">
                <div class="pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <?php if (($_SESSION['usuario_rol'] ?? '') === 'admin'): ?>
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
                            <a class="nav-link" href="clientes.php">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="ventas_lista.php">
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
                        <?php if ($empresa_plan !== 'basico' && $_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="sucursales.php">
                                    <i class="fas fa-store"></i>
                                    Sucursales
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
                                    <img src="../images/emidalogo.png" alt="" style="width: 20px; height: 20px; margin-right: 10px;">
                                    Emida Servicios
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (($_SESSION['usuario_rol'] ?? '') === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i>
                                    Comisiones
                                </a>
                            </li>
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
            <main class="main-content">
                <div class="container-fluid px-3 px-sm-4 py-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                        <h2 class="mb-0 fs-4 fs-md-3">
                            <i class="fas fa-receipt me-2"></i>
                            Historial de Ventas
                        </h2>
                        <div class="d-flex gap-2">
                            <a href="caja.php" class="btn btn-success">
                                <i class="fas fa-cash-register me-2"></i>
                                <span class="d-none d-sm-inline">Nueva Venta</span>
                                <span class="d-inline d-sm-none">Venta</span>
                            </a>
                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#filtrosModal">
                                <i class="fas fa-filter me-2"></i>
                                <span class="d-none d-sm-inline">Filtros</span>
                            </button>
                        </div>
                    </div>

                    <!-- Filtros activos -->
                    <?php if ($filtro_fecha_desde || $filtro_fecha_hasta || $filtro_estado || $filtro_sucursal || $filtro_metodo_pago || $filtro_categoria): ?>
                        <div class="filtros-activos">
                            <h6 class="mb-2 fs-6">Filtros Aplicados:</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($filtro_fecha_desde): ?>
                                    <span class="badge bg-primary">Desde: <?php echo safe_html($filtro_fecha_desde); ?></span>
                                <?php endif; ?>
                                <?php if ($filtro_fecha_hasta): ?>
                                    <span class="badge bg-primary">Hasta: <?php echo safe_html($filtro_fecha_hasta); ?></span>
                                <?php endif; ?>
                                <?php if ($filtro_estado): ?>
                                    <span class="badge bg-info">Estado: <?php echo ucfirst($filtro_estado); ?></span>
                                <?php endif; ?>
                                <?php if ($filtro_sucursal): ?>
                                    <?php
                                    $sucursal_nombre = 'Sucursal no encontrada';
                                    foreach ($sucursales as $suc) {
                                        if ($suc['id'] == $filtro_sucursal) {
                                            $sucursal_nombre = $suc['nombre'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <span class="badge bg-success">Sucursal: <?php echo safe_html($sucursal_nombre); ?></span>
                                <?php endif; ?>
                                <?php if ($filtro_metodo_pago): ?>
                                    <span class="badge metodo-pago-color-<?php echo $filtro_metodo_pago; ?>">Pago: <?php echo ucfirst($filtro_metodo_pago); ?></span>
                                <?php endif; ?>
                                <?php if ($filtro_categoria): 
                                    $categoria_nombre = '';
                                    foreach ($categorias as $cat) {
                                        if ($cat['id'] == $filtro_categoria) {
                                            $categoria_nombre = $cat['nombre'];
                                            break;
                                        }
                                    }
                                ?>
                                    <span class="badge bg-warning text-dark">Categoría: <?php echo safe_html($categoria_nombre); ?></span>
                                <?php endif; ?>
                                <a href="ventas_lista.php" class="badge bg-danger text-decoration-none">Limpiar</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Filtros Desktop -->
                    <div class="d-none d-lg-block filtros-avanzados">
                        <form method="GET" id="filtrosForm">
                            <input type="hidden" name="pagina" value="1">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label small">Fecha Desde</label>
                                    <input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?php echo safe_html($filtro_fecha_desde); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Fecha Hasta</label>
                                    <input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?php echo safe_html($filtro_fecha_hasta); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Estado</label>
                                    <select class="form-select form-select-sm" name="estado">
                                        <option value="">Todos</option>
                                        <option value="completada" <?php echo $filtro_estado === 'completada' ? 'selected' : ''; ?>>Completadas</option>
                                        <option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
                                        <option value="cancelada" <?php echo $filtro_estado === 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Sucursal</label>
                                    <select class="form-select form-select-sm" name="sucursal">
                                        <option value="">Todas</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>" <?php echo $filtro_sucursal == $sucursal['id'] ? 'selected' : ''; ?>>
                                                <?php echo safe_html($sucursal['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Método Pago</label>
                                    <select class="form-select form-select-sm" name="metodo_pago">
                                        <option value="">Todos</option>
                                        <?php foreach ($metodos_pago as $metodo): ?>
                                            <option value="<?php echo $metodo; ?>" <?php echo $filtro_metodo_pago === $metodo ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($metodo); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Categoría</label>
                                    <select class="form-select form-select-sm" name="categoria">
                                        <option value="">Todas</option>
                                        <?php foreach ($categorias as $categoria): ?>
                                            <option value="<?php echo $categoria['id']; ?>" <?php echo ($filtro_categoria ?? '') == $categoria['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Orden</label>
                                    <select class="form-select form-select-sm" name="orden">
                                        <option value="desc" <?php echo $filtro_orden === 'desc' ? 'selected' : ''; ?>>Más reciente primero</option>
                                        <option value="asc" <?php echo $filtro_orden === 'asc' ? 'selected' : ''; ?>>Más antigua primero</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button>
                                        <a href="ventas_lista.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Estadísticas -->
                    <div class="row g-3 mb-4 stats-row">
                        <div class="col-6 col-md-3">
                            <div class="card stat-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="card-title text-muted mb-1 small">Total Ventas</h6>
                                            <h3 class="mb-0 text-primary"><?php echo $stats_ventas['total_ventas'] ?? 0; ?></h3>
                                        </div>
                                        <i class="fas fa-shopping-cart fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card stat-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="card-title text-muted mb-1 small">Monto Total</h6>
                                            <h3 class="mb-0 text-success fs-6 fs-md-3">$<?php echo number_format($stats_ventas['monto_total'] ?? 0, 2); ?></h3>
                                        </div>
                                        <i class="fas fa-dollar-sign fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card stat-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="card-title text-muted mb-1 small">Promedio Venta</h6>
                                            <h3 class="mb-0 text-info">$<?php echo number_format($stats_ventas['promedio_venta'] ?? 0, 2); ?></h3>
                                        </div>
                                        <i class="fas fa-chart-line fa-2x text-info opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card stat-card h-100 descuento-total">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="card-title text-muted mb-1 small">Total Descuentos</h6>
                                            <h3 class="mb-0 text-danger">$<?php echo number_format($stats_ventas['total_descuentos'] ?? 0, 2); ?></h3>
                                        </div>
                                        <i class="fas fa-tag fa-2x text-danger opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Barra de Búsqueda -->
                    <div class="card mb-4">
                        <div class="card-body py-3">
                            <div class="row align-items-center g-3">
                                <div class="col-md-6">
                                    <div class="position-relative">
                                        <i class="fas fa-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted small"></i>
                                        <input type="text" class="form-control ps-5" placeholder="Buscar ventas..." id="searchInput">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-md-end gap-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="showSucursal" checked>
                                            <label class="form-check-label small" for="showSucursal">Sucursal</label>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="showCliente" checked>
                                            <label class="form-check-label small" for="showCliente">Cliente</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vista Desktop - MODIFICADA CON COLUMNA DE PRODUCTOS -->
                    <div class="d-none d-lg-block">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center py-3">
                                <h5 class="card-title mb-0">Lista de Ventas</h5>
                                <span class="badge bg-secondary">Pág. <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive-custom">
                                    <table class="table table-hover mb-0" id="ventasTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Venta</th>
                                                <th>Cliente</th>
                                                <th>Sucursal</th>
                                                <th>Vendedor</th>
                                                <th>Productos</th>
                                                <th>Total</th>
                                                <th>Estado</th>
                                                <th>Método Pago</th>
                                                <th>Fecha</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($ventas)): ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted py-4">
                                                        <i class="fas fa-receipt fa-3x mb-3 d-block"></i>
                                                        <p>No se encontraron ventas</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php 
                                                // Si es descendente, empieza desde el total general de registros menos el offset.
                                                // Si es ascendente, empieza desde el offset + 1.
                                                $numero_venta = ($filtro_orden === 'desc') ? ($total_registros - $offset) : ($offset + 1); 
                                                ?>
                                                <?php foreach ($ventas as $venta): ?>
                                                    <tr class="clickable-row" data-venta-id="<?php echo $venta['id']; ?>">
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="venta-avatar me-3">V</div>
                                                                <div>
                                                                    <div class="fw-bold">#<?php echo $numero_venta; ?></div>
                                                                    <?php ($filtro_orden === 'desc') ? $numero_venta-- : $numero_venta++; ?>
                                                                    <div class="ticket-number"><?php echo safe_html($venta['codigo_venta']); ?></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td><span class="cliente-badge"><?php echo safe_html($venta['cliente_nombre']); ?></span></td>
                                                        <td><span class="info-text"><?php echo safe_html($venta['sucursal_nombre']); ?></span></td>
                                                        <td><span class="info-text"><?php echo safe_html($venta['usuario_nombre']); ?></span></td>
                                                        <td>
                                                            <span class="productos-badge" title="<?php echo safe_html($venta['productos_resumen']); ?>">
                                                                <?php echo safe_html(truncate_text($venta['productos_resumen'], 40)); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="monto-venta">$<?php echo number_format($venta['total'], 2); ?></span>
                                                            <?php if ($venta['descuento'] > 0): ?>
                                                                <br><small class="text-danger">-$$<?php echo number_format($venta['descuento'], 2); ?></small>
                                                            <?php endif; ?>
                                                         </td>
                                                        <td><span class="status-badge status-<?php echo $venta['estado']; ?>"><?php echo ucfirst($venta['estado']); ?></span></td>
                                                        <td><span class="metodo-pago-badge metodo-pago-color-<?php echo $venta['metodo_pago']; ?>"><?php echo ucfirst($venta['metodo_pago']); ?></span></td>
                                                        <td>
                                                            <div class="info-text">
                                                                <?php echo date('d/m/Y', strtotime($venta['fecha'])); ?>
                                                                <br><small><?php echo date('H:i', strtotime($venta['fecha'])); ?></small>
                                                            </div>
                                                         </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Paginación Desktop -->
                                <?php if ($total_paginas > 1): ?>
                                    <div class="pagination-container px-3 py-3">
                                        <div class="pagination-info small">Mostrando <?php echo count($ventas); ?> de <?php echo $total_registros; ?> ventas</div>
                                        <nav>
                                            <ul class="pagination pagination-sm mb-0">
                                                <?php if ($pagina_actual > 1): ?>
                                                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>"><i class="fas fa-angle-double-left"></i></a></li>
                                                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>"><i class="fas fa-angle-left"></i></a></li>
                                                <?php else: ?>
                                                    <li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-double-left"></i></span></li>
                                                    <li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-left"></i></span></li>
                                                <?php endif; ?>

                                                <?php
                                                $inicio = max(1, $pagina_actual - 2);
                                                $fin = min($total_paginas, $pagina_actual + 2);
                                                for ($i = $inicio; $i <= $fin; $i++):
                                                ?>
                                                    <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a>
                                                    </li>
                                                <?php endfor; ?>

                                                <?php if ($pagina_actual < $total_paginas): ?>
                                                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>"><i class="fas fa-angle-right"></i></a></li>
                                                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>"><i class="fas fa-angle-double-right"></i></a></li>
                                                <?php else: ?>
                                                    <li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-right"></i></span></li>
                                                    <li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-double-right"></i></span></li>
                                                <?php endif; ?>
                                            </ul>
                                        </nav>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Vista Móvil - MODIFICADA CON PRODUCTOS -->
                    <div class="d-lg-none">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Lista de Ventas</h6>
                            <span class="badge bg-secondary">Pág. <?php echo $pagina_actual; ?>/<?php echo $total_paginas; ?></span>
                        </div>

                        <div id="mobileVentas">
                            <?php if (empty($ventas)): ?>
                                <div class="card text-center text-muted py-4">
                                    <i class="fas fa-receipt fa-3x mb-3"></i>
                                    <p>No se encontraron ventas</p>
                                </div>
                            <?php else: ?>
                                <?php 
                                // Aplicamos la misma lógica matemática para el listado móvil
                                $numero_venta_mobile = ($filtro_orden === 'desc') ? ($total_registros - $offset) : ($offset + 1); 
                                ?>
                                <?php foreach ($ventas as $venta): ?>
                                    <div class="card mobile-venta-card <?php echo $venta['total'] > 1000 ? 'venta-grande' : ''; ?> clickable-card" data-venta-id="<?php echo $venta['id']; ?>">
                                        <div class="card-body p-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="venta-avatar me-3">V</div>
                                                    <div>
                                                        <h6 class="fw-bold mb-0">Venta #<?php echo $numero_venta_mobile; ?></h6>
                                                        <?php ($filtro_orden === 'desc') ? $numero_venta_mobile-- : $numero_venta_mobile++; ?>
                                                        <div class="ticket-number"><?php echo safe_html($venta['codigo_venta']); ?></div>
                                                    </div>
                                                </div>
                                                <span class="status-badge status-<?php echo $venta['estado']; ?>"><?php echo ucfirst($venta['estado']); ?></span>
                                            </div>

                                            <div class="row text-center mb-2">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Total</small>
                                                    <span class="monto-venta">$<?php echo number_format($venta['total'], 2); ?></span>
                                                    <?php if ($venta['descuento'] > 0): ?>
                                                        <br><small class="text-danger">Desc: -$$<?php echo number_format($venta['descuento'], 2); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Fecha</small>
                                                    <span class="info-text"><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></span>
                                                </div>
                                            </div>

                                            <!-- Productos en móvil -->
                                            <div class="productos-mobile mb-2">
                                                <i class="fas fa-box me-1"></i>
                                                <?php echo safe_html(truncate_text($venta['productos_resumen'], 60)); ?>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="cliente-badge"><?php echo safe_html($venta['cliente_nombre']); ?></span>
                                                <span class="info-text sucursal-info"><?php echo safe_html($venta['sucursal_nombre']); ?></span>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="info-text">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php echo safe_html($venta['usuario_nombre']); ?>
                                                </div>
                                                <span class="metodo-pago-badge metodo-pago-color-<?php echo $venta['metodo_pago']; ?>">
                                                    <?php echo ucfirst($venta['metodo_pago']); ?>
                                                </span>
                                            </div>

                                            <div class="mobile-actions mt-3">
                                                <button class="btn btn-outline-info btn-sm reimprimir-ticket-btn" data-venta-id="<?php echo $venta['id']; ?>">
                                                    <i class="fas fa-print me-1"></i>Ticket
                                                </button>
                                                <?php if ($venta['estado'] === 'pendiente'): ?>
                                                    <button class="btn btn-outline-success btn-sm completar-venta" data-venta-id="<?php echo $venta['id']; ?>">
                                                        <i class="fas fa-check me-1"></i>Completar
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Paginación Móvil -->
                        <?php if ($total_paginas > 1): ?>
                            <div class="pagination-container mt-3">
                                <div class="pagination-info small"><?php echo count($ventas); ?> de <?php echo $total_registros; ?> ventas</div>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php if ($pagina_actual > 1): ?>
                                            <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>"><i class="fas fa-chevron-left"></i></a></li>
                                        <?php else: ?>
                                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-chevron-left"></i></span></li>
                                        <?php endif; ?>
                                        <li class="page-item disabled"><span class="page-link"><?php echo $pagina_actual; ?> / <?php echo $total_paginas; ?></span></li>
                                        <?php if ($pagina_actual < $total_paginas): ?>
                                            <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>"><i class="fas fa-chevron-right"></i></a></li>
                                        <?php else: ?>
                                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-chevron-right"></i></span></li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Detalles Venta -->
    <div class="modal fade" id="detallesVentaModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalles de Venta <?php echo isset($numero_venta_detalle) && $numero_venta_detalle !== null ? '#' . $numero_venta_detalle : ''; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($detalles_venta) && !empty($venta_especifica)): ?>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <strong>Información de la Venta:</strong>
                                <p class="mb-1">Código: <?php echo safe_html($venta_especifica['codigo_venta']); ?></p>
                                <p class="mb-1">Fecha: <?php echo date('d/m/Y H:i', strtotime($venta_especifica['fecha'])); ?></p>
                                <p class="mb-1">Subtotal: $<?php echo number_format($venta_especifica['subtotal'], 2); ?></p>
                                <?php if ($venta_especifica['descuento'] > 0): ?>
                                    <p class="mb-1 text-danger">Descuento: -$<?php echo number_format($venta_especifica['descuento'], 2); ?></p>
                                <?php endif; ?>
                                <p class="mb-1">IVA: $<?php echo number_format($venta_especifica['iva'], 2); ?></p>
                                <p class="mb-1">Total: <strong>$<?php echo number_format($venta_especifica['total'], 2); ?></strong></p>
                            </div>
                            <div class="col-md-6">
                                <strong>Información Adicional:</strong>
                                <p class="mb-1">Estado: <span class="status-badge status-<?php echo $venta_especifica['estado']; ?>"><?php echo ucfirst($venta_especifica['estado']); ?></span></p>
                                <p class="mb-1">Método de Pago: <?php echo ucfirst($venta_especifica['metodo_pago']); ?></p>
                                <?php if ($venta_especifica['metodo_pago'] === 'efectivo'): ?>
                                    <p class="mb-1">Efectivo Recibido: $<?php echo number_format($venta_especifica['efectivo_recibido'], 2); ?></p>
                                    <p class="mb-1">Cambio: $<?php echo number_format($venta_especifica['cambio'], 2); ?></p>
                                <?php endif; ?>
                                <p class="mb-1">Cliente: <?php echo safe_html($venta_especifica['cliente_nombre']); ?></p>
                                <p class="mb-1">Sucursal: <?php echo safe_html($venta_especifica['sucursal_nombre']); ?></p>
                                <p class="mb-1">Vendedor: <?php echo safe_html($venta_especifica['usuario_nombre']); ?></p>
                                
                                <?php if (!empty($venta_especifica['descripcion'])): ?>
                                    <div class="mt-3">
                                        <strong>Descripción:</strong>
                                        <div class="descripcion-badge mt-1">
                                            <?php echo nl2br(safe_html($venta_especifica['descripcion'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <h6>Productos Vendidos:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Producto</th>
                                        <th>Código</th>
                                        <th>Cantidad</th>
                                        <th>Precio</th>
                                        <th>Descuento</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detalles_venta as $detalle): ?>
                                        <tr>
                                            <td><?php echo safe_html($detalle['producto_nombre']); ?></td>
                                            <td><?php echo safe_html($detalle['producto_codigo']); ?></td>
                                            <td><?php echo $detalle['cantidad']; ?> <?php echo safe_html($detalle['unidad_medida'] ?? ''); ?></td>
                                            <td>$<?php echo number_format($detalle['precio_unitario'], 2); ?></td>
                                            <td><?php if ($detalle['descuento'] > 0): ?>-$<?php echo number_format($detalle['descuento'], 2); ?><?php else: ?>$0.00<?php endif; ?></td>
                                            <td>$<?php echo number_format($detalle['subtotal'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <td colspan="5" class="text-end"><strong>Total:</strong></td>
                                        <td><strong>$<?php echo number_format($venta_especifica['total'], 2); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">No se encontraron detalles para esta venta.</div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <?php if (!empty($venta_especifica)): ?>
                        <button class="btn btn-primary reimprimir-ticket" data-venta-id="<?php echo $venta_especifica['id']; ?>">
                            <i class="fas fa-print me-2"></i>Reimprimir Ticket
                        </button>
                        <?php if ($is_admin): ?>
                            <button class="btn btn-danger-venta eliminar-venta" data-venta-id="<?php echo $venta_especifica['id']; ?>" data-venta-codigo="<?php echo safe_html($venta_especifica['codigo_venta']); ?>">
                                <i class="fas fa-trash me-2"></i>Eliminar Venta
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Filtros Móvil -->
    <div class="modal fade" id="filtrosModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Filtros</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="GET" action="ventas_lista.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small">Fecha Desde</label>
                            <input type="date" class="form-control" name="fecha_desde" value="<?php echo safe_html($filtro_fecha_desde); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Fecha Hasta</label>
                            <input type="date" class="form-control" name="fecha_hasta" value="<?php echo safe_html($filtro_fecha_hasta); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Estado</label>
                            <select class="form-select" name="estado">
                                <option value="">Todos</option>
                                <option value="completada" <?php echo $filtro_estado === 'completada' ? 'selected' : ''; ?>>Completadas</option>
                                <option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
                                <option value="cancelada" <?php echo $filtro_estado === 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Sucursal</label>
                            <select class="form-select" name="sucursal">
                                <option value="">Todas</option>
                                <?php foreach ($sucursales as $sucursal): ?>
                                    <option value="<?php echo $sucursal['id']; ?>" <?php echo $filtro_sucursal == $sucursal['id'] ? 'selected' : ''; ?>>
                                        <?php echo safe_html($sucursal['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Método de Pago</label>
                            <select class="form-select" name="metodo_pago">
                                <option value="">Todos</option>
                                <?php foreach ($metodos_pago as $metodo): ?>
                                    <option value="<?php echo $metodo; ?>" <?php echo $filtro_metodo_pago === $metodo ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($metodo); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Categoría</label>
                            <select class="form-select" name="categoria">
                                <option value="">Todas</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?php echo $categoria['id']; ?>" <?php echo ($filtro_categoria ?? '') == $categoria['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Orden</label>
                            <select class="form-select" name="orden">
                                <option value="desc" <?php echo $filtro_orden === 'desc' ? 'selected' : ''; ?>>Más reciente primero</option>
                                <option value="asc" <?php echo $filtro_orden === 'asc' ? 'selected' : ''; ?>>Más antigua primero</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="ventas_lista.php" class="btn btn-danger">Limpiar</a>
                        <button type="submit" class="btn btn-primary">Aplicar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar móvil
            const sidebarMobile = document.getElementById('sidebarMobile');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const hamburgerBtn = document.getElementById('sidebarToggleMobile');
            const swipeArea = document.getElementById('swipeArea');
            
            let touchStartX = 0;
            let isOpen = false;
            const SWIPE_THRESHOLD = 50;

            function openSidebar() {
                if (sidebarMobile && !isOpen) {
                    sidebarMobile.classList.add('show');
                    sidebarOverlay.classList.add('show');
                    document.body.style.overflow = 'hidden';
                    isOpen = true;
                    if (hamburgerBtn) hamburgerBtn.classList.add('active');
                }
            }

            function closeSidebar() {
                if (sidebarMobile && isOpen) {
                    sidebarMobile.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                    isOpen = false;
                    if (hamburgerBtn) hamburgerBtn.classList.remove('active');
                }
            }

            if (hamburgerBtn) {
                hamburgerBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    isOpen ? closeSidebar() : openSidebar();
                });
            }

            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', closeSidebar);
            }

            if (swipeArea) {
                swipeArea.addEventListener('touchstart', function(e) {
                    touchStartX = e.changedTouches[0].screenX;
                });
                
                swipeArea.addEventListener('touchend', function(e) {
                    const touchEndX = e.changedTouches[0].screenX;
                    const deltaX = touchEndX - touchStartX;
                    
                    if (deltaX > SWIPE_THRESHOLD && !isOpen) {
                        openSidebar();
                    } else if (deltaX < -SWIPE_THRESHOLD && isOpen) {
                        closeSidebar();
                    }
                });
            }

            // Búsqueda
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const term = e.target.value.toLowerCase();
                    
                    document.querySelectorAll('#ventasTable tbody tr').forEach(function(row) {
                        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                    });
                    
                    document.querySelectorAll('#mobileVentas .mobile-venta-card').forEach(function(card) {
                        card.style.display = card.textContent.toLowerCase().includes(term) ? '' : 'none';
                    });
                });
            }

            // Click en filas/tarjetas
            function abrirDetalles(ventaId) {
                const url = new URL(window.location.href);
                url.searchParams.set('ver_venta', ventaId);
                window.location.href = url.toString();
            }

            document.querySelectorAll('.clickable-row, .clickable-card').forEach(function(el) {
                el.querySelectorAll('button').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                });
                el.addEventListener('click', function() {
                    const ventaId = this.dataset.ventaId;
                    if (ventaId) abrirDetalles(ventaId);
                });
            });

            // Reimprimir ticket
            document.querySelectorAll('.reimprimir-ticket, .reimprimir-ticket-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const ventaId = this.dataset.ventaId;
                    window.open('reimprimir_ticket.php?venta_id=' + ventaId, '_blank', 'width=400,height=600');
                });
            });

            // Completar venta
            document.querySelectorAll('.completar-venta').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const ventaId = this.dataset.ventaId;
                    if (confirm('¿Completar venta #' + ventaId + '?')) {
                        fetch('completar_venta.php?id=' + ventaId, { method: 'POST' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) location.reload();
                                else alert('Error: ' + data.message);
                            })
                            .catch(function() { alert('Error al completar'); });
                    }
                });
            });

            // Eliminar venta - Solo para admin
            document.querySelectorAll('.eliminar-venta').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const ventaId = this.dataset.ventaId;
                    const ventaCodigo = this.dataset.ventaCodigo || ventaId;
                    
                    if (confirm('⚠️ ¿Está seguro de eliminar la venta #' + ventaCodigo + '?')) {
                        if (confirm('Confirmación final: ¿Eliminar permanentemente la venta #' + ventaCodigo + '?')) {
                            const originalText = this.innerHTML;
                            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Eliminando...';
                            this.disabled = true;
                            
                            const formData = new FormData();
                            formData.append('id', ventaId);
                            
                            fetch('eliminar_venta.php', { 
                                method: 'POST',
                                body: formData
                            })
                            .then(function(response) {
                                return response.text().then(function(text) {
                                    try {
                                        return JSON.parse(text);
                                    } catch (e) {
                                        throw new Error('Respuesta no es JSON válido: ' + text.substring(0, 200) + '...');
                                    }
                                });
                            })
                            .then(function(data) {
                                if (data.success) {
                                    const modal = bootstrap.Modal.getInstance(document.getElementById('detallesVentaModal'));
                                    if (modal) modal.hide();
                                    alert('✅ Venta eliminada correctamente');
                                    setTimeout(function() { location.reload(); }, 500);
                                } else {
                                    alert('❌ Error: ' + (data.message || 'No se pudo eliminar la venta'));
                                    this.innerHTML = originalText;
                                    this.disabled = false;
                                }
                            }.bind(this))
                            .catch(function(error) {
                                console.error('Error completo:', error);
                                alert('❌ Error: ' + error.message);
                                this.innerHTML = originalText;
                                this.disabled = false;
                            }.bind(this));
                        }
                    }
                });
            });

            // Mostrar modal si hay parámetro
            if (window.location.search.includes('ver_venta')) {
                const modal = new bootstrap.Modal(document.getElementById('detallesVentaModal'));
                modal.show();
                
                document.getElementById('detallesVentaModal')?.addEventListener('hidden.bs.modal', function() {
                    const url = new URL(window.location);
                    url.searchParams.delete('ver_venta');
                    window.history.replaceState({}, '', url);
                });
            }

            // Filtros visuales
            const showSucursal = document.getElementById('showSucursal');
            const showCliente = document.getElementById('showCliente');
            
            function toggleColumnas() {
                const showSuc = showSucursal?.checked;
                const showCli = showCliente?.checked;
                
                // Tabla
                const headerRow = document.querySelector('#ventasTable thead tr');
                if (headerRow) {
                    if (headerRow.cells[1]) headerRow.cells[1].style.display = showCli ? '' : 'none';
                    if (headerRow.cells[2]) headerRow.cells[2].style.display = showSuc ? '' : 'none';
                }
                
                document.querySelectorAll('#ventasTable tbody tr').forEach(function(row) {
                    if (row.cells[1]) row.cells[1].style.display = showCli ? '' : 'none';
                    if (row.cells[2]) row.cells[2].style.display = showSuc ? '' : 'none';
                });
                
                // Tarjetas móviles
                document.querySelectorAll('#mobileVentas .cliente-badge').forEach(function(el) {
                    el.style.display = showCli ? '' : 'none';
                });
                document.querySelectorAll('#mobileVentas .sucursal-info').forEach(function(el) {
                    el.style.display = showSuc ? '' : 'none';
                });
            }
            
            if (showSucursal) showSucursal.addEventListener('change', toggleColumnas);
            if (showCliente) showCliente.addEventListener('change', toggleColumnas);
            toggleColumnas();

            // Cerrar sidebar con ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && isOpen) closeSidebar();
            });
        });
    </script>
</body>

</html>
