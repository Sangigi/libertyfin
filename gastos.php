<?php
// gastos.php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

$mensaje = '';
$tipo_mensaje = '';

$registros_por_pagina = 15;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Categorías predefinidas para gastos manuales (además de "Costo de venta", que es automática)
$categorias_gasto = [
    'Renta', 'Nómina', 'Servicios (luz, agua, internet)', 'Mantenimiento',
    'Insumos', 'Transporte', 'Marketing', 'Impuestos', 'Otros'
];

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // === Obtener plan/colores/logo de la empresa (igual que en otras páginas) ===
    $conn_main = getDBConnection();
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
    $_SESSION['empresa_plan'] = $empresa_plan;

    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch(PDO::FETCH_ASSOC);

    $logo_empresa = null;
    $logo_src_base64 = null;
    if (!empty($empresa_info['logo'])) {
        $empresa_logo = $empresa_info['logo'];
        $logo_path = '';
        $rutas_posibles = [
            $empresa_logo, '../' . $empresa_logo, '../../' . $empresa_logo,
            'admin/' . $empresa_logo, '../admin/' . $empresa_logo,
            'logos/' . $empresa_logo, 'img/' . $empresa_logo, 'images/' . $empresa_logo,
            'assets/' . $empresa_logo, 'uploads/' . $empresa_logo,
            '../logos/' . $empresa_logo, '../img/' . $empresa_logo,
            '../images/' . $empresa_logo, '../assets/' . $empresa_logo, '../uploads/' . $empresa_logo
        ];
        foreach ($rutas_posibles as $ruta) {
            if (file_exists($ruta) && is_file($ruta)) {
                $logo_path = $ruta;
                break;
            }
        }
        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
    }

    $color_primario = $empresa_info['color_primario'] ?? '#27ae60';
    $color_secundario = $empresa_info['color_secundario'] ?? '#2ecc71';

    // Sucursales para filtro/formulario
    $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre";
    $result_sucursales = $conn->query($sql_sucursales);
    $sucursales = $result_sucursales->fetchAll(PDO::FETCH_ASSOC);

    // === Procesar acciones POST: guardar (nuevo/editar) y eliminar gasto manual ===
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_gasto') {
            $gasto_id = isset($_POST['gasto_id']) ? (int)$_POST['gasto_id'] : 0;
            $concepto = trim($_POST['concepto'] ?? '');
            $categoria = trim($_POST['categoria'] ?? '') ?: 'Otros';
            $monto = isset($_POST['monto']) ? (float)str_replace(',', '', $_POST['monto']) : 0;
            $fecha = trim($_POST['fecha'] ?? '') ?: date('Y-m-d H:i:s');
            $sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
            $metodo_pago = trim($_POST['metodo_pago'] ?? '') ?: null;
            $descripcion = trim($_POST['descripcion'] ?? '') ?: null;
            $proveedor = trim($_POST['proveedor'] ?? '') ?: null;
            $numero_referencia = trim($_POST['numero_referencia'] ?? '') ?: null;

            if ($concepto === '' || $monto <= 0) {
                $mensaje = 'El concepto y el monto (mayor a 0) son obligatorios.';
                $tipo_mensaje = 'danger';
            } else {
                if ($gasto_id > 0) {
                    // Editar: solo se permite editar gastos manuales
                    $sql = "UPDATE gastos SET concepto = ?, categoria = ?, monto = ?, fecha = ?, sucursal_id = ?, metodo_pago = ?, descripcion = ?, proveedor = ?, numero_referencia = ?
                            WHERE id = ? AND tipo = 'manual'";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$concepto, $categoria, $monto, $fecha, $sucursal_id, $metodo_pago, $descripcion, $proveedor, $numero_referencia, $gasto_id]);
                    $mensaje = 'Gasto actualizado correctamente.';
                } else {
                    $sql = "INSERT INTO gastos (concepto, categoria, monto, tipo, origen, usuario_id, sucursal_id, metodo_pago, descripcion, fecha, proveedor, numero_referencia)
                            VALUES (?, ?, ?, 'manual', 'manual', ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$concepto, $categoria, $monto, $_SESSION['usuario_id'], $sucursal_id, $metodo_pago, $descripcion, $fecha, $proveedor, $numero_referencia]);
                    $mensaje = 'Gasto registrado correctamente.';
                }
                $tipo_mensaje = 'success';
            }
        } elseif (isset($_POST['accion']) && $_POST['accion'] === 'eliminar_gasto') {
            $gasto_id = (int)($_POST['gasto_id'] ?? 0);
            if ($gasto_id > 0) {
                // Solo se permite eliminar gastos manuales; los automáticos se eliminan junto con la venta
                $stmt = $conn->prepare("DELETE FROM gastos WHERE id = ? AND tipo = 'manual'");
                $stmt->execute([$gasto_id]);
                $mensaje = 'Gasto eliminado correctamente.';
                $tipo_mensaje = 'success';
            }
        }

        // Redirigir para evitar reenvío del formulario, conservando filtros
        $query_redirect = $_GET;
        $_SESSION['gastos_mensaje'] = $mensaje;
        $_SESSION['gastos_tipo_mensaje'] = $tipo_mensaje;
        header("Location: gastos.php" . (!empty($query_redirect) ? '?' . http_build_query($query_redirect) : ''));
        exit();
    }

    if (isset($_SESSION['gastos_mensaje'])) {
        $mensaje = $_SESSION['gastos_mensaje'];
        $tipo_mensaje = $_SESSION['gastos_tipo_mensaje'];
        unset($_SESSION['gastos_mensaje'], $_SESSION['gastos_tipo_mensaje']);
    }

    // === Filtros ===
    $filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
    $filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';
    $filtro_categoria = $_GET['categoria'] ?? '';
    $filtro_tipo = $_GET['tipo'] ?? '';
    $filtro_sucursal = $_GET['sucursal'] ?? '';
    $filtro_orden = $_GET['orden'] ?? 'desc';
    if (!in_array($filtro_orden, ['asc', 'desc'])) {
        $filtro_orden = 'desc';
    }

    $where_conditions = [];
    $params = [];

    if (!empty($filtro_fecha_desde)) {
        $where_conditions[] = "DATE(g.fecha) >= ?";
        $params[] = $filtro_fecha_desde;
    }
    if (!empty($filtro_fecha_hasta)) {
        $where_conditions[] = "DATE(g.fecha) <= ?";
        $params[] = $filtro_fecha_hasta;
    }
    if (!empty($filtro_categoria)) {
        $where_conditions[] = "g.categoria = ?";
        $params[] = $filtro_categoria;
    }
    if (!empty($filtro_tipo)) {
        $where_conditions[] = "g.tipo = ?";
        $params[] = $filtro_tipo;
    }
    if (!empty($filtro_sucursal)) {
        $where_conditions[] = "g.sucursal_id = ?";
        $params[] = $filtro_sucursal;
    }

    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }

    // Total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM gastos g $where_clause";
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->execute($params);
    $total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
    $stmt_count = null;

    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    $sql_gastos = "
        SELECT g.*, s.nombre as sucursal_nombre, u.nombre as usuario_nombre, v.codigo_venta
        FROM gastos g
        LEFT JOIN sucursales s ON g.sucursal_id = s.id
        LEFT JOIN usuarios u ON g.usuario_id = u.id
        LEFT JOIN ventas v ON g.venta_id = v.id
        $where_clause
        ORDER BY g.fecha $filtro_orden, g.id $filtro_orden
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql_gastos);
    $all_params = $params;
    $all_params[] = $registros_por_pagina;
    $all_params[] = $offset;
    $stmt->execute($all_params);
    $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null;

    // Estadísticas del periodo filtrado (sin paginación)
    $sql_stats = "
        SELECT
            COUNT(*) as total_gastos,
            COALESCE(SUM(monto), 0) as monto_total,
            COALESCE(SUM(CASE WHEN tipo = 'automatico' THEN monto ELSE 0 END), 0) as monto_automatico,
            COALESCE(SUM(CASE WHEN tipo = 'manual' THEN monto ELSE 0 END), 0) as monto_manual
        FROM gastos g
        $where_clause
    ";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->execute($params);
    $stats_gastos = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    $stmt_stats = null;

    // Categorías realmente usadas (para el filtro, incluye "Costo de venta")
    $sql_categorias_usadas = "SELECT DISTINCT categoria FROM gastos ORDER BY categoria";
    $categorias_usadas = $conn->query($sql_categorias_usadas)->fetchAll(PDO::FETCH_COLUMN);

    $conn = null;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

function safe_html_gasto($value) {
    return htmlspecialchars($value ?? '');
}

$is_admin = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Gastos - <?php echo safe_html_gasto($_SESSION['empresa_nombre'] ?? ''); ?></title>
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
    <style>
        .stat-card {
            border-radius: 12px;
            padding: 20px;
            color: #fff;
            height: 100%;
        }
        .stat-card .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        .stat-card.bg-total { background: linear-gradient(135deg, #6c5ce7, #a29bfe); }
        .stat-card.bg-auto { background: linear-gradient(135deg, #e17055, #fab1a0); }
        .stat-card.bg-manual { background: linear-gradient(135deg, #00b894, #55efc4); color: #063d33; }
        .stat-card.bg-count { background: linear-gradient(135deg, #0984e3, #74b9ff); }

        .badge-tipo-automatico {
            background-color: #ffeaa7;
            color: #b7791f;
        }
        .badge-tipo-manual {
            background-color: #dfe6e9;
            color: #2d3436;
        }
        .gasto-mobile-card {
            border-radius: 10px;
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
                        <span class="d-none d-sm-inline"><?php echo safe_html_gasto($_SESSION['usuario_nombre'] ?? ''); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo safe_html_gasto($_SESSION['empresa_nombre'] ?? ''); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo safe_html_gasto($_SESSION['usuario_rol'] ?? ''); ?></small>
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
                            <a class="nav-link active" href="gastos.php">
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
                            <a class="nav-link active" href="gastos.php">
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
                            <i class="fas fa-money-bill-wave me-2"></i>
                            Gastos
                        </h2>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalGasto" onclick="prepararNuevoGasto()">
                            <i class="fas fa-plus me-1"></i>Nuevo Gasto
                        </button>
                    </div>

                    <?php if (!empty($mensaje)): ?>
                        <div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                            <?php echo safe_html_gasto($mensaje); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Tarjetas de estadísticas -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-lg-3">
                            <div class="stat-card bg-total">
                                <div class="stat-label"><i class="fas fa-wallet me-1"></i>Total del periodo</div>
                                <div class="stat-value">$<?php echo number_format($stats_gastos['monto_total'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="stat-card bg-auto">
                                <div class="stat-label"><i class="fas fa-robot me-1"></i>Automáticos (costo de venta)</div>
                                <div class="stat-value">$<?php echo number_format($stats_gastos['monto_automatico'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="stat-card bg-manual">
                                <div class="stat-label"><i class="fas fa-hand-holding-usd me-1"></i>Manuales</div>
                                <div class="stat-value">$<?php echo number_format($stats_gastos['monto_manual'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="stat-card bg-count">
                                <div class="stat-label"><i class="fas fa-list me-1"></i># Registros</div>
                                <div class="stat-value"><?php echo (int)$stats_gastos['total_gastos']; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" id="filtrosForm">
                                <input type="hidden" name="pagina" value="1">
                                <div class="row g-3">
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small">Fecha Desde</label>
                                        <input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?php echo safe_html_gasto($filtro_fecha_desde); ?>">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small">Fecha Hasta</label>
                                        <input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?php echo safe_html_gasto($filtro_fecha_hasta); ?>">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small">Categoría</label>
                                        <select class="form-select form-select-sm" name="categoria">
                                            <option value="">Todas</option>
                                            <?php foreach ($categorias_usadas as $cat): ?>
                                                <option value="<?php echo safe_html_gasto($cat); ?>" <?php echo $filtro_categoria === $cat ? 'selected' : ''; ?>>
                                                    <?php echo safe_html_gasto($cat); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small">Tipo</label>
                                        <select class="form-select form-select-sm" name="tipo">
                                            <option value="">Todos</option>
                                            <option value="manual" <?php echo $filtro_tipo === 'manual' ? 'selected' : ''; ?>>Manual</option>
                                            <option value="automatico" <?php echo $filtro_tipo === 'automatico' ? 'selected' : ''; ?>>Automático</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small">Sucursal</label>
                                        <select class="form-select form-select-sm" name="sucursal">
                                            <option value="">Todas</option>
                                            <?php foreach ($sucursales as $sucursal): ?>
                                                <option value="<?php echo $sucursal['id']; ?>" <?php echo $filtro_sucursal == $sucursal['id'] ? 'selected' : ''; ?>>
                                                    <?php echo safe_html_gasto($sucursal['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small">Orden</label>
                                        <select class="form-select form-select-sm" name="orden">
                                            <option value="desc" <?php echo $filtro_orden === 'desc' ? 'selected' : ''; ?>>Más reciente primero</option>
                                            <option value="asc" <?php echo $filtro_orden === 'asc' ? 'selected' : ''; ?>>Más antiguo primero</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                                        <a href="gastos.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Tabla desktop -->
                    <div class="card d-none d-md-block">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="gastosTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Concepto</th>
                                            <th>Proveedor</th>
                                            <th>Categoría</th>
                                            <th>Tipo</th>
                                            <th>Sucursal</th>
                                            <th>Monto</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($gastos)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-5 text-muted">
                                                    <i class="fas fa-receipt fa-3x mb-3 d-block"></i>
                                                    No hay gastos registrados con estos filtros.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($gastos as $gasto): ?>
                                                <tr>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($gasto['fecha'])); ?></td>
                                                    <td>
                                                        <div class="fw-bold"><?php echo safe_html_gasto($gasto['concepto']); ?></div>
                                                        <?php if (!empty($gasto['codigo_venta'])): ?>
                                                            <small class="text-muted">Venta: <?php echo safe_html_gasto($gasto['codigo_venta']); ?></small>
                                                        <?php endif; ?>
                                                        <?php if (!empty($gasto['numero_referencia'])): ?>
                                                            <small class="text-muted d-block">Ref: <?php echo safe_html_gasto($gasto['numero_referencia']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo safe_html_gasto($gasto['proveedor'] ?? '-'); ?></td>
                                                    <td><?php echo safe_html_gasto($gasto['categoria']); ?></td>
                                                    <td>
                                                        <?php if ($gasto['tipo'] === 'automatico'): ?>
                                                            <span class="badge badge-tipo-automatico">Automático</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-tipo-manual">Manual</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo safe_html_gasto($gasto['sucursal_nombre'] ?? '-'); ?></td>
                                                    <td class="fw-bold text-danger">-$<?php echo number_format($gasto['monto'], 2); ?></td>
                                                    <td class="text-end">
                                                        <?php if ($gasto['tipo'] === 'manual'): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='prepararEditarGasto(<?php echo json_encode($gasto); ?>)'>
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmarEliminarGasto(<?php echo (int)$gasto['id']; ?>)">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted small" title="Los gastos automáticos se eliminan junto con su venta">
                                                                <i class="fas fa-lock"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Cards móvil -->
                    <div class="d-md-none" id="gastosMobile">
                        <?php if (empty($gastos)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-receipt fa-3x mb-3 d-block"></i>
                                No hay gastos registrados con estos filtros.
                            </div>
                        <?php else: ?>
                            <?php foreach ($gastos as $gasto): ?>
                                <div class="card gasto-mobile-card mb-3">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <h6 class="fw-bold mb-0"><?php echo safe_html_gasto($gasto['concepto']); ?></h6>
                                            <?php if ($gasto['tipo'] === 'automatico'): ?>
                                                <span class="badge badge-tipo-automatico">Automático</span>
                                            <?php else: ?>
                                                <span class="badge badge-tipo-manual">Manual</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-muted small mb-1"><?php echo date('d/m/Y H:i', strtotime($gasto['fecha'])); ?> · <?php echo safe_html_gasto($gasto['categoria']); ?></p>
                                        <?php if (!empty($gasto['proveedor'])): ?>
                                            <p class="text-muted small mb-1">Proveedor: <?php echo safe_html_gasto($gasto['proveedor']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($gasto['numero_referencia'])): ?>
                                            <p class="text-muted small mb-1">Ref: <?php echo safe_html_gasto($gasto['numero_referencia']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($gasto['codigo_venta'])): ?>
                                            <p class="text-muted small mb-1">Venta: <?php echo safe_html_gasto($gasto['codigo_venta']); ?></p>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between align-items-center mt-2">
                                            <span class="fw-bold text-danger">-$<?php echo number_format($gasto['monto'], 2); ?></span>
                                            <?php if ($gasto['tipo'] === 'manual'): ?>
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick='prepararEditarGasto(<?php echo json_encode($gasto); ?>)'>
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmarEliminarGasto(<?php echo (int)$gasto['id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Paginación -->
                    <?php if ($total_paginas > 1): ?>
                        <nav class="mt-4">
                            <ul class="pagination justify-content-center flex-wrap">
                                <?php if ($pagina_actual > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>"><i class="fas fa-chevron-left"></i></a></li>
                                <?php endif; ?>
                                <?php
                                $inicio = max(1, $pagina_actual - 2);
                                $fin = min($total_paginas, $pagina_actual + 2);
                                for ($i = $inicio; $i <= $fin; $i++): ?>
                                    <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($pagina_actual < $total_paginas): ?>
                                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>"><i class="fas fa-chevron-right"></i></a></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Nuevo/Editar Gasto -->
    <div class="modal fade" id="modalGasto" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="gastos.php<?php echo !empty($_GET) ? '?' . http_build_query($_GET) : ''; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalGastoTitulo">Nuevo Gasto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="guardar_gasto">
                        <input type="hidden" name="gasto_id" id="gasto_id" value="">
                        <div class="mb-3">
                            <label class="form-label">Concepto *</label>
                            <input type="text" class="form-control" name="concepto" id="gasto_concepto" required maxlength="255">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Categoría</label>
                                <input type="text" class="form-control" name="categoria" id="gasto_categoria" list="listaCategorias" placeholder="Ej. Renta">
                                <datalist id="listaCategorias">
                                    <?php foreach ($categorias_gasto as $cat): ?>
                                        <option value="<?php echo safe_html_gasto($cat); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Monto *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control" name="monto" id="gasto_monto" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Fecha</label>
                                <input type="datetime-local" class="form-control" name="fecha" id="gasto_fecha">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Sucursal</label>
                                <select class="form-select" name="sucursal_id" id="gasto_sucursal">
                                    <option value="">Sin especificar</option>
                                    <?php foreach ($sucursales as $sucursal): ?>
                                        <option value="<?php echo $sucursal['id']; ?>"><?php echo safe_html_gasto($sucursal['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Método de pago</label>
                            <select class="form-select" name="metodo_pago" id="gasto_metodo_pago">
                                <option value="">Sin especificar</option>
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Proveedor</label>
                                <input type="text" class="form-control" name="proveedor" id="gasto_proveedor" maxlength="150" placeholder="Ej. CFE, Telmex...">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Referencia / Folio</label>
                                <input type="text" class="form-control" name="numero_referencia" id="gasto_numero_referencia" maxlength="100" placeholder="Ej. Factura F-1234">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" name="descripcion" id="gasto_descripcion" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Formulario oculto para eliminar -->
    <form method="POST" action="gastos.php<?php echo !empty($_GET) ? '?' . http_build_query($_GET) : ''; ?>" id="formEliminarGasto" style="display:none;">
        <input type="hidden" name="accion" value="eliminar_gasto">
        <input type="hidden" name="gasto_id" id="eliminar_gasto_id" value="">
    </form>
    <!-- Bootstrap JS -->
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
        });

        // Preparar modal para nuevo gasto
        function prepararNuevoGasto() {
            document.getElementById('modalGastoTitulo').textContent = 'Nuevo Gasto';
            document.getElementById('gasto_id').value = '';
            document.getElementById('gasto_concepto').value = '';
            document.getElementById('gasto_categoria').value = '';
            document.getElementById('gasto_monto').value = '';
            document.getElementById('gasto_fecha').value = '';
            document.getElementById('gasto_sucursal').value = '';
            document.getElementById('gasto_metodo_pago').value = '';
            document.getElementById('gasto_descripcion').value = '';
            document.getElementById('gasto_proveedor').value = '';
            document.getElementById('gasto_numero_referencia').value = '';
        }

        // Preparar modal para editar un gasto manual existente
        function prepararEditarGasto(gasto) {
            document.getElementById('modalGastoTitulo').textContent = 'Editar Gasto';
            document.getElementById('gasto_id').value = gasto.id;
            document.getElementById('gasto_concepto').value = gasto.concepto || '';
            document.getElementById('gasto_categoria').value = gasto.categoria || '';
            document.getElementById('gasto_monto').value = gasto.monto || '';

            if (gasto.fecha) {
                // Convertir 'YYYY-MM-DD HH:MM:SS' a formato datetime-local 'YYYY-MM-DDTHH:MM'
                const fecha = gasto.fecha.replace(' ', 'T').substring(0, 16);
                document.getElementById('gasto_fecha').value = fecha;
            } else {
                document.getElementById('gasto_fecha').value = '';
            }

            document.getElementById('gasto_sucursal').value = gasto.sucursal_id || '';
            document.getElementById('gasto_metodo_pago').value = gasto.metodo_pago || '';
            document.getElementById('gasto_descripcion').value = gasto.descripcion || '';
            document.getElementById('gasto_proveedor').value = gasto.proveedor || '';
            document.getElementById('gasto_numero_referencia').value = gasto.numero_referencia || '';

            const modal = new bootstrap.Modal(document.getElementById('modalGasto'));
            modal.show();
        }

        // Confirmar y eliminar un gasto manual
        function confirmarEliminarGasto(id) {
            if (confirm('¿Eliminar este gasto? Esta acción no se puede deshacer.')) {
                document.getElementById('eliminar_gasto_id').value = id;
                document.getElementById('formEliminarGasto').submit();
            }
        }
    </script>
</body>

</html>
