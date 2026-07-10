<?php

$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

// activaciones.php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Variables para filtros
$filtro_empresa = isset($_GET['empresa']) ? intval($_GET['empresa']) : 0;
$filtro_tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$filtro_fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$filtro_fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener lista de empresas para el filtro
$empresas = [];
$sql_empresas = "SELECT id, nombre_empresa FROM empresas ORDER BY nombre_empresa";
$result_empresas = $conn->query($sql_empresas);
if ($result_empresas->num_rows > 0) {
    while ($row = $result_empresas->fetch_assoc()) {
        $empresas[] = $row;
    }
}

// Función para construir WHERE clause
function buildWhereClause($conn, $filtro_empresa, $filtro_fecha_inicio, $filtro_fecha_fin, &$params, &$types) {
    $where = [];
    
    if ($filtro_empresa > 0) {
        $where[] = "empresa_id = ?";
        $params[] = $filtro_empresa;
        $types .= "i";
    }
    
    if (!empty($filtro_fecha_inicio)) {
        $where[] = "DATE(fecha_activacion) >= ?";
        $params[] = $filtro_fecha_inicio;
        $types .= "s";
    }
    
    if (!empty($filtro_fecha_fin)) {
        $where[] = "DATE(fecha_activacion) <= ?";
        $params[] = $filtro_fecha_fin;
        $types .= "s";
    }
    
    return !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
}

// Variables para resultados
$activaciones = [];
$totales = [
    'planes' => ['count' => 0, 'total_sin_iva' => 0, 'total_con_iva' => 0],
    'timbres' => ['count' => 0, 'total_sin_iva' => 0, 'total_con_iva' => 0, 'total_timbres' => 0],
    'sucursales' => ['count' => 0, 'total_sin_iva' => 0, 'total_con_iva' => 0, 'total_sucursales' => 0]
];

try {
    // Array para almacenar todas las consultas
    $queries = [];
    $all_params = [];
    $all_types = "";
    
    // =============================================
    // CONSULTA PARA PLANES
    // =============================================
    if ($filtro_tipo == 'todos' || $filtro_tipo == 'planes') {
        $params_planes = [];
        $types_planes = "";
        $where_planes = buildWhereClause($conn, $filtro_empresa, $filtro_fecha_inicio, $filtro_fecha_fin, $params_planes, $types_planes);
        
        $sql_planes = "SELECT 
                        'plan' as tipo,
                        ap.id,
                        ap.empresa_id,
                        e.nombre_empresa,
                        ap.plan_anterior,
                        ap.plan_nuevo,
                        ap.precio_sin_iva,
                        ap.precio_con_iva,
                        ap.fecha_activacion,
                        ap.usuario_activo,
                        ap.notas,
                        NULL as cantidad,
                        NULL as timbres_anteriores,
                        NULL as timbres_nuevos,
                        NULL as sucursales_anteriores,
                        NULL as sucursales_nuevas
                       FROM activaciones_plan ap
                       INNER JOIN empresas e ON ap.empresa_id = e.id
                       $where_planes
                       ORDER BY ap.fecha_activacion DESC";
        
        $stmt = $conn->prepare($sql_planes);
        if (!empty($params_planes)) {
            $stmt->bind_param($types_planes, ...$params_planes);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $activaciones[] = $row;
            $totales['planes']['count']++;
            $totales['planes']['total_sin_iva'] += floatval($row['precio_sin_iva']);
            $totales['planes']['total_con_iva'] += floatval($row['precio_con_iva']);
        }
        $stmt->close();
    }
    
    // =============================================
    // CONSULTA PARA TIMBRES
    // =============================================
    if ($filtro_tipo == 'todos' || $filtro_tipo == 'timbres') {
        $params_timbres = [];
        $types_timbres = "";
        $where_timbres = buildWhereClause($conn, $filtro_empresa, $filtro_fecha_inicio, $filtro_fecha_fin, $params_timbres, $types_timbres);
        
        $sql_timbres = "SELECT 
                        'timbre' as tipo,
                        at.id,
                        at.empresa_id,
                        e.nombre_empresa,
                        NULL as plan_anterior,
                        NULL as plan_nuevo,
                        at.precio_sin_iva,
                        at.precio_con_iva,
                        at.fecha_activacion,
                        at.usuario_activo,
                        at.notas,
                        at.cantidad_timbres as cantidad,
                        at.timbres_anteriores,
                        at.timbres_nuevos,
                        NULL as sucursales_anteriores,
                        NULL as sucursales_nuevas
                       FROM activaciones_timbres at
                       INNER JOIN empresas e ON at.empresa_id = e.id
                       $where_timbres
                       ORDER BY at.fecha_activacion DESC";
        
        $stmt = $conn->prepare($sql_timbres);
        if (!empty($params_timbres)) {
            $stmt->bind_param($types_timbres, ...$params_timbres);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $activaciones[] = $row;
            $totales['timbres']['count']++;
            $totales['timbres']['total_sin_iva'] += floatval($row['precio_sin_iva']);
            $totales['timbres']['total_con_iva'] += floatval($row['precio_con_iva']);
            $totales['timbres']['total_timbres'] += intval($row['cantidad']);
        }
        $stmt->close();
    }
    
    // =============================================
    // CONSULTA PARA SUCURSALES
    // =============================================
    if ($filtro_tipo == 'todos' || $filtro_tipo == 'sucursales') {
        $params_sucursales = [];
        $types_sucursales = "";
        $where_sucursales = buildWhereClause($conn, $filtro_empresa, $filtro_fecha_inicio, $filtro_fecha_fin, $params_sucursales, $types_sucursales);
        
        $sql_sucursales = "SELECT 
                        'sucursal' as tipo,
                        asu.id,
                        asu.empresa_id,
                        e.nombre_empresa,
                        NULL as plan_anterior,
                        NULL as plan_nuevo,
                        asu.precio_sin_iva,
                        asu.precio_con_iva,
                        asu.fecha_activacion,
                        asu.usuario_activo,
                        asu.notas,
                        asu.sucursales_nuevas as cantidad,
                        NULL as timbres_anteriores,
                        NULL as timbres_nuevos,
                        asu.sucursales_anteriores,
                        asu.sucursales_nuevas
                       FROM activaciones_sucursales asu
                       INNER JOIN empresas e ON asu.empresa_id = e.id
                       $where_sucursales
                       ORDER BY asu.fecha_activacion DESC";
        
        $stmt = $conn->prepare($sql_sucursales);
        if (!empty($params_sucursales)) {
            $stmt->bind_param($types_sucursales, ...$params_sucursales);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $activaciones[] = $row;
            $totales['sucursales']['count']++;
            $totales['sucursales']['total_sin_iva'] += floatval($row['precio_sin_iva']);
            $totales['sucursales']['total_con_iva'] += floatval($row['precio_con_iva']);
            $totales['sucursales']['total_sucursales'] += intval($row['cantidad']);
        }
        $stmt->close();
    }
    
    // Ordenar todas las activaciones por fecha (combinadas)
    if ($filtro_tipo == 'todos' && !empty($activaciones)) {
        usort($activaciones, function($a, $b) {
            return strtotime($b['fecha_activacion']) - strtotime($a['fecha_activacion']);
        });
    }
    
} catch (Exception $e) {
    $error = "Error al cargar las activaciones: " . $e->getMessage();
}

$conn->close();

// Función para obtener el color según el tipo
function getTipoColor($tipo) {
    switch ($tipo) {
        case 'plan':
            return 'primary';
        case 'timbre':
            return 'success';
        case 'sucursal':
            return 'info';
        default:
            return 'secondary';
    }
}

// Función para obtener el icono según el tipo
function getTipoIcon($tipo) {
    switch ($tipo) {
        case 'plan':
            return 'crown';
        case 'timbre':
            return 'file-invoice';
        case 'sucursal':
            return 'store';
        default:
            return 'history';
    }
}

// Función para obtener el texto del tipo
function getTipoText($tipo) {
    switch ($tipo) {
        case 'plan':
            return 'Plan';
        case 'timbre':
            return 'Timbres';
        case 'sucursal':
            return 'Sucursales';
        default:
            return 'Otro';
    }
}

// Función para formatear moneda
function formatearMoneda($monto) {
    return '$' . number_format(floatval($monto), 2, '.', ',') . ' MXN';
}

// Función para formatear fecha
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

// Función para obtener el nombre del plan
function getPlanNombre($plan) {
    $planes = [
        'prueba' => 'Prueba',
        'basico' => 'Básico',
        'starter' => 'Starter',
        'emprendedor' => 'Emprendedor',
        'premium' => 'Premium'
    ];
    return $planes[$plan] ?? ucfirst($plan);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Activaciones - Panel de Administración</title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
            --primary-gradient: linear-gradient(135deg, #27ae60, #2ecc71);
            --secondary-gradient: linear-gradient(135deg, #3498db, #2980b9);
            --success-gradient: linear-gradient(135deg, #2ecc71, #27ae60);
            --warning-gradient: linear-gradient(135deg, #f39c12, #e67e22);
            --danger-gradient: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
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

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .card-header.bg-gradient-primary {
            background: var(--primary-gradient) !important;
        }

        .card-header.bg-gradient-success {
            background: var(--success-gradient) !important;
        }

        .card-header.bg-gradient-info {
            background: var(--secondary-gradient) !important;
        }

        .card-header.bg-gradient-warning {
            background: var(--warning-gradient) !important;
        }

        .stat-card {
            border-left: 4px solid;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateX(5px);
        }

        .stat-card.primary {
            border-left-color: var(--primary-color);
        }

        .stat-card.success {
            border-left-color: #28a745;
        }

        .stat-card.info {
            border-left-color: #17a2b8;
        }

        .badge-tipo {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(39, 174, 96, 0.05);
            cursor: pointer;
        }

        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .btn-filter {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
            color: white;
        }

        .btn-reset {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
            color: white;
        }

        .plan-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .plan-badge.prueba { background: #6c757d; color: white; }
        .plan-badge.basico { background: #17a2b8; color: white; }
        .plan-badge.starter { background: #ffc107; color: #212529; }
        .plan-badge.emprendedor { background: #fd7e14; color: white; }
        .plan-badge.premium { background: #28a745; color: white; }

        /* Estilos para DataTables en español */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            margin-bottom: 1rem;
        }

        .dataTables_wrapper .dataTables_filter input {
            margin-left: 0.5rem;
            border-radius: 0.375rem;
            border: 1px solid #dee2e6;
            padding: 0.375rem 0.75rem;
        }

        .dataTables_wrapper .dataTables_length select {
            border-radius: 0.375rem;
            border: 1px solid #dee2e6;
            padding: 0.375rem 2rem 0.375rem 0.75rem;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.375rem 0.75rem;
            margin: 0 2px;
            border-radius: 0.375rem;
            border: 1px solid #dee2e6;
            background: white;
            color: #333 !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #e9ecef;
            border-color: #dee2e6;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--primary-gradient);
            color: white !important;
            border: none;
        }

        /* Estilos para tarjetas de totales */
        .total-card {
            transition: all 0.3s ease;
        }
        
        .total-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .total-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .total-card:hover .total-icon {
            transform: scale(1.1);
        }

        /* Responsive */
        @media (max-width: 767.98px) {
            .sidebar {
                display: none;
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
            }

            .filter-section .row>div {
                margin-bottom: 15px;
            }

            .btn-filter, .btn-reset {
                width: 100%;
                margin-bottom: 10px;
            }

            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter {
                text-align: left !important;
            }

            .dataTables_wrapper .dataTables_filter input {
                width: 100%;
                margin-left: 0;
                margin-top: 0.5rem;
            }

            .dataTables_wrapper .dataTables_paginate {
                text-align: center !important;
                margin-top: 1rem;
            }
        }

        /* Animaciones */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: slideIn 0.5s ease-out;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../images/LibertyfinBlanco.png" alt="Logo" class="me-2" style="height: 30px;">
                <span>Panel de Administración</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar d-none d-md-block">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="empresas.php">
                                <i class="fas fa-building"></i>
                                Empresas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="activaciones.php">
                                <i class="fas fa-history"></i>
                                Planes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="usuarios.php">
                                <i class="fas fa-user-cog"></i>
                                Usuarios Admin
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="solicitudes.php">
                                <i class="fas fa-user-cog"></i>
                                Solicitudes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="distribuidores.php">
                                <i class="fas fa-users"></i>
                                Distribuidores
                            </a>
                        </li>
                        <!-- <li class="nav-item">
                            <a class="nav-link" href="configuracion.php">
                                <i class="fas fa-cogs"></i>
                                Configuración
                            </a>
                        </li> -->
                       <li class="nav-item">
                            <a class="nav-link" href="pagos.php">
                                <i class="fas fa-money-bill-wave"></i>
                                Pagos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ActivacionesCaracteristicas.php">
                                <i class="fas fa-sliders-h"></i>
                                Características
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Encabezado -->
                <div class="d-flex justify-content-between align-items-center mb-4 fade-in">
                    <div>
                        <h2 class="h4 mb-1">
                            <i class="fas fa-history me-2"></i>
                            Historial de Activaciones
                        </h2>
                        <p class="text-muted mb-0">
                            <small>Consulta todas las activaciones de planes, timbres y sucursales</small>
                        </p>
                    </div>
                    <div>
                        <?php if (!empty($activaciones)): ?>
                        <button class="btn btn-outline-success" onclick="exportarExcel()">
                            <i class="fas fa-file-excel me-1"></i>Exportar a Excel
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="filter-section fade-in">
                    <form method="GET" id="filterForm" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">
                                <i class="fas fa-building me-1"></i>Empresa
                            </label>
                            <select class="form-select" name="empresa">
                                <option value="0">Todas las empresas</option>
                                <?php foreach ($empresas as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php echo $filtro_empresa == $emp['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['nombre_empresa']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-filter me-1"></i>Tipo de Activación
                            </label>
                            <select class="form-select" name="tipo">
                                <option value="todos" <?php echo $filtro_tipo == 'todos' ? 'selected' : ''; ?>>Todos</option>
                                <option value="planes" <?php echo $filtro_tipo == 'planes' ? 'selected' : ''; ?>>Planes</option>
                                <option value="timbres" <?php echo $filtro_tipo == 'timbres' ? 'selected' : ''; ?>>Timbres</option>
                                <option value="sucursales" <?php echo $filtro_tipo == 'sucursales' ? 'selected' : ''; ?>>Sucursales</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-calendar-alt me-1"></i>Fecha Inicio
                            </label>
                            <input type="date" class="form-control" name="fecha_inicio" value="<?php echo $filtro_fecha_inicio; ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">
                                <i class="fas fa-calendar-alt me-1"></i>Fecha Fin
                            </label>
                            <input type="date" class="form-control" name="fecha_fin" value="<?php echo $filtro_fecha_fin; ?>">
                        </div>
                    </form>

                    <div class="row mt-3">
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-filter" onclick="document.getElementById('filterForm').submit()">
                                <i class="fas fa-search me-1"></i>Aplicar Filtros
                            </button>
                            <a href="activaciones.php" class="btn btn-reset">
                                <i class="fas fa-undo me-1"></i>Limpiar Filtros
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Tarjetas de resumen general -->
                <?php if (!empty($activaciones)): ?>
                <!-- Primera fila: Totales generales -->
                <div class="row g-4 mb-4 fade-in">
                    <!-- Total General sin IVA -->
                    <div class="col-md-3">
                        <div class="card total-card" style="border-left: 4px solid #6c757d;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">
                                            <i class="fas fa-file-invoice me-1"></i>
                                            Total sin IVA
                                        </h6>
                                        <h3 class="mb-0 text-secondary">
                                            <?php 
                                            $total_general_sin_iva = $totales['planes']['total_sin_iva'] + 
                                                                     $totales['timbres']['total_sin_iva'] + 
                                                                     $totales['sucursales']['total_sin_iva'];
                                            echo formatearMoneda($total_general_sin_iva); 
                                            ?>
                                        </h3>
                                        <small class="text-muted">
                                            Subtotal de todas las activaciones
                                        </small>
                                    </div>
                                    <div class="total-icon bg-secondary bg-opacity-10">
                                        <i class="fas fa-file-invoice text-secondary fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total General con IVA -->
                    <div class="col-md-3">
                        <div class="card total-card" style="border-left: 4px solid #28a745;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">
                                            <i class="fas fa-file-invoice-dollar me-1"></i>
                                            Total con IVA
                                        </h6>
                                        <h3 class="mb-0 text-success">
                                            <?php 
                                            $total_general_con_iva = $totales['planes']['total_con_iva'] + 
                                                                     $totales['timbres']['total_con_iva'] + 
                                                                     $totales['sucursales']['total_con_iva'];
                                            echo formatearMoneda($total_general_con_iva); 
                                            ?>
                                        </h3>
                                        <small class="text-success">
                                            Total pagado (IVA incluido)
                                        </small>
                                    </div>
                                    <div class="total-icon bg-success bg-opacity-10">
                                        <i class="fas fa-file-invoice-dollar text-success fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- IVA Total -->
                    <div class="col-md-3">
                        <div class="card total-card" style="border-left: 4px solid #17a2b8;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">
                                            <i class="fas fa-calculator me-1"></i>
                                            IVA Total
                                        </h6>
                                        <h3 class="mb-0 text-info">
                                            <?php 
                                            $iva_total = $total_general_con_iva - $total_general_sin_iva;
                                            echo formatearMoneda($iva_total); 
                                            ?>
                                        </h3>
                                        <small class="text-info">
                                            16% de IVA sobre el total
                                        </small>
                                    </div>
                                    <div class="total-icon bg-info bg-opacity-10">
                                        <i class="fas fa-calculator text-info fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Número total de activaciones -->
                    <div class="col-md-3">
                        <div class="card total-card" style="border-left: 4px solid #fd7e14;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">
                                            <i class="fas fa-chart-line me-1"></i>
                                            Total Activaciones
                                        </h6>
                                        <h3 class="mb-0 text-warning">
                                            <?php echo count($activaciones); ?>
                                        </h3>
                                        <small class="text-warning">
                                            <?php 
                                            echo $totales['planes']['count'] + $totales['timbres']['count'] + $totales['sucursales']['count']; 
                                            ?> registros
                                        </small>
                                    </div>
                                    <div class="total-icon bg-warning bg-opacity-10">
                                        <i class="fas fa-chart-line text-warning fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Segunda fila: Tarjetas por tipo -->
                <div class="row g-4 mb-4 fade-in">
                    <!-- Total Planes -->
                    <div class="col-md-4">
                        <div class="card stat-card primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">
                                            <i class="fas fa-crown me-1"></i>
                                            Planes
                                        </h6>
                                        <h4 class="mb-2 text-primary"><?php echo $totales['planes']['count']; ?> activaciones</h4>
                                        <div class="small">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="text-muted">Sin IVA:</span>
                                                <span class="fw-bold"><?php echo formatearMoneda($totales['planes']['total_sin_iva']); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted">Con IVA:</span>
                                                <span class="fw-bold text-primary"><?php echo formatearMoneda($totales['planes']['total_con_iva']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-crown text-primary fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Timbres -->
                    <div class="col-md-4">
                        <div class="card stat-card success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">
                                            <i class="fas fa-file-invoice me-1"></i>
                                            Timbres
                                        </h6>
                                        <h4 class="mb-2 text-success"><?php echo $totales['timbres']['count']; ?> activaciones</h4>
                                        <div class="small">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="text-muted">Timbres:</span>
                                                <span class="fw-bold"><?php echo $totales['timbres']['total_timbres']; ?> CFDI</span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="text-muted">Sin IVA:</span>
                                                <span class="fw-bold"><?php echo formatearMoneda($totales['timbres']['total_sin_iva']); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted">Con IVA:</span>
                                                <span class="fw-bold text-success"><?php echo formatearMoneda($totales['timbres']['total_con_iva']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-file-invoice text-success fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Sucursales -->
                    <div class="col-md-4">
                        <div class="card stat-card info">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">
                                            <i class="fas fa-store me-1"></i>
                                            Sucursales
                                        </h6>
                                        <h4 class="mb-2 text-info"><?php echo $totales['sucursales']['count']; ?> activaciones</h4>
                                        <div class="small">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="text-muted">Sucursales:</span>
                                                <span class="fw-bold"><?php echo $totales['sucursales']['total_sucursales']; ?> extra(s)</span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="text-muted">Sin IVA:</span>
                                                <span class="fw-bold"><?php echo formatearMoneda($totales['sucursales']['total_sin_iva']); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted">Con IVA:</span>
                                                <span class="fw-bold text-info"><?php echo formatearMoneda($totales['sucursales']['total_con_iva']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="bg-info bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-store text-info fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tabla de activaciones -->
                <div class="card fade-in">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-list-alt text-primary me-2"></i>
                            <h5 class="card-title mb-0">Registro de Activaciones</h5>
                            <span class="badge bg-primary ms-3"><?php echo count($activaciones); ?> registros</span>
                        </div>
                    </div>

                    <div class="card-body">
                        <?php if (empty($activaciones)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No hay activaciones registradas</h5>
                                <p class="text-muted mb-0">Los registros de activaciones aparecerán aquí cuando se realicen compras</p>
                                <?php if ($filtro_empresa > 0 || !empty($filtro_fecha_inicio) || !empty($filtro_fecha_fin) || $filtro_tipo != 'todos'): ?>
                                <p class="text-muted mt-3">
                                    <small>Intentá limpiar los filtros para ver más resultados</small>
                                </p>
                                <a href="activaciones.php" class="btn btn-outline-primary mt-2">
                                    <i class="fas fa-undo me-1"></i>Limpiar Filtros
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="activacionesTable" style="width:100%">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Empresa</th>
                                            <th>Tipo</th>
                                            <th>Detalle</th>
                                            <th class="text-end">Monto sin IVA</th>
                                            <th class="text-end">Monto con IVA</th>
                                            <th>Usuario</th>
                                            <th>Notas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activaciones as $act): ?>
                                            <tr onclick="verDetalle(<?php echo htmlspecialchars(json_encode($act)); ?>)">
                                                <td>
                                                    <span class="fw-bold"><?php echo formatearFecha($act['fecha_activacion']); ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($act['nombre_empresa']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo getTipoColor($act['tipo']); ?> badge-tipo">
                                                        <i class="fas fa-<?php echo getTipoIcon($act['tipo']); ?> me-1"></i>
                                                        <?php echo ucfirst(getTipoText($act['tipo'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($act['tipo'] == 'plan'): ?>
                                                        <div>
                                                            <?php if (!empty($act['plan_anterior'])): ?>
                                                                <span class="plan-badge <?php echo $act['plan_anterior']; ?> me-1">
                                                                    <?php echo getPlanNombre($act['plan_anterior']); ?>
                                                                </span>
                                                                <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                                            <?php endif; ?>
                                                            <span class="plan-badge <?php echo $act['plan_nuevo']; ?>">
                                                                <?php echo getPlanNombre($act['plan_nuevo']); ?>
                                                            </span>
                                                        </div>
                                                    <?php elseif ($act['tipo'] == 'timbre'): ?>
                                                        <div>
                                                            <strong><?php echo $act['cantidad']; ?> timbres</strong>
                                                            <?php if (!empty($act['timbres_anteriores'])): ?>
                                                                <br><small class="text-muted">
                                                                    Anterior: <?php echo $act['timbres_anteriores']; ?> → Nuevo: <?php echo $act['timbres_nuevos']; ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($act['tipo'] == 'sucursal'): ?>
                                                        <div>
                                                            <strong><?php echo $act['cantidad']; ?> sucursal(es)</strong>
                                                            <?php if (!empty($act['sucursales_anteriores'])): ?>
                                                                <br><small class="text-muted">
                                                                    Anterior: <?php echo $act['sucursales_anteriores']; ?> → Nuevo: <?php echo $act['sucursales_nuevas']; ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end"><?php echo formatearMoneda($act['precio_sin_iva']); ?></td>
                                                <td class="text-end">
                                                    <span class="fw-bold text-primary">
                                                        <?php echo formatearMoneda($act['precio_con_iva']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($act['usuario_activo'])): ?>
                                                        <span class="badge bg-secondary">
                                                            <i class="fas fa-user me-1"></i>
                                                            <?php echo htmlspecialchars($act['usuario_activo']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($act['notas'])): ?>
                                                        <i class="fas fa-comment text-muted" 
                                                           data-bs-toggle="tooltip" 
                                                           title="<?php echo htmlspecialchars($act['notas']); ?>"></i>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($activaciones)): ?>
                        <div class="card-footer bg-white py-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Mostrando <?php echo count($activaciones); ?> registros
                                    </small>
                                </div>
                                <div class="col-md-6 text-end">
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        Última actualización: <?php echo date('d/m/Y H:i'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal de Detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>
                        Detalle de la Activación
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="detalleContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Inicializar DataTable en español
        $(document).ready(function() {
            <?php if (!empty($activaciones)): ?>
            $('#activacionesTable').DataTable({
                responsive: true,
                language: {
                    "decimal": "",
                    "emptyTable": "No hay datos disponibles en la tabla",
                    "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
                    "infoEmpty": "Mostrando 0 a 0 de 0 registros",
                    "infoFiltered": "(filtrado de _MAX_ registros totales)",
                    "infoPostFix": "",
                    "thousands": ",",
                    "lengthMenu": "Mostrar _MENU_ registros",
                    "loadingRecords": "Cargando...",
                    "processing": "Procesando...",
                    "search": "Buscar:",
                    "zeroRecords": "No se encontraron registros coincidentes",
                    "paginate": {
                        "first": "Primero",
                        "last": "Último",
                        "next": "Siguiente",
                        "previous": "Anterior"
                    },
                    "aria": {
                        "sortAscending": ": activar para ordenar la columna ascendente",
                        "sortDescending": ": activar para ordenar la columna descendente"
                    }
                },
                order: [[0, 'desc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Todos"]],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                initComplete: function() {
                    // Personalizar el placeholder del buscador
                    $('div.dataTables_filter input').attr('placeholder', 'Buscar...');
                    
                    // Añadir clases de Bootstrap
                    $('div.dataTables_filter input').addClass('form-control form-control-sm');
                    $('div.dataTables_length select').addClass('form-select form-select-sm');
                }
            });
            <?php endif; ?>

            // Inicializar tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
        });

        // Función para ver detalle
        function verDetalle(activacion) {
            let html = '';
            
            // Información general
            html += `
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Información General</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-2"><strong>Fecha:</strong> ${activacion.fecha_activacion}</p>
                                <p class="mb-2"><strong>Empresa:</strong> ${activacion.nombre_empresa}</p>
                                <p class="mb-2"><strong>Tipo:</strong> ${activacion.tipo.charAt(0).toUpperCase() + activacion.tipo.slice(1)}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-2"><strong>Usuario:</strong> ${activacion.usuario_activo || 'No registrado'}</p>
                                <p class="mb-2"><strong>Precio sin IVA:</strong> ${formatearMoneda(activacion.precio_sin_iva)}</p>
                                <p class="mb-2"><strong>Precio con IVA:</strong> ${formatearMoneda(activacion.precio_con_iva)}</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Detalle según el tipo
            if (activacion.tipo === 'plan') {
                html += `
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0">Cambio de Plan</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 text-center border-end">
                                    <label class="text-muted d-block mb-2">Plan Anterior</label>
                                    <span class="badge ${activacion.plan_anterior ? 'bg-secondary' : 'bg-light text-muted'} p-3" style="font-size: 1.2rem;">
                                        ${activacion.plan_anterior ? activacion.plan_anterior.toUpperCase() : 'N/A'}
                                    </span>
                                </div>
                                <div class="col-md-6 text-center">
                                    <label class="text-muted d-block mb-2">Plan Nuevo</label>
                                    <span class="badge bg-success p-3" style="font-size: 1.2rem;">
                                        ${activacion.plan_nuevo.toUpperCase()}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else if (activacion.tipo === 'timbre') {
                html += `
                    <div class="card mb-3">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0">Activación de Timbres</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <h3 class="text-primary">${activacion.cantidad}</h3>
                                    <small class="text-muted">Timbres Adquiridos</small>
                                </div>
                                <div class="col-md-4">
                                    <h3 class="text-info">${activacion.timbres_anteriores || 0}</h3>
                                    <small class="text-muted">Timbres Anteriores</small>
                                </div>
                                <div class="col-md-4">
                                    <h3 class="text-success">${activacion.timbres_nuevos || 0}</h3>
                                    <small class="text-muted">Total Actual</small>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else if (activacion.tipo === 'sucursal') {
                html += `
                    <div class="card mb-3">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">Activación de Sucursales</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <h3 class="text-primary">${activacion.cantidad}</h3>
                                    <small class="text-muted">Sucursales Nuevas</small>
                                </div>
                                <div class="col-md-4">
                                    <h3 class="text-info">${activacion.sucursales_anteriores || 0}</h3>
                                    <small class="text-muted">Sucursales Anteriores</small>
                                </div>
                                <div class="col-md-4">
                                    <h3 class="text-success">${activacion.sucursales_nuevas || 0}</h3>
                                    <small class="text-muted">Total Actual</small>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Notas
            if (activacion.notas) {
                html += `
                    <div class="card">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Notas</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-0">${activacion.notas}</p>
                        </div>
                    </div>
                `;
            }

            document.getElementById('detalleContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('modalDetalle')).show();
        }

        // Función para formatear moneda
        function formatearMoneda(monto) {
            return '$' + parseFloat(monto).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,') + ' MXN';
        }

        // Función para exportar a Excel
        function exportarExcel() {
            const table = document.getElementById('activacionesTable');
            if (!table) {
                Swal.fire({
                    title: 'Error',
                    text: 'No hay datos para exportar',
                    icon: 'error',
                    confirmButtonColor: '#dc3545'
                });
                return;
            }

            let csv = [];
            let rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                let row = [];
                let cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    // Limpiar el texto de HTML
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
                    // Escapar comillas
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                
                csv.push(row.join(','));
            }

            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'activaciones_' + new Date().toISOString().slice(0,10) + '.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            Swal.fire({
                title: 'Exportado',
                text: 'El archivo se ha descargado correctamente',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
        }

        // Validar fechas en los filtros
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            const fechaInicio = this.fecha_inicio.value;
            const fechaFin = this.fecha_fin.value;
            
            if (fechaInicio && fechaFin && fechaInicio > fechaFin) {
                e.preventDefault();
                Swal.fire({
                    title: 'Error',
                    text: 'La fecha de inicio no puede ser mayor que la fecha fin',
                    icon: 'error',
                    confirmButtonColor: '#dc3545'
                });
            }
        });
    </script>
</body>

</html>