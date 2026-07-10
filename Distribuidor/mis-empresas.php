<?php
session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['distribuidor_id'])) {
    header("Location: login-distribuidor.php");
    exit;
}

$db_config = [
    'host' => 'libertyfin.com.mx',
    'user' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'database' => 'juanc141_ventas'
];

$conn = new mysqli(
    $db_config['host'],
    $db_config['user'],
    $db_config['password'],
    $db_config['database']
);

$distribuidor_id = $_SESSION['distribuidor_id'];

// Obtener información del distribuidor
$sql = "SELECT * FROM distribuidores WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $distribuidor_id);
$stmt->execute();
$result = $stmt->get_result();
$distribuidor = $result->fetch_assoc();
$stmt->close();

$numero_control_distribuidor = $distribuidor['numero_control'];

// Variables para filtros y paginación
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 10;

// Construir consulta base con filtros
$sql_where = "WHERE e.no_distribuidor = ?";
$params = [$numero_control_distribuidor];
$types = "s";

if (!empty($filtro_estado) && $filtro_estado !== 'todos') {
    $sql_where .= " AND e.estado_verificacion = ?";
    $params[] = $filtro_estado;
    $types .= "s";
}

if (!empty($filtro_busqueda)) {
    $sql_where .= " AND (e.nombre_empresa LIKE ? OR e.rfc LIKE ? OR e.email LIKE ?)";
    $busqueda_param = "%" . $filtro_busqueda . "%";
    $params[] = $busqueda_param;
    $params[] = $busqueda_param;
    $params[] = $busqueda_param;
    $types .= "sss";
}

// Contar total de registros
$sql_count = "SELECT COUNT(*) as total FROM empresas e $sql_where";
$stmt_count = $conn->prepare($sql_count);
$stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_registros = $result_count->fetch_assoc()['total'];
$stmt_count->close();

// Calcular paginación
$total_paginas = ceil($total_registros / $registros_por_pagina);
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Obtener empresas
$sql_empresas = "SELECT e.*, g.nombre as nombre_giro 
                FROM empresas e
                LEFT JOIN giro_comercial g ON e.giro_comercial = g.id
                $sql_where 
                ORDER BY e.fecha_creacion DESC 
                LIMIT ? OFFSET ?";

$stmt_empresas = $conn->prepare($sql_empresas);
$params[] = $registros_por_pagina;
$params[] = $offset;
$types .= "ii";
$stmt_empresas->bind_param($types, ...$params);
$stmt_empresas->execute();
$empresas_result = $stmt_empresas->get_result();

$empresas = [];
while ($row = $empresas_result->fetch_assoc()) {
    $empresas[] = $row;
}
$stmt_empresas->close();

// Obtener estadísticas para las tarjetas
$stats_sql = "SELECT 
    COUNT(*) as total_empresas,
    SUM(CASE WHEN estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobadas,
    SUM(CASE WHEN estado_verificacion = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
    SUM(CASE WHEN estado_verificacion = 'en_revision' THEN 1 ELSE 0 END) as en_revision,
    SUM(CASE WHEN estado_verificacion = 'rechazado' THEN 1 ELSE 0 END) as rechazadas
    FROM empresas WHERE no_distribuidor = ?";

$stmt_stats = $conn->prepare($stats_sql);
$stmt_stats->bind_param("s", $numero_control_distribuidor);
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$estadisticas = $stats_result->fetch_assoc();
$stmt_stats->close();

$conn->close();

// Funciones auxiliares
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y', strtotime($fecha));
}

function claseEstado($estado) {
    switch ($estado) {
        case 'pendiente': return 'warning';
        case 'en_revision': return 'info';
        case 'aprobado': return 'success';
        case 'rechazado': return 'danger';
        default: return 'secondary';
    }
}

function textoEstado($estado) {
    $estados = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Revisión',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado'
    ];
    return $estados[$estado] ?? ucfirst($estado);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Mis Empresas - Libertyfin</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #27ae60;
            --primary-dark: #219a52;
            --secondary-color: #2ecc71;
            --accent-color: #3498db;
            --dark-bg: #1a2634;
            --gray-bg: #f8fafc;
            --card-shadow: 0 10px 40px rgba(0,0,0,0.08);
            --card-shadow-hover: 0 20px 50px rgba(0,0,0,0.12);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* Navbar mejorado */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 4px 20px rgba(39,174,96,0.2);
            padding: 0.8rem 1rem;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1030;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: -0.3px;
        }

        .img-logo-navbar {
            height: 36px;
            width: auto;
            filter: brightness(0) invert(1);
        }

        /* Sidebar moderno */
        .sidebar {
            background: linear-gradient(180deg, #1e2a36 0%, #1a2530 100%);
            color: white;
            position: fixed;
            top: 70px;
            left: 0;
            width: 260px;
            height: calc(100vh - 70px);
            transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            z-index: 1020;
            overflow-y: auto;
            box-shadow: 2px 0 20px rgba(0,0,0,0.1);
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 4px 12px;
            border-radius: 12px;
            transition: var(--transition-smooth);
            font-weight: 500;
            position: relative;
        }

        .sidebar .nav-link:hover {
            background: rgba(46, 204, 113, 0.15);
            color: white;
            transform: translateX(5px);
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(39,174,96,0.3);
        }

        .sidebar .nav-link i {
            width: 24px;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        /* Main content wrapper */
        .main-wrapper {
            margin-top: 70px;
            margin-left: 260px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 70px);
            padding: 1.5rem;
        }

        /* Sidebar toggle button */
        .sidebar-toggle {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem 0.8rem;
            margin-right: 1rem;
            border-radius: 12px;
            transition: var(--transition-smooth);
            display: none;
        }

        .sidebar-toggle:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1015;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Responsive */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.mobile-visible {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0 !important;
                padding: 1rem;
            }

            .sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }
        }

        /* Tarjetas modernas */
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        /* Tarjetas de estadísticas */
        .stat-card {
            border-left: 4px solid;
            transition: var(--transition-smooth);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card .card-body {
            padding: 1.25rem;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.2;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .metric-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
        }

        /* Welcome card premium */
        .welcome-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 24px;
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .welcome-card::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        /* Sección de filtros */
        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 1.5px solid #e9ecef;
            padding: 0.7rem 1rem;
            transition: var(--transition-smooth);
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39,174,96,0.1);
            outline: none;
        }

        /* Botones */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: var(--transition-smooth);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: var(--transition-smooth);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108,117,125,0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: var(--transition-smooth);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
        }

        /* Badges */
        .badge-estado {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Tablas */
        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: linear-gradient(135deg, #f8f9fa, #f1f3f5);
            border-bottom: 2px solid #e9ecef;
            font-weight: 700;
            color: #2c3e50;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 1rem;
        }

        .table tbody tr {
            transition: var(--transition-smooth);
        }

        .table tbody tr:hover {
            background: linear-gradient(90deg, #f8f9fa, #ffffff);
            transform: scale(1.01);
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }

        /* Botones de acción */
        .accion-btn {
            margin: 0 2px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            transition: var(--transition-smooth);
        }

        .accion-btn:hover {
            transform: scale(1.05);
        }

        /* Paginación */
        .pagination {
            gap: 0.25rem;
        }

        .page-link {
            border-radius: 10px;
            border: none;
            padding: 0.5rem 1rem;
            color: var(--primary-color);
            font-weight: 500;
            transition: var(--transition-smooth);
        }

        .page-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
        }

        /* Animaciones */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card, .filter-section {
            animation: fadeInUp 0.5s ease-out forwards;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }

        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Cards para empresas en móvil */
        .empresas-cards-movil {
            display: none;
        }

        .empresa-card-movil {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: var(--transition-smooth);
            border: 1px solid #e9ecef;
            position: relative;
            overflow: hidden;
        }

        .empresa-card-movil::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .empresa-card-movil:hover {
            transform: translateX(5px);
            box-shadow: var(--card-shadow);
            border-color: var(--primary-color);
        }

        @media (max-width: 767.98px) {
            .table-responsive-desktop {
                display: none;
            }
            
            .empresas-cards-movil {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle" aria-label="Abrir menú">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="panel-distribuidor.php">
                <img src="../images/LibertyfinBlanco.webp" alt="LibertyFin" class="me-2 img-logo-navbar">
                <span>Mis Empresas</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-2"></i>
                        <span><?php echo htmlspecialchars($_SESSION['distribuidor_nombre'] ?? $distribuidor['nombre_distribuidor']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="perfil-distribuidor.php"><i class="fas fa-user me-2"></i>Mi Perfil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="cerrar-sesion.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Sidebar Overlay -->
    <div class="sidebar-backdrop" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="position-sticky pt-4">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="panel-distribuidor.php">
                        <i class="fas fa-tachometer-alt"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="perfil-distribuidor.php">
                        <i class="fas fa-user-cog"></i>
                        Perfil
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="mis-empresas.php">
                        <i class="fas fa-building"></i>
                        Empresas
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="nueva-empresa.php">
                        <i class="fas fa-plus-circle"></i>
                        Registrar Empresa
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="comisiones.php">
                        <i class="fas fa-chart-line"></i>
                        Comisiones
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Main Content Wrapper -->
    <div class="main-wrapper" id="mainWrapper">
        <main>
            <!-- Welcome Card Premium -->
            <div class="card welcome-card mb-4">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex align-items-center">
                        <div class="me-4">
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-building fa-3x"></i>
                            </div>
                        </div>
                        <div>
                            <h2 class="card-title mb-2 fw-bold">Mis Empresas</h2>
                            <div class="d-flex align-items-center flex-wrap gap-3">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-id-card me-2"></i>
                                    <span>Número de control: <strong><?php echo htmlspecialchars($distribuidor['numero_control']); ?></strong></span>
                                </div>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-chart-line me-1"></i>Total: <?php echo $estadisticas['total_empresas'] ?? 0; ?> empresas
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tarjetas de estadísticas -->
            <div class="row g-3 mb-4">
                <div class="col-md-2 col-6">
                    <div class="card stat-card h-100" style="border-left-color: var(--primary-color);">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Total</div>
                                    <div class="metric-value"><?php echo $estadisticas['total_empresas'] ?? 0; ?></div>
                                </div>
                                <div class="bg-success bg-opacity-10 rounded-3 p-2">
                                    <i class="fas fa-building fa-2x text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card stat-card h-100" style="border-left-color: #28a745;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Aprobadas</div>
                                    <div class="metric-value text-success"><?php echo $estadisticas['aprobadas'] ?? 0; ?></div>
                                </div>
                                <div class="bg-success bg-opacity-10 rounded-3 p-2">
                                    <i class="fas fa-check-circle fa-2x text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card stat-card h-100" style="border-left-color: #ffc107;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Pendientes</div>
                                    <div class="metric-value text-warning"><?php echo $estadisticas['pendientes'] ?? 0; ?></div>
                                </div>
                                <div class="bg-warning bg-opacity-10 rounded-3 p-2">
                                    <i class="fas fa-clock fa-2x text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card stat-card h-100" style="border-left-color: #0dcaf0;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">En Revisión</div>
                                    <div class="metric-value text-info"><?php echo $estadisticas['en_revision'] ?? 0; ?></div>
                                </div>
                                <div class="bg-info bg-opacity-10 rounded-3 p-2">
                                    <i class="fas fa-search fa-2x text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="card stat-card h-100" style="border-left-color: #dc3545;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Rechazadas</div>
                                    <div class="metric-value text-danger"><?php echo $estadisticas['rechazadas'] ?? 0; ?></div>
                                </div>
                                <div class="bg-danger bg-opacity-10 rounded-3 p-2">
                                    <i class="fas fa-times-circle fa-2x text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-filter me-1"></i>Estado
                        </label>
                        <select name="estado" class="form-select">
                            <option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                            <option value="en_revision" <?php echo $filtro_estado === 'en_revision' ? 'selected' : ''; ?>>En Revisión</option>
                            <option value="aprobado" <?php echo $filtro_estado === 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                            <option value="rechazado" <?php echo $filtro_estado === 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">
                            <i class="fas fa-search me-1"></i>Buscar
                        </label>
                        <input type="text" name="busqueda" class="form-control" 
                               placeholder="Nombre de empresa, RFC o Email..." 
                               value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Filtrar
                        </button>
                        <a href="mis-empresas.php" class="btn btn-secondary w-100">
                            <i class="fas fa-undo me-2"></i>Limpiar
                        </a>
                    </div>
                </form>
            </div>

            <!-- Tabla de Empresas -->
            <div class="card">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <h5 class="card-title mb-0 fw-bold">
                            <i class="fas fa-list-ul me-2 text-primary"></i>
                            Listado de Empresas
                        </h5>
                        <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo $total_registros; ?> registros</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($empresas)): ?>
                        <div class="text-center py-5">
                            <div class="bg-light rounded-circle p-4 d-inline-block mb-4">
                                <i class="fas fa-building fa-3x text-muted"></i>
                            </div>
                            <h5 class="text-muted mb-3">No se encontraron empresas</h5>
                            <p class="text-muted mb-4">Aún no has registrado ninguna empresa o no coinciden con los filtros aplicados.</p>
                            <a href="nueva-empresa.php" class="btn btn-success rounded-pill px-4">
                                <i class="fas fa-plus-circle me-2"></i>Registrar Empresa
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- VERSIÓN TABLA - Solo visible en desktop -->
                        <div class="table-responsive-desktop">
                            <table class="table table-hover mb-0" id="tablaEmpresas">
                                <thead>
                                    <tr>
                                        <th>Empresa</th>
                                        <th>RFC</th>
                                        <th>Contacto</th>
                                        <th>Email</th>
                                        <th>Estado</th>
                                        <th>Registro</th>
                                        <th>Acciones</th>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($empresas as $empresa): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($empresa['rfc'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($empresa['nombre_contacto']); ?></td>
                                                <td><?php echo htmlspecialchars($empresa['email']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo claseEstado($empresa['estado_verificacion']); ?> badge-estado">
                                                        <i class="fas <?php echo $empresa['estado_verificacion'] == 'aprobado' ? 'fa-check' : ($empresa['estado_verificacion'] == 'pendiente' ? 'fa-clock' : ($empresa['estado_verificacion'] == 'en_revision' ? 'fa-search' : 'fa-times')); ?> me-1"></i>
                                                        <?php echo textoEstado($empresa['estado_verificacion']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatearFecha($empresa['fecha_creacion']); ?></td>
                                                <td>
                                                    <a href="ver-empresa.php?id=<?php echo $empresa['id']; ?>" 
                                                       class="btn btn-sm btn-info accion-btn" 
                                                       title="Ver detalles de la empresa">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                        </div>

                        <!-- VERSIÓN TARJETAS - Solo visible en móvil -->
                        <div class="empresas-cards-movil p-3">
                            <?php foreach ($empresas as $empresa): ?>
                                <div class="empresa-card-movil">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <div class="fw-bold fs-6"><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></div>
                                            <div class="text-muted small">
                                                <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?>
                                            </div>
                                        </div>
                                        <span class="badge bg-<?php echo claseEstado($empresa['estado_verificacion']); ?> badge-estado">
                                            <i class="fas <?php echo $empresa['estado_verificacion'] == 'aprobado' ? 'fa-check' : ($empresa['estado_verificacion'] == 'pendiente' ? 'fa-clock' : ($empresa['estado_verificacion'] == 'en_revision' ? 'fa-search' : 'fa-times')); ?> me-1"></i>
                                            <?php echo textoEstado($empresa['estado_verificacion']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <div class="small text-muted">RFC</div>
                                        <div class="small fw-medium"><?php echo htmlspecialchars($empresa['rfc'] ?? 'N/A'); ?></div>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <div class="small text-muted">Contacto</div>
                                        <div class="small fw-medium"><?php echo htmlspecialchars($empresa['nombre_contacto']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($empresa['email']); ?></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                        <div class="small text-muted">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            <?php echo formatearFecha($empresa['fecha_creacion']); ?>
                                        </div>
                                        <a href="ver-empresa.php?id=<?php echo $empresa['id']; ?>" 
                                           class="btn btn-sm btn-info rounded-pill px-3">
                                            <i class="fas fa-eye me-1"></i> Ver
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Paginación -->
                        <?php if ($total_paginas > 1): ?>
                            <div class="d-flex justify-content-center py-4">
                                <nav>
                                    <ul class="pagination">
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual-1; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php 
                                        $inicio = max(1, $pagina_actual - 2);
                                        $fin = min($total_paginas, $pagina_actual + 2);
                                        for ($i = $inicio; $i <= $fin; $i++): 
                                        ?>
                                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                <a class="page-link" href="?pagina=<?php echo $i; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual+1; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>

    <script>
        // =============================================
        // FUNCIONALIDAD DE SWIPE AUTOMÁTICO PARA SIDEBAR
        // =============================================

        const sidebar = document.getElementById('sidebar');
        const mainWrapper = document.getElementById('mainWrapper');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (sidebar && mainWrapper && sidebarToggle && sidebarOverlay) {
            let touchStartX = 0;
            let touchEndX = 0;
            let isSwiping = false;
            const minSwipeDistance = 50;

            function openSidebar() {
                if (window.innerWidth <= 767.98) {
                    sidebar.classList.add('mobile-visible');
                    sidebarOverlay.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeSidebar() {
                if (window.innerWidth <= 767.98) {
                    sidebar.classList.remove('mobile-visible');
                    sidebarOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }

            function toggleSidebar() {
                if (window.innerWidth <= 767.98) {
                    if (sidebar.classList.contains('mobile-visible')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                }
            }

            // Touch events para swipe
            document.addEventListener('touchstart', (e) => {
                if (window.innerWidth > 767.98) return;
                touchStartX = e.touches[0].clientX;
                isSwiping = true;
            });

            document.addEventListener('touchend', (e) => {
                if (window.innerWidth > 767.98 || !isSwiping) return;
                touchEndX = e.changedTouches[0].clientX;
                const deltaX = touchEndX - touchStartX;

                if (deltaX > minSwipeDistance && touchStartX < 30) {
                    if (!sidebar.classList.contains('mobile-visible')) {
                        openSidebar();
                    }
                } else if (deltaX < -minSwipeDistance && sidebar.classList.contains('mobile-visible')) {
                    closeSidebar();
                }

                isSwiping = false;
            });

            // Event listeners para controles
            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarOverlay.addEventListener('click', closeSidebar);

            // Cerrar sidebar al hacer click en un enlace (solo móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 767.98) {
                        closeSidebar();
                    }
                });
            });

            // Manejar resize de ventana
            function handleResize() {
                if (window.innerWidth > 767.98) {
                    closeSidebar();
                    sidebar.classList.remove('mobile-visible');
                    sidebarOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }

            window.addEventListener('resize', handleResize);
        }

        // =============================================
        // INICIALIZAR DATATABLE (SOLO EN DESKTOP)
        // =============================================
        
        <?php if (!empty($empresas)): ?>
            if (window.innerWidth > 767.98) {
                $('#tablaEmpresas').DataTable({
                    responsive: true,
                    language: {
                        "decimal": "",
                        "emptyTable": "No hay datos disponibles",
                        "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
                        "infoEmpty": "Mostrando 0 a 0 de 0 registros",
                        "infoFiltered": "(filtrado de _MAX_ registros totales)",
                        "infoPostFix": "",
                        "thousands": ",",
                        "lengthMenu": "Mostrar _MENU_ registros",
                        "loadingRecords": "Cargando...",
                        "processing": "Procesando...",
                        "search": "Buscar:",
                        "zeroRecords": "No se encontraron registros",
                        "paginate": {
                            "first": "Primero",
                            "last": "Último",
                            "next": "Siguiente",
                            "previous": "Anterior"
                        }
                    },
                    order: [[5, 'desc']],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Todos"]]
                });
            }
        <?php endif; ?>
    </script>
</body>
</html>