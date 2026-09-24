<?php
//caja_historial.php
session_start();

require_once __DIR__ . '/config/database.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Valores por defecto (para que el sidebar nunca falle)
$empresa_plan        = "prueba";
$timbres_totales     = 0;
$timbres_disponibles = 0;
$terminal_emida      = null;   // <-- FALTABA
$notification_status = null;   // <-- FALTABA

try {
    // ============================================
    // OBTENER EL PLAN DE LA EMPRESA (BD principal)
    // ============================================
    $pdo_main = getDBConnection();

    $stmt = $pdo_main->prepare("SELECT plan, timbres_totales, timbres_disponibles, terminal_emida 
                                FROM empresas WHERE id = ?");
    $stmt->execute([$_SESSION['empresa_id']]);
    $empresa_data = $stmt->fetch();

    if ($empresa_data) {
        $empresa_plan        = $empresa_data['plan'];
        $timbres_totales     = $empresa_data['timbres_totales'] ?? 0;
        $timbres_disponibles = $empresa_data['timbres_disponibles'] ?? 0;
        $terminal_emida      = $empresa_data['terminal_emida'] ?? null;
    }

    // Notificaciones Emida (igual que dashboard.php)
    if (file_exists(__DIR__ . '/../EmidaServicios/config.php')) {
        require_once __DIR__ . '/../EmidaServicios/config.php';
        if (function_exists('getNotificationStatus')) {
            $notification_status = getNotificationStatus($pdo_main);
        }
    }

    // Guardar el plan en la sesión
    $_SESSION['empresa_plan'] = $empresa_plan;

    // ============================================
    // CONECTAR A LA BD DE LA EMPRESA
    // ============================================
    $pdo = getEmpresaDBConnection($_SESSION['empresa_db']);

    // ============================================
    // INFORMACIÓN DE LA EMPRESA Y COLORES
    // ============================================
    $stmt = $pdo->query("SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1");
    $empresa_info = $stmt->fetch() ?: [];

    // OBTENER LOGO DE LA EMPRESA
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

    // Colores por defecto
    $color_primario = $empresa_info['color_primario'] ?? '#27ae60';
    $color_secundario = $empresa_info['color_secundario'] ?? '#2ecc71';

    // Convertir color hexadecimal a RGB
    function hexToRgb($hex)
    {
        $hex = str_replace("#", "", $hex);
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "$r, $g, $b";
    }

    $color_primario_rgb = hexToRgb($color_primario);

    // ============================================
    // LISTA DE USUARIOS PARA EL FILTRO
    // ============================================
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE sucursal_id = ? ORDER BY nombre");
    $stmt->execute([$_SESSION['sucursal_id']]);
    $usuarios = $stmt->fetchAll();

    // ============================================
    // CONFIGURACIÓN DE PAGINACIÓN
    // ============================================
    $registros_por_pagina = 10;
    $pagina_actual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) && $_GET['pagina'] > 0
        ? (int)$_GET['pagina']
        : 1;
    $offset = ($pagina_actual - 1) * $registros_por_pagina;

    // ============================================
    // CONSTRUIR CONDICIONES WHERE CON FILTROS
    // ============================================
    $where_sql = " WHERE c.sucursal_id = ?";
    $params = [$_SESSION['sucursal_id']];

    if (isset($_GET['fecha_desde']) && !empty($_GET['fecha_desde'])) {
        $where_sql .= " AND DATE(c.fecha_apertura) >= ?";
        $params[] = $_GET['fecha_desde'];
    }

    if (isset($_GET['fecha_hasta']) && !empty($_GET['fecha_hasta'])) {
        $where_sql .= " AND DATE(c.fecha_apertura) <= ?";
        $params[] = $_GET['fecha_hasta'];
    }

    if (isset($_GET['usuario']) && !empty($_GET['usuario'])) {
        $where_sql .= " AND c.usuario_id = ?";
        $params[] = $_GET['usuario'];
    }

    if (isset($_GET['estado']) && !empty($_GET['estado'])) {
        $where_sql .= " AND c.estado = ?";
        $params[] = $_GET['estado'];
    }

    // ============================================
    // 1) CONTAR TOTAL DE REGISTROS
    // ============================================
    $sql_count = "SELECT COUNT(*) as total 
                  FROM caja c 
                  JOIN usuarios u ON c.usuario_id = u.id 
                  JOIN sucursales s ON c.sucursal_id = s.id 
                  $where_sql";

    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_registros = (int)$stmt_count->fetch()['total'];

    $total_paginas = $total_registros > 0 ? (int)ceil($total_registros / $registros_por_pagina) : 1;

    // Ajustar página si excede el total
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // ============================================
    // 2) OBTENER REGISTROS DE LA PÁGINA ACTUAL
    // ============================================
    $sql = "SELECT c.*, u.nombre as usuario_nombre, s.nombre as sucursal_nombre 
            FROM caja c 
            JOIN usuarios u ON c.usuario_id = u.id 
            JOIN sucursales s ON c.sucursal_id = s.id 
            $where_sql
            ORDER BY c.fecha_apertura DESC 
            LIMIT " . (int)$registros_por_pagina . " OFFSET " . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cajas_data = $stmt->fetchAll();

    // ============================================
    // 3) ESTADÍSTICAS GLOBALES (sobre todos los filtrados)
    // ============================================
    $sql_stats = "SELECT 
                    SUM(CASE WHEN c.estado = 'abierta' THEN 1 ELSE 0 END) as abiertas,
                    SUM(CASE WHEN c.estado = 'cerrada' THEN 1 ELSE 0 END) as cerradas,
                    SUM(CASE WHEN c.estado = 'abierta' AND c.usuario_id = ? THEN 1 ELSE 0 END) as mi_caja
                  FROM caja c 
                  JOIN usuarios u ON c.usuario_id = u.id 
                  JOIN sucursales s ON c.sucursal_id = s.id 
                  $where_sql";

    $params_stats = array_merge([$_SESSION['usuario_id']], $params);
    $stmt_stats = $pdo->prepare($sql_stats);
    $stmt_stats->execute($params_stats);
    $stats = $stmt_stats->fetch();

    $cajas_abiertas = (int)($stats['abiertas'] ?? 0);
    $cajas_cerradas = (int)($stats['cerradas'] ?? 0);
    $mi_caja_abierta = ((int)($stats['mi_caja'] ?? 0)) > 0;

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Caja - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tema unificado LibertyFin -->
    <link rel="stylesheet" href="css/crm-theme.css">

    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --primary-rgb: <?php echo $color_primario_rgb; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }

        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card {
            border-left: 4px solid var(--primary-color, #27ae60);
            transition: box-shadow 0.2s ease;
        }

        .stat-card.filter-active {
            border-left: 4px solid #ffc107;
            box-shadow: 0 0 0 2px rgba(255, 193, 7, 0.15);
        }

        /* PAGINACIÓN */
        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e9ecef;
        }

        .pagination-info {
            font-size: 0.875rem;
            color: #6c757d;
        }

        .pagination .page-link {
            color: var(--primary-color, #27ae60);
            border-color: #e9ecef;
            border-radius: 8px;
            margin: 0 2px;
            min-width: 38px;
            text-align: center;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .pagination .page-link:hover {
            background-color: rgba(var(--primary-rgb, 39, 174, 96), 0.1);
            color: var(--primary-color, #27ae60);
            border-color: rgba(var(--primary-rgb, 39, 174, 96), 0.3);
        }

        .pagination .page-item.active .page-link {
            background-color: var(--primary-color, #27ae60);
            border-color: var(--primary-color, #27ae60);
            color: #fff;
            box-shadow: 0 2px 6px rgba(var(--primary-rgb, 39, 174, 96), 0.3);
        }

        .pagination .page-item.disabled .page-link {
            color: #adb5bd;
            background-color: #f8f9fa;
        }

        /* VISTA MÓVIL: TARJETAS */
        @media (max-width: 767.98px) {

            .caja-card-mobile {
                background: #ffffff;
                border-radius: 12px;
                border: 1px solid #e9ecef;
                border-left: 4px solid var(--primary-color, #27ae60);
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
                overflow: hidden;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }

            .caja-card-mobile:active {
                transform: scale(0.99);
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
            }

            .caja-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding: 12px 14px;
                background: linear-gradient(135deg,
                        rgba(var(--primary-rgb, 39, 174, 96), 0.06) 0%,
                        rgba(var(--primary-rgb, 39, 174, 96), 0.02) 100%);
                border-bottom: 1px solid #f1f3f5;
            }

            .caja-card-fecha {
                font-weight: 700;
                font-size: 0.95rem;
                color: #2c3e50;
            }

            .caja-card-hora {
                font-size: 0.8rem;
                color: #6c757d;
                margin-top: 2px;
            }

            .caja-card-usuario {
                display: flex;
                align-items: center;
                padding: 10px 14px;
                border-bottom: 1px dashed #f1f3f5;
            }

            .caja-card-usuario i {
                font-size: 1.6rem;
                color: var(--primary-color, #27ae60);
                opacity: 0.7;
            }

            .caja-card-usuario .fw-semibold {
                font-size: 0.9rem;
                color: #2c3e50;
            }

            .caja-card-usuario small {
                font-size: 0.75rem;
            }

            .caja-card-montos {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1px;
                background: #f1f3f5;
                padding: 1px;
            }

            .caja-monto-item {
                background: #ffffff;
                padding: 10px 12px;
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .caja-monto-label {
                font-size: 0.7rem;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                color: #6c757d;
                font-weight: 600;
            }

            .caja-monto-value {
                font-size: 0.95rem;
                font-weight: 600;
                color: #2c3e50;
            }

            .caja-card-actions {
                display: flex;
                gap: 8px;
                padding: 12px 14px;
                background: #fafbfc;
                border-top: 1px solid #f1f3f5;
            }

            .caja-card-actions .btn {
                font-size: 0.85rem;
                font-weight: 600;
                padding: 8px 10px;
                border-radius: 8px;
            }

            .table-responsive {
                display: none !important;
            }

            .d-flex.justify-content-between.flex-wrap.flex-md-nowrap h1.h2 {
                font-size: 1.15rem;
            }

            .d-flex.justify-content-between.flex-wrap.flex-md-nowrap .btn-toolbar {
                margin-top: 10px;
                width: 100%;
            }

            .d-flex.justify-content-between.flex-wrap.flex-md-nowrap .btn-toolbar .btn {
                width: 100%;
            }

            .row.mb-4>.col-md-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            main.px-md-4 {
                padding-left: 12px !important;
                padding-right: 12px !important;
            }

            .pagination-wrapper {
                flex-direction: column;
                text-align: center;
            }

            .pagination .page-link {
                min-width: 34px;
                padding: 6px 8px;
                font-size: 0.8rem;
            }
        }

        @media (min-width: 768px) {
            .caja-card-mobile {
                display: none;
            }
        }
    </style>
</head>

<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-history me-2"></i>Historial de Cortes de Caja
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="dashboard.php" class="btn btn-secondary me-2">
                            <i class="fas fa-arrow-left me-1"></i>Volver al Dashboard
                        </a>
                    </div>
                </div>

                <!-- Alertas -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success'];
                                                                unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error'];
                                                                        unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                        </h5>
                        <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                            <span class="badge bg-primary">Filtros activos</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3 col-6">
                                <label for="fecha_desde" class="form-label">Fecha Desde</label>
                                <input type="date" class="form-control" id="fecha_desde" name="fecha_desde"
                                    value="<?php echo isset($_GET['fecha_desde']) ? htmlspecialchars($_GET['fecha_desde']) : ''; ?>">
                            </div>
                            <div class="col-md-3 col-6">
                                <label for="fecha_hasta" class="form-label">Fecha Hasta</label>
                                <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta"
                                    value="<?php echo isset($_GET['fecha_hasta']) ? htmlspecialchars($_GET['fecha_hasta']) : ''; ?>">
                            </div>
                            <div class="col-md-3 col-6">
                                <label for="usuario" class="form-label">Usuario</label>
                                <select class="form-select" id="usuario" name="usuario">
                                    <option value="">Todos los usuarios</option>
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <option value="<?php echo $usuario['id']; ?>"
                                            <?php echo (isset($_GET['usuario']) && $_GET['usuario'] == $usuario['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($usuario['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 col-6">
                                <label for="estado" class="form-label">Estado</label>
                                <select class="form-select" id="estado" name="estado">
                                    <option value="">Todos</option>
                                    <option value="abierta" <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'abierta') ? 'selected' : ''; ?>>Abierta</option>
                                    <option value="cerrada" <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'cerrada') ? 'selected' : ''; ?>>Cerrada</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i>Filtrar
                                </button>
                                <a href="caja_historial.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo me-1"></i>Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Estadísticas rápidas -->
                <div class="row mb-4 g-2">
                    <div class="col-md-3 col-6">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])) ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Cortes</div>
                                        <div class="metric-value text-primary"><?php echo $total_registros; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-cash-register fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'abierta') ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Cajas Abiertas</div>
                                        <div class="metric-value text-warning"><?php echo $cajas_abiertas; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-lock-open fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'cerrada') ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Cajas Cerradas</div>
                                        <div class="metric-value text-success"><?php echo $cajas_cerradas; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-lock fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Mi Caja</div>
                                        <div class="metric-value text-info"><?php echo $mi_caja_abierta ? 'Abierta' : 'Cerrada'; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user fa-2x text-info opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de historial -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Registros de Cortes de Caja
                        </h5>
                        <span class="badge bg-primary"><?php echo $total_registros; ?> registros</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($cajas_data) > 0): ?>

                            <!-- VISTA DESKTOP: Tabla -->
                            <div class="table-responsive d-none d-md-block">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Usuario</th>
                                            <th>Apertura</th>
                                            <th>Cierre</th>
                                            <th>Ventas Total</th>
                                            <th>Diferencia</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cajas_data as $caja): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo date('d/m/Y', strtotime($caja['fecha_apertura'])); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($caja['fecha_apertura'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($caja['usuario_nombre']); ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($caja['sucursal_nombre']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-success">$<?php echo number_format($caja['monto_apertura'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($caja['monto_cierre']): ?>
                                                        <span class="fw-bold text-primary">$<?php echo number_format($caja['monto_cierre'], 2); ?></span>
                                                        <br>
                                                        <small class="text-muted"><?php echo $caja['fecha_cierre'] ? date('H:i', strtotime($caja['fecha_cierre'])) : '-'; ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-bold">$<?php echo number_format($caja['total_ventas'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($caja['diferencia']): ?>
                                                        <span class="badge bg-<?php
                                                                                if ($caja['diferencia'] > 0) echo 'success';
                                                                                elseif ($caja['diferencia'] < 0) echo 'danger';
                                                                                else echo 'secondary';
                                                                                ?>">
                                                            $<?php echo number_format($caja['diferencia'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $caja['estado'] == 'abierta' ? 'success' : 'secondary'; ?>">
                                                        <i class="fas fa-<?php echo $caja['estado'] == 'abierta' ? 'lock-open' : 'lock'; ?> me-1"></i>
                                                        <?php echo ucfirst($caja['estado']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="caja_resumen.php?id=<?php echo $caja['id']; ?>"
                                                            class="btn btn-outline-primary"
                                                            title="Ver resumen detallado">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if ($caja['estado'] == 'abierta' && $caja['usuario_id'] == $_SESSION['usuario_id']): ?>
                                                            <a href="caja_cierre.php"
                                                                class="btn btn-outline-warning"
                                                                title="Cerrar caja">
                                                                <i class="fas fa-lock"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- VISTA MÓVIL: Tarjetas -->
                            <div class="d-md-none">
                                <?php foreach ($cajas_data as $caja): ?>
                                    <div class="caja-card-mobile mb-3">
                                        <div class="caja-card-header">
                                            <div>
                                                <div class="caja-card-fecha">
                                                    <i class="fas fa-calendar-day me-1"></i>
                                                    <?php echo date('d/m/Y', strtotime($caja['fecha_apertura'])); ?>
                                                </div>
                                                <div class="caja-card-hora">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('H:i', strtotime($caja['fecha_apertura'])); ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-<?php echo $caja['estado'] == 'abierta' ? 'success' : 'secondary'; ?>">
                                                <i class="fas fa-<?php echo $caja['estado'] == 'abierta' ? 'lock-open' : 'lock'; ?> me-1"></i>
                                                <?php echo ucfirst($caja['estado']); ?>
                                            </span>
                                        </div>

                                        <div class="caja-card-usuario">
                                            <i class="fas fa-user-circle me-2"></i>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($caja['usuario_nombre']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($caja['sucursal_nombre']); ?></small>
                                            </div>
                                        </div>

                                        <div class="caja-card-montos">
                                            <div class="caja-monto-item">
                                                <span class="caja-monto-label">
                                                    <i class="fas fa-arrow-up text-success me-1"></i>Apertura
                                                </span>
                                                <span class="caja-monto-value text-success">
                                                    $<?php echo number_format($caja['monto_apertura'], 2); ?>
                                                </span>
                                            </div>
                                            <div class="caja-monto-item">
                                                <span class="caja-monto-label">
                                                    <i class="fas fa-arrow-down text-primary me-1"></i>Cierre
                                                </span>
                                                <span class="caja-monto-value text-primary">
                                                    <?php if ($caja['monto_cierre']): ?>
                                                        $<?php echo number_format($caja['monto_cierre'], 2); ?>
                                                        <small class="text-muted d-block">
                                                            <?php echo $caja['fecha_cierre'] ? date('H:i', strtotime($caja['fecha_cierre'])) : '-'; ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="caja-monto-item">
                                                <span class="caja-monto-label">
                                                    <i class="fas fa-shopping-cart text-dark me-1"></i>Ventas
                                                </span>
                                                <span class="caja-monto-value fw-bold">
                                                    $<?php echo number_format($caja['total_ventas'], 2); ?>
                                                </span>
                                            </div>
                                            <div class="caja-monto-item">
                                                <span class="caja-monto-label">
                                                    <i class="fas fa-balance-scale text-secondary me-1"></i>Diferencia
                                                </span>
                                                <span class="caja-monto-value">
                                                    <?php if ($caja['diferencia']): ?>
                                                        <span class="badge bg-<?php
                                                                                if ($caja['diferencia'] > 0) echo 'success';
                                                                                elseif ($caja['diferencia'] < 0) echo 'danger';
                                                                                else echo 'secondary';
                                                                                ?>">
                                                            $<?php echo number_format($caja['diferencia'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="caja-card-actions">
                                            <a href="caja_resumen.php?id=<?php echo $caja['id']; ?>"
                                                class="btn btn-sm btn-outline-primary flex-fill">
                                                <i class="fas fa-eye me-1"></i>Ver Resumen
                                            </a>
                                            <?php if ($caja['estado'] == 'abierta' && $caja['usuario_id'] == $_SESSION['usuario_id']): ?>
                                                <a href="caja_cierre.php"
                                                    class="btn btn-sm btn-warning flex-fill">
                                                    <i class="fas fa-lock me-1"></i>Cerrar Caja
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- PAGINACIÓN -->
                            <?php if ($total_paginas > 1): ?>
                                <?php
                                $query_params = $_GET;
                                unset($query_params['pagina']);
                                $base_url = 'caja_historial.php?' . http_build_query($query_params);
                                $separator = empty($query_params) ? '' : '&';
                                ?>
                                <div class="pagination-wrapper">
                                    <div class="pagination-info">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Mostrando <strong><?php echo $offset + 1; ?></strong>
                                        a <strong><?php echo min($offset + $registros_por_pagina, $total_registros); ?></strong>
                                        de <strong><?php echo $total_registros; ?></strong> registros
                                    </div>

                                    <nav aria-label="Paginación de registros">
                                        <ul class="pagination mb-0">
                                            <!-- Primera -->
                                            <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo $pagina_actual > 1 ? $base_url . $separator . 'pagina=1' : '#'; ?>"
                                                    title="Primera">
                                                    <i class="fas fa-angle-double-left"></i>
                                                </a>
                                            </li>

                                            <!-- Anterior -->
                                            <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo $pagina_actual > 1 ? $base_url . $separator . 'pagina=' . ($pagina_actual - 1) : '#'; ?>"
                                                    title="Anterior">
                                                    <i class="fas fa-angle-left"></i>
                                                </a>
                                            </li>

                                            <!-- Números de página -->
                                            <?php
                                            $rango = 2;
                                            $inicio = max(1, $pagina_actual - $rango);
                                            $fin = min($total_paginas, $pagina_actual + $rango);

                                            if ($inicio > 1) {
                                                echo '<li class="page-item"><a class="page-link" href="' . $base_url . $separator . 'pagina=1">1</a></li>';
                                                if ($inicio > 2) {
                                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                }
                                            }

                                            for ($i = $inicio; $i <= $fin; $i++):
                                            ?>
                                                <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                    <a class="page-link" href="<?php echo $base_url . $separator . 'pagina=' . $i; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <?php
                                            if ($fin < $total_paginas) {
                                                if ($fin < $total_paginas - 1) {
                                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                }
                                                echo '<li class="page-item"><a class="page-link" href="' . $base_url . $separator . 'pagina=' . $total_paginas . '">' . $total_paginas . '</a></li>';
                                            }
                                            ?>

                                            <!-- Siguiente -->
                                            <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo $pagina_actual < $total_paginas ? $base_url . $separator . 'pagina=' . ($pagina_actual + 1) : '#'; ?>"
                                                    title="Siguiente">
                                                    <i class="fas fa-angle-right"></i>
                                                </a>
                                            </li>

                                            <!-- Última -->
                                            <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo $pagina_actual < $total_paginas ? $base_url . $separator . 'pagina=' . $total_paginas : '#'; ?>"
                                                    title="Última">
                                                    <i class="fas fa-angle-double-right"></i>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php else: ?>
                                <div class="pagination-info text-center mt-3 pt-3" style="border-top: 1px solid #e9ecef;">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Mostrando <strong><?php echo $total_registros; ?></strong> registro<?php echo $total_registros != 1 ? 's' : ''; ?>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-cash-register fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">
                                    <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                        No hay registros que coincidan con los filtros
                                    <?php else: ?>
                                        No hay registros de caja
                                    <?php endif; ?>
                                </h5>
                                <p class="text-muted mb-4">
                                    <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                        Intenta ajustar los criterios de búsqueda.
                                    <?php else: ?>
                                        No se han encontrado cortes de caja en el sistema.
                                    <?php endif; ?>
                                </p>
                                <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                    <a href="caja_historial.php" class="btn btn-outline-primary">
                                        <i class="fas fa-undo me-2"></i>Ver todos los registros
                                    </a>
                                <?php else: ?>
                                    <a href="caja_apertura.php" class="btn btn-primary">
                                        <i class="fas fa-lock-open me-2"></i>Abrir Primera Caja
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php if (!isset($_COOKIE['swipe_hint_seen']) && !isset($_SESSION['swipe_hint_seen'])): ?>
        <div class="swipe-hint d-md-none">
            <i class="fas fa-arrows-left-right me-2"></i>Desliza para abrir/cerrar menú
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            let touchStartX = 0;
            let touchEndX = 0;
            let touchStartY = 0;
            let touchEndY = 0;
            let isSwiping = false;
            let swipeThreshold = 50;
            let verticalThreshold = 30;

            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';

                const swipeHint = document.querySelector('.swipe-hint');
                if (swipeHint) {
                    swipeHint.style.display = 'none';
                    document.cookie = "swipe_hint_seen=true; max-age=86400; path=/";
                    <?php $_SESSION['swipe_hint_seen'] = true; ?>
                }
            }

            if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
            if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', toggleSidebar);

            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        toggleSidebar();
                    }
                });
            });

            function handleSwipe() {
                const distanceX = touchEndX - touchStartX;
                const distanceY = Math.abs(touchEndY - touchStartY);

                if (window.innerWidth >= 768) return false;

                if (Math.abs(distanceX) > distanceY && distanceY < verticalThreshold) {
                    if (distanceX < -swipeThreshold && sidebar.classList.contains('show')) {
                        toggleSidebar();
                        return true;
                    } else if (distanceX > swipeThreshold && !sidebar.classList.contains('show')) {
                        toggleSidebar();
                        return true;
                    }
                }
                return false;
            }

            document.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
                touchStartY = e.changedTouches[0].screenY;
                isSwiping = true;
            }, { passive: true });

            document.addEventListener('touchmove', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;

                const distanceX = Math.abs(touchEndX - touchStartX);
                const distanceY = Math.abs(touchEndY - touchStartY);

                if (distanceX > distanceY && distanceX > 10) {
                    e.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('touchend', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;

                handleSwipe();
                isSwiping = false;

                document.body.classList.remove('swipe-right', 'swipe-left');
            }, { passive: true });

            document.addEventListener('touchcancel', function() {
                isSwiping = false;
                document.body.classList.remove('swipe-right', 'swipe-left');
            }, { passive: true });

            if (sidebar) {
                sidebar.addEventListener('touchstart', function(e) {
                    touchStartX = e.touches[0].clientX;
                }, { passive: true });
            }

            // Configuración de fechas
            const today = new Date().toISOString().split('T')[0];
            const fechaDesdeInput = document.getElementById('fecha_desde');
            const fechaHastaInput = document.getElementById('fecha_hasta');

            if (fechaDesdeInput) fechaDesdeInput.max = today;
            if (fechaHastaInput) fechaHastaInput.max = today;

            if (fechaDesdeInput) {
                fechaDesdeInput.addEventListener('change', function() {
                    if (this.value && fechaHastaInput.value && this.value > fechaHastaInput.value) {
                        fechaHastaInput.value = this.value;
                    }
                });
            }

            if (fechaHastaInput) {
                fechaHastaInput.addEventListener('change', function() {
                    if (this.value && fechaDesdeInput.value && this.value < fechaDesdeInput.value) {
                        fechaDesdeInput.value = this.value;
                    }
                });
            }

            if (sidebar) {
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'class') {
                            if (sidebar.classList.contains('show')) {
                                document.body.style.overflow = 'hidden';
                            } else {
                                document.body.style.overflow = '';
                            }
                        }
                    });
                });

                observer.observe(sidebar, { attributes: true });
            }

            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768 && sidebar && sidebar.classList.contains('show')) {
                    toggleSidebar();
                }
            });

            document.addEventListener('touchmove', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                const touch = e.touches[0];
                const distanceX = touch.screenX - touchStartX;

                if (Math.abs(distanceX) > 10) {
                    if (distanceX > 0 && !sidebar.classList.contains('show')) {
                        document.body.classList.add('swipe-right');
                        document.body.classList.remove('swipe-left');
                    } else if (distanceX < 0 && sidebar.classList.contains('show')) {
                        document.body.classList.add('swipe-left');
                        document.body.classList.remove('swipe-right');
                    }
                }
            }, { passive: true });

            const swipeHint = document.querySelector('.swipe-hint');
            if (swipeHint) {
                setTimeout(function() {
                    swipeHint.style.display = 'none';
                    document.cookie = "swipe_hint_seen=true; max-age=86400; path=/";
                    <?php $_SESSION['swipe_hint_seen'] = true; ?>
                }, 5000);
            }

            document.addEventListener('click', function(e) {
                if (window.innerWidth < 768 &&
                    sidebar && sidebar.classList.contains('show') &&
                    !sidebar.contains(e.target) &&
                    sidebarToggle && !sidebarToggle.contains(e.target)) {
                    toggleSidebar();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar && sidebar.classList.contains('show')) {
                    toggleSidebar();
                }
            });

            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Paginación: evitar clicks en items deshabilitados/activos
            const paginationLinks = document.querySelectorAll('.pagination .page-link');
            paginationLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.parentElement.classList.contains('disabled') ||
                        this.parentElement.classList.contains('active')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>

</html>