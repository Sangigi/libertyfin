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

// Verificar si se encontró el distribuidor
if (!$distribuidor) {
    session_destroy();
    header("Location: login-distribuidor.php?error=distribuidor_no_encontrado");
    exit;
}

// Obtener el número de control del distribuidor para relacionarlo con empresas.no_distribuidor
$numero_control_distribuidor = $distribuidor['numero_control'];

// Obtener estadísticas de empresas del distribuidor (usando no_distribuidor = numero_control)
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

// Asegurar que todas las estadísticas tengan valores por defecto
$estadisticas = [
    'total_empresas' => $estadisticas['total_empresas'] ?? 0,
    'aprobadas' => $estadisticas['aprobadas'] ?? 0,
    'pendientes' => $estadisticas['pendientes'] ?? 0,
    'en_revision' => $estadisticas['en_revision'] ?? 0,
    'rechazadas' => $estadisticas['rechazadas'] ?? 0
];

// Obtener últimas empresas registradas del distribuidor
$empresas_sql = "SELECT e.*, g.nombre as nombre_giro 
                FROM empresas e
                LEFT JOIN giro_comercial g ON e.giro_comercial = g.id
                WHERE e.no_distribuidor = ? 
                ORDER BY e.fecha_creacion DESC 
                LIMIT 5";
$stmt_empresas = $conn->prepare($empresas_sql);
$stmt_empresas->bind_param("s", $numero_control_distribuidor);
$stmt_empresas->execute();
$empresas_result = $stmt_empresas->get_result();
$empresas_recientes = [];
while ($row = $empresas_result->fetch_assoc()) {
    $empresas_recientes[] = $row;
}
$stmt_empresas->close();

// Si no hay empresas con el no_distribuidor, intentar buscar por si el número de control está en otro formato
if (empty($empresas_recientes) && $estadisticas['total_empresas'] == 0) {
    // Intentar buscar sin ceros a la izquierda
    $numero_control_limpio = ltrim($numero_control_distribuidor, '0');
    
    if ($numero_control_limpio != $numero_control_distribuidor) {
        // Buscar con el número sin ceros
        $stmt_alt = $conn->prepare("SELECT COUNT(*) as total FROM empresas WHERE no_distribuidor = ?");
        $stmt_alt->bind_param("s", $numero_control_limpio);
        $stmt_alt->execute();
        $alt_result = $stmt_alt->get_result();
        $alt_count = $alt_result->fetch_assoc()['total'];
        $stmt_alt->close();
        
        if ($alt_count > 0) {
            // Si encontramos empresas con el formato alternativo, actualizar las consultas
            $numero_control_distribuidor = $numero_control_limpio;
            
            // Re-ejecutar consultas con el nuevo formato
            $stmt_stats = $conn->prepare($stats_sql);
            $stmt_stats->bind_param("s", $numero_control_distribuidor);
            $stmt_stats->execute();
            $stats_result = $stmt_stats->get_result();
            $estadisticas_temp = $stats_result->fetch_assoc();
            $stmt_stats->close();
            
            $estadisticas = [
                'total_empresas' => $estadisticas_temp['total_empresas'] ?? 0,
                'aprobadas' => $estadisticas_temp['aprobadas'] ?? 0,
                'pendientes' => $estadisticas_temp['pendientes'] ?? 0,
                'en_revision' => $estadisticas_temp['en_revision'] ?? 0,
                'rechazadas' => $estadisticas_temp['rechazadas'] ?? 0
            ];
            
            $stmt_empresas = $conn->prepare($empresas_sql);
            $stmt_empresas->bind_param("s", $numero_control_distribuidor);
            $stmt_empresas->execute();
            $empresas_result = $stmt_empresas->get_result();
            $empresas_recientes = [];
            while ($row = $empresas_result->fetch_assoc()) {
                $empresas_recientes[] = $row;
            }
            $stmt_empresas->close();
        }
    }
}

$conn->close();

// Función para formatear fecha (maneja valores nulos o vacíos)
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00' || $fecha == '0000-00-00') {
        return 'No registrada';
    }
    // Intentar diferentes formatos de fecha
    $timestamp = strtotime($fecha);
    if ($timestamp === false) {
        return 'Fecha inválida';
    }
    return date('d/m/Y H:i', $timestamp);
}

// Función para formatear fecha sin hora (para fechas de registro)
function formatearFechaRegistro($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00' || $fecha == '0000-00-00') {
        return 'No registrada';
    }
    $timestamp = strtotime($fecha);
    if ($timestamp === false) {
        return 'Fecha inválida';
    }
    return date('d/m/Y', $timestamp);
}

// Función para obtener la clase del estado
function claseEstado($estado) {
    switch ($estado) {
        case 'pendiente':
            return 'warning';
        case 'en_revision':
            return 'info';
        case 'aprobado':
            return 'success';
        case 'rechazado':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Función para obtener el texto del estado
function textoEstado($estado) {
    $estados = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Revisión',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado'
    ];
    return $estados[$estado] ?? ucfirst($estado);
}

// Determinar si los documentos están subidos
$constancia_subida = !empty($distribuidor['constancia_fiscal']);
$credencial_subida = !empty($distribuidor['credencial_identificacion']);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Panel Distribuidor - Libertyfin</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            min-height: calc(100vh - 70px);
            transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            will-change: transform;
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

        /* Tarjetas modernas */
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
            overflow: hidden;
            backdrop-filter: blur(0px);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        /* Tarjetas de estadísticas */
        .stat-card {
            border-radius: 20px;
            cursor: pointer;
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .stat-card:hover {
            transform: translateY(-8px);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        .metric-value {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1.2;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .metric-label {
            font-size: 0.75rem;
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

        .welcome-card .badge {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 500;
        }

        /* Documentos mejorados */
        .documento-item {
            padding: 18px;
            border: 1px solid #e9ecef;
            border-radius: 16px;
            margin-bottom: 12px;
            transition: var(--transition-smooth);
            background: white;
        }

        .documento-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 5px 20px rgba(39,174,96,0.1);
            transform: translateX(5px);
        }

        /* Tabla moderna - Desktop */
        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: linear-gradient(135deg, #f8f9fa, #f1f3f5);
            border-bottom: 2px solid #e9ecef;
            font-weight: 700;
            color: #2c3e50;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 1rem;
        }

        .table tbody tr {
            transition: var(--transition-smooth);
            cursor: pointer;
        }

        .table tbody tr:hover {
            background: linear-gradient(90deg, #f8f9fa, #ffffff);
            transform: scale(1.01);
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }

        /* ============================================ */
        /* TARJETAS PARA EMPRESAS EN MÓVIL */
        /* ============================================ */
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

        .empresa-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .empresa-nombre {
            font-weight: 700;
            font-size: 1rem;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .empresa-giro {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .empresa-rfc {
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 8px;
            font-size: 0.7rem;
            font-family: monospace;
            display: inline-block;
            margin-top: 0.5rem;
        }

        .empresa-contacto {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e9ecef;
        }

        .contacto-nombre {
            font-weight: 600;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .contacto-email {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 0.25rem;
            word-break: break-all;
        }

        .empresa-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e9ecef;
        }

        .empresa-fecha {
            font-size: 0.7rem;
            color: #6c757d;
        }

        .empresa-fecha i {
            margin-right: 0.25rem;
        }

        .btn-ver-movil {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 500;
            transition: var(--transition-smooth);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-ver-movil:hover {
            transform: scale(1.05);
            color: white;
        }

        /* Responsive: Ocultar tabla en móvil, mostrar tarjetas */
        @media (max-width: 767.98px) {
            .table-responsive-desktop {
                display: none;
            }
            
            .empresas-cards-movil {
                display: block;
            }
        }

        /* Para tabletas, mantener tabla pero con scroll */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .table-responsive-desktop {
                overflow-x: auto;
            }
        }

        /* Badges de estado */
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

        /* ACCIONES RÁPIDAS - DOS CARDS POR LÍNEA */
        .acciones-rapidas-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .action-card {
            transition: var(--transition-smooth);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            width: 100%;
        }

        .action-card .btn {
            transition: var(--transition-smooth);
            position: relative;
            z-index: 1;
            width: 100%;
            text-align: left;
            padding: 1.2rem;
            border: none;
            border-radius: 16px;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .action-card .btn i {
            font-size: 2rem;
            margin-bottom: 0.75rem;
        }

        .action-card .btn h6 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .action-card .btn small {
            font-size: 0.75rem;
            opacity: 0.85;
        }

        .action-card .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.15);
            transition: left 0.3s ease;
            z-index: -1;
        }

        .action-card:hover {
            transform: translateY(-5px);
        }

        .action-card:hover .btn::before {
            left: 0;
        }

        /* Responsive para móvil */
        @media (max-width: 576px) {
            .acciones-rapidas-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .action-card .btn {
                padding: 1rem;
            }
            
            .action-card .btn i {
                font-size: 1.5rem;
                margin-bottom: 0.5rem;
            }
        }

        /* Botón hamburguesa */
        .sidebar-toggle {
            display: none;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem 0.8rem;
            margin-right: 1rem;
            border-radius: 12px;
            transition: var(--transition-smooth);
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
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Responsive general */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 70px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 70px);
                z-index: 1050;
                overflow-y: auto;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }

            .metric-value {
                font-size: 1.6rem;
            }
            
            .col-md-2, .col-md-4 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .welcome-card .h4 {
                font-size: 1.2rem;
            }
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

        .card, .welcome-card, .empresa-card-movil {
            animation: fadeInUp 0.5s ease-out forwards;
        }

        /* Scrollbar personalizado */
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

        /* Modal mejorado */
        #modalArchivo .modal-content {
            border-radius: 24px;
            overflow: hidden;
        }

        #modalArchivo .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
        }

        #modalArchivo .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        /* Tooltips */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 6px 12px;
            background: rgba(0,0,0,0.85);
            color: white;
            font-size: 0.75rem;
            border-radius: 8px;
            white-space: nowrap;
            display: none;
            z-index: 1000;
            pointer-events: none;
            font-weight: 500;
        }

        [data-tooltip]:hover:before {
            display: block;
        }

        /* Indicadores de progreso */
        .progress-custom {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            overflow: hidden;
        }

        .progress-custom-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        /* Ajustes para la sección de información */
        .bg-light {
            background-color: #f8f9fa !important;
        }
        
        .rounded-3 {
            border-radius: 16px !important;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle" aria-label="Abrir menú">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../images/LibertyfinBlanco.webp" alt="LibertyFin" class="me-2 img-logo-navbar">
                <span>Panel Distribuidor</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-2"></i>
                        <span><?php echo htmlspecialchars($_SESSION['distribuidor_nombre'] ?? 'Distribuidor'); ?></span>
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

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-4">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="panel-distribuidor.php">
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
                            <a class="nav-link" href="mis-empresas.php">
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

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Welcome Card Premium -->
                <div class="card welcome-card mb-4">
                    <div class="card-body p-4 p-md-5">
                        <div class="d-flex align-items-center justify-content-between flex-wrap">
                            <div class="d-flex align-items-center mb-3 mb-sm-0">
                                <div class="me-4">
                                    <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                        <i class="fas fa-user-circle fa-3x"></i>
                                    </div>
                                </div>
                                <div>
                                    <h2 class="card-title mb-2 fw-bold">¡Bienvenido, <?php echo htmlspecialchars($distribuidor['nombre_distribuidor']); ?>!</h2>
                                    <div class="d-flex align-items-center flex-wrap gap-3">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-id-card me-2"></i>
                                            <span>Número de control: <strong><?php echo htmlspecialchars($distribuidor['numero_control']); ?></strong></span>
                                        </div>
                                        <span class="badge">
                                            <i class="fas fa-user-tag me-1"></i>Distribuidor Verificado
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="bg-white bg-opacity-25 rounded-3 p-3">
                                    <small class="text-white-50 d-block mb-1">Empresas registradas</small>
                                    <span class="h2 mb-0 text-white fw-bold"><?php echo $estadisticas['total_empresas']; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas de Empresas con indicadores visuales -->
                <div class="row mb-4">
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100" data-tooltip="Total de empresas registradas">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label mb-2">Total Empresas</div>
                                        <div class="metric-value"><?php echo $estadisticas['total_empresas']; ?></div>
                                    </div>
                                    <div class="bg-success bg-opacity-10 rounded-3 p-2">
                                        <i class="fas fa-building fa-2x text-success"></i>
                                    </div>
                                </div>
                                <div class="progress-custom mt-3">
                                    <div class="progress-custom-bar" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100" data-tooltip="Empresas aprobadas">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label mb-2">Aprobadas</div>
                                        <div class="metric-value"><?php echo $estadisticas['aprobadas']; ?></div>
                                    </div>
                                    <div class="bg-success bg-opacity-10 rounded-3 p-2">
                                        <i class="fas fa-check-circle fa-2x text-success"></i>
                                    </div>
                                </div>
                                <div class="progress-custom mt-3">
                                    <div class="progress-custom-bar" style="width: <?php echo $estadisticas['total_empresas'] > 0 ? ($estadisticas['aprobadas'] / $estadisticas['total_empresas'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100" data-tooltip="Empresas pendientes de revisión">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label mb-2">Pendientes</div>
                                        <div class="metric-value"><?php echo $estadisticas['pendientes']; ?></div>
                                    </div>
                                    <div class="bg-warning bg-opacity-10 rounded-3 p-2">
                                        <i class="fas fa-clock fa-2x text-warning"></i>
                                    </div>
                                </div>
                                <div class="progress-custom mt-3">
                                    <div class="progress-custom-bar" style="width: <?php echo $estadisticas['total_empresas'] > 0 ? ($estadisticas['pendientes'] / $estadisticas['total_empresas'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100" data-tooltip="Empresas en proceso de verificación">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label mb-2">En Revisión</div>
                                        <div class="metric-value"><?php echo $estadisticas['en_revision']; ?></div>
                                    </div>
                                    <div class="bg-info bg-opacity-10 rounded-3 p-2">
                                        <i class="fas fa-search fa-2x text-info"></i>
                                    </div>
                                </div>
                                <div class="progress-custom mt-3">
                                    <div class="progress-custom-bar" style="width: <?php echo $estadisticas['total_empresas'] > 0 ? ($estadisticas['en_revision'] / $estadisticas['total_empresas'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-tooltip="Empresas rechazadas" style="border-left-color: #dc3545;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label mb-2">Rechazadas</div>
                                        <div class="metric-value"><?php echo $estadisticas['rechazadas']; ?></div>
                                    </div>
                                    <div class="bg-danger bg-opacity-10 rounded-3 p-2">
                                        <i class="fas fa-times-circle fa-2x text-danger"></i>
                                    </div>
                                </div>
                                <div class="progress-custom mt-3">
                                    <div class="progress-custom-bar bg-danger" style="width: <?php echo $estadisticas['total_empresas'] > 0 ? ($estadisticas['rechazadas'] / $estadisticas['total_empresas'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estado de Verificación y Documentos -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-white border-0 pt-4 pb-0">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-shield-alt me-2 text-success"></i>
                                    Estado de Verificación
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $estado = $distribuidor['estado_verificacion'] ?? 'pendiente';
                                $clase = 'secondary';
                                $icono = 'fa-question-circle';
                                $mensaje = '';
                                $color = '';

                                if ($estado == 'pendiente') {
                                    $clase = 'warning';
                                    $icono = 'fa-hourglass-half';
                                    $mensaje = 'Tus documentos están siendo revisados. Este proceso puede tomar 24-48 horas.';
                                    $color = '#ffc107';
                                } elseif ($estado == 'en_revision') {
                                    $clase = 'info';
                                    $icono = 'fa-search';
                                    $mensaje = 'Tus documentos están en proceso de revisión por nuestro equipo.';
                                    $color = '#0dcaf0';
                                } elseif ($estado == 'aprobado') {
                                    $clase = 'success';
                                    $icono = 'fa-check-circle';
                                    $mensaje = '¡Felicidades! Tu cuenta ha sido verificada exitosamente.';
                                    $color = '#27ae60';
                                } elseif ($estado == 'rechazado') {
                                    $clase = 'danger';
                                    $icono = 'fa-times-circle';
                                    $mensaje = 'Tu documentación ha sido rechazada. Por favor, revisa las observaciones.';
                                    $color = '#dc3545';
                                }
                                ?>

                                <div class="text-center mb-4">
                                    <div class="position-relative d-inline-block">
                                        <div class="display-1 mb-3" style="color: <?php echo $color; ?>">
                                            <i class="fas <?php echo $icono; ?>"></i>
                                        </div>
                                        <div class="position-absolute top-0 start-100 translate-middle">
                                            <span class="badge bg-<?php echo $clase; ?> rounded-pill p-2">
                                                <i class="fas fa-info-circle"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <h4 class="fw-bold" style="color: <?php echo $color; ?>">
                                        <?php echo ucfirst($estado); ?>
                                    </h4>
                                </div>

                                <?php if ($mensaje): ?>
                                    <div class="alert alert-<?php echo $clase; ?> border-0 rounded-3 shadow-sm">
                                        <i class="fas fa-info-circle me-2"></i><?php echo $mensaje; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($estado == 'rechazado' && !empty($distribuidor['observaciones_verificacion'])): ?>
                                    <div class="alert alert-danger border-0 rounded-3 shadow-sm">
                                        <strong><i class="fas fa-exclamation-triangle me-2"></i>Motivo del rechazo:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($distribuidor['observaciones_verificacion'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-white border-0 pt-4 pb-0">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-file-alt me-2 text-success"></i>
                                    Documentos Enviados
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="documento-item">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="fas fa-file-pdf text-danger me-2 fa-lg"></i>
                                                <strong class="fs-6">Constancia de Situación Fiscal</strong>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Subido: <?php echo formatearFecha($distribuidor['fecha_subida_constancia'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <?php if (!empty($distribuidor['constancia_fiscal'])): ?>
                                            <button class="btn btn-sm btn-outline-primary ver-archivo rounded-pill px-3"
                                                data-archivo="../Distribuidor/uploads/distribuidores/constancias/<?php echo $distribuidor['constancia_fiscal']; ?>"
                                                data-tipo="pdf"
                                                data-nombre="constancia_fiscal_<?php echo $distribuidor['numero_control']; ?>.pdf"
                                                data-titulo="Constancia de Situación Fiscal">
                                                <i class="fas fa-eye me-1"></i> Ver
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-warning badge-estado">
                                                <i class="fas fa-clock me-1"></i>Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="documento-item">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="fas fa-id-card text-primary me-2 fa-lg"></i>
                                                <strong class="fs-6">Credencial de Identificación</strong>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Subido: <?php echo formatearFecha($distribuidor['fecha_subida_credencial'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <?php if (!empty($distribuidor['credencial_identificacion'])): ?>
                                            <?php
                                            $ext = pathinfo($distribuidor['credencial_identificacion'], PATHINFO_EXTENSION);
                                            $tipo = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif']) ? 'imagen' : 'pdf';
                                            ?>
                                            <button class="btn btn-sm btn-outline-primary ver-archivo rounded-pill px-3"
                                                data-archivo="../Distribuidor/uploads/distribuidores/credenciales/<?php echo $distribuidor['credencial_identificacion']; ?>"
                                                data-tipo="<?php echo $tipo; ?>"
                                                data-nombre="credencial_<?php echo $distribuidor['numero_control']; ?>.<?php echo $ext; ?>"
                                                data-titulo="Credencial de Identificación">
                                                <i class="fas fa-eye me-1"></i> Ver
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-warning badge-estado">
                                                <i class="fas fa-clock me-1"></i>Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empresas Recientes - VERSIÓN HÍBRIDA: TABLA EN DESKTOP, TARJETAS EN MÓVIL -->
                <div class="card mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4">
                        <h5 class="card-title mb-0 fw-bold">
                            <i class="fas fa-building me-2 text-success"></i>
                            Empresas Registradas Recientemente
                        </h5>
                        <a href="mis-empresas.php" class="btn btn-sm btn-outline-success rounded-pill px-3">
                            Ver todas <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($empresas_recientes)): ?>
                            <div class="text-center py-5">
                                <div class="bg-light rounded-circle p-4 d-inline-block mb-4">
                                    <i class="fas fa-building fa-3x text-muted"></i>
                                </div>
                                <h5 class="text-muted mb-3">No hay empresas registradas</h5>
                                <p class="text-muted mb-4">Aún no has registrado ninguna empresa. ¡Comienza registrando tu primera empresa!</p>
                                <a href="nueva-empresa.php" class="btn btn-success btn-lg rounded-pill px-4">
                                    <i class="fas fa-plus-circle me-2"></i>Registrar Empresa
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- VERSIÓN TABLA - Solo visible en desktop -->
                            <div class="table-responsive-desktop">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Empresa</th>
                                            <th>RFC</th>
                                            <th>Contacto</th>
                                            <th>Estado</th>
                                            <th>Fecha Registro</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($empresas_recientes as $empresa): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($empresa['rfc'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($empresa['nombre_contacto']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($empresa['email']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo claseEstado($empresa['estado_verificacion']); ?> badge-estado">
                                                    <i class="fas <?php echo $empresa['estado_verificacion'] == 'aprobado' ? 'fa-check' : ($empresa['estado_verificacion'] == 'pendiente' ? 'fa-clock' : ($empresa['estado_verificacion'] == 'en_revision' ? 'fa-search' : 'fa-times')); ?> me-1"></i>
                                                    <?php echo textoEstado($empresa['estado_verificacion']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatearFechaRegistro($empresa['fecha_creacion']); ?></td>
                                            <td>
                                                <a href="ver-empresa.php?id=<?php echo $empresa['id']; ?>" 
                                                   class="btn btn-sm btn-info rounded-pill px-3" 
                                                   title="Ver detalles de la empresa">
                                                    <i class="fas fa-eye me-1"></i> Ver
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- VERSIÓN TARJETAS - Solo visible en móvil -->
                            <div class="empresas-cards-movil p-3">
                                <?php foreach ($empresas_recientes as $empresa): ?>
                                <div class="empresa-card-movil">
                                    <div class="empresa-card-header">
                                        <div>
                                            <div class="empresa-nombre"><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></div>
                                            <div class="empresa-giro">
                                                <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?>
                                            </div>
                                            <div class="empresa-rfc">
                                                <i class="fas fa-file-alt me-1"></i>RFC: <?php echo htmlspecialchars($empresa['rfc'] ?? 'N/A'); ?>
                                            </div>
                                        </div>
                                        <span class="badge bg-<?php echo claseEstado($empresa['estado_verificacion']); ?> badge-estado">
                                            <i class="fas <?php echo $empresa['estado_verificacion'] == 'aprobado' ? 'fa-check' : ($empresa['estado_verificacion'] == 'pendiente' ? 'fa-clock' : ($empresa['estado_verificacion'] == 'en_revision' ? 'fa-search' : 'fa-times')); ?> me-1"></i>
                                            <?php echo textoEstado($empresa['estado_verificacion']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="empresa-contacto">
                                        <div class="contacto-nombre">
                                            <i class="fas fa-user-circle text-success"></i>
                                            <?php echo htmlspecialchars($empresa['nombre_contacto']); ?>
                                        </div>
                                        <div class="contacto-email">
                                            <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($empresa['email']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="empresa-footer">
                                        <div class="empresa-fecha">
                                            <i class="fas fa-calendar-alt"></i>
                                            <?php echo formatearFechaRegistro($empresa['fecha_creacion']); ?>
                                        </div>
                                        <a href="ver-empresa.php?id=<?php echo $empresa['id']; ?>" class="btn-ver-movil">
                                            <i class="fas fa-eye"></i> Ver detalles
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Información del Distribuidor y Acciones Rápidas -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-user me-2 text-success"></i>
                                    Mi Información
                                </h5>
                            </div>
                            <div class="card-body px-4">
                                <div class="row g-4">
                                    <div class="col-sm-6">
                                        <div class="bg-light rounded-3 p-3">
                                            <small class="text-muted d-block mb-1">Nombre Completo</small>
                                            <strong class="fs-5"><?php echo htmlspecialchars($distribuidor['nombre_distribuidor']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="bg-light rounded-3 p-3">
                                            <small class="text-muted d-block mb-1">Número de Control</small>
                                            <strong class="fs-5"><?php echo htmlspecialchars($distribuidor['numero_control']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="bg-light rounded-3 p-3">
                                            <small class="text-muted d-block mb-1">Email</small>
                                            <strong><i class="fas fa-envelope me-2 text-success"></i><?php echo htmlspecialchars($distribuidor['email']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="bg-light rounded-3 p-3">
                                            <small class="text-muted d-block mb-1">Teléfono</small>
                                            <strong><i class="fas fa-phone me-2 text-success"></i><?php echo htmlspecialchars($distribuidor['telefono']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="bg-light rounded-3 p-3">
                                            <small class="text-muted d-block mb-1">RFC</small>
                                            <strong><?php echo htmlspecialchars($distribuidor['rfc']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="bg-light rounded-3 p-3">
                                            <small class="text-muted d-block mb-1">Fecha de Registro</small>
                                            <strong><?php echo formatearFechaRegistro($distribuidor['fecha_registro'] ?? ''); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <a href="perfil-distribuidor.php" class="btn btn-outline-primary rounded-pill px-4">
                                        <i class="fas fa-edit me-2"></i>Editar Perfil
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-bolt me-2 text-success"></i>
                                    Acciones Rápidas
                                </h5>
                            </div>
                            <div class="card-body px-4">
                                <div class="acciones-rapidas-grid">
                                    <div class="action-card">
                                        <a href="nueva-empresa.php" class="btn">
                                            <i class="fas fa-plus-circle"></i>
                                            <h6 class="mb-1 fw-bold">Registrar Empresa</h6>
                                            <small>Agregar nueva empresa al sistema</small>
                                        </a>
                                    </div>
                                    <div class="action-card">
                                        <a href="mis-empresas.php" class="btn">
                                            <i class="fas fa-building"></i>
                                            <h6 class="mb-1 fw-bold">Mis Empresas</h6>
                                            <small>Ver todas mis empresas registradas</small>
                                        </a>
                                    </div>
                                    <div class="action-card">
                                        <a href="documentos.php" class="btn">
                                            <i class="fas fa-file-alt"></i>
                                            <h6 class="mb-1 fw-bold">Documentos</h6>
                                            <small>Gestionar mis documentos</small>
                                        </a>
                                    </div>
                                    <div class="action-card">
                                        <a href="comisiones.php" class="btn">
                                            <i class="fas fa-chart-line"></i>
                                            <h6 class="mb-1 fw-bold">Comisiones</h6>
                                            <small>Consultar mis comisiones generadas</small>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para ver archivos -->
    <div class="modal fade" id="modalArchivo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalArchivoTitulo"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-0" style="min-height: 500px;">
                    <div class="d-flex justify-content-center align-items-center h-100" id="archivoCargando">
                        <div class="text-center">
                            <div class="spinner-border text-success mb-3" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <p class="text-muted">Cargando archivo...</p>
                        </div>
                    </div>
                    <div id="visorImagen" class="d-none text-center p-3">
                        <img id="imagenVisor" src="" alt="Vista previa del documento" class="img-fluid">
                    </div>
                    <div id="visorPDF" class="d-none h-100">
                        <iframe id="pdfVisor" src="" frameborder="0" class="w-100 h-100" title="Visor de PDF"></iframe>
                    </div>
                    <div id="visorError" class="d-none text-center p-5">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <h5>No se puede mostrar el archivo</h5>
                        <p class="text-muted">Puede descargarlo para verlo en su dispositivo</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto">
                        <span id="infoArchivo" class="text-muted small"></span>
                    </div>
                    <a href="#" id="descargarArchivo" class="btn btn-success rounded-pill px-4" download>
                        <i class="fas fa-download me-1"></i>Descargar
                    </a>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // =============================================
        // FUNCIONALIDAD DE SWIPE AUTOMÁTICO PARA SIDEBAR
        // =============================================

        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        let isSidebarTouch = false;
        const SWIPE_THRESHOLD = 50;
        const SWIPE_EDGE_ZONE = 30;
        const VERTICAL_THRESHOLD = 30;

        function openSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.add('show');
                sidebarBackdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        function isInsideTable(element) {
            while (element) {
                if (element.classList && element.classList.contains('table-responsive')) {
                    return true;
                }
                if (element.classList && element.classList.contains('table')) {
                    return true;
                }
                if (element.tagName === 'TD' || element.tagName === 'TH' || element.tagName === 'TR' ||
                    element.tagName === 'TBODY' || element.tagName === 'THEAD') {
                    return true;
                }
                element = element.parentElement;
            }
            return false;
        }

        document.addEventListener('touchstart', function(e) {
            if (window.innerWidth >= 768) return;

            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;

            if (touchX <= SWIPE_EDGE_ZONE && !isInsideTable(e.target)) {
                isSidebarTouch = true;
                touchStartX = touchX;
                touchStartY = touchY;
                touchEndX = touchStartX;
                touchEndY = touchStartY;
            }
        }, {
            passive: true
        });

        document.addEventListener('touchmove', function(e) {
            if (window.innerWidth >= 768) return;

            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;

            if (isSidebarTouch) {
                touchEndX = touchX;
                touchEndY = touchY;

                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                    e.preventDefault();
                }
            }
        }, {
            passive: false
        });

        document.addEventListener('touchend', function(e) {
            if (window.innerWidth >= 768) return;

            if (isSidebarTouch) {
                isSidebarTouch = false;

                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
                    return;
                }

                const sidebar = document.getElementById('sidebar');
                const isSidebarOpen = sidebar && sidebar.classList.contains('show');

                if (deltaX > SWIPE_THRESHOLD) {
                    if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                        openSidebarAuto();
                    }
                } else if (deltaX < -SWIPE_THRESHOLD) {
                    if (isSidebarOpen) {
                        closeSidebarAuto();
                    }
                }
            }
        }, {
            passive: true
        });

        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function toggleSidebar() {
                if (sidebar.classList.contains('show')) {
                    closeSidebarAuto();
                } else {
                    openSidebarAuto();
                }
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebarAuto);
            }

            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebarAuto();
                    }
                });
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebarAuto();
                }
            });
        });

        // =============================================
        // FUNCIONALIDAD PARA EL VISOR DE ARCHIVOS
        // =============================================

        window.abrirArchivoModal = function(rutaArchivo, tipoArchivo, nombreArchivo, titulo) {
            const modalElement = document.getElementById('modalArchivo');
            const modal = new bootstrap.Modal(modalElement);
            const modalTitulo = document.getElementById('modalArchivoTitulo');
            const cargando = document.getElementById('archivoCargando');
            const visorImagen = document.getElementById('visorImagen');
            const imagenVisor = document.getElementById('imagenVisor');
            const visorPDF = document.getElementById('visorPDF');
            const pdfVisor = document.getElementById('pdfVisor');
            const visorError = document.getElementById('visorError');
            const descargarBtn = document.getElementById('descargarArchivo');
            const infoArchivo = document.getElementById('infoArchivo');

            modalTitulo.textContent = titulo;
            descargarBtn.href = rutaArchivo;
            descargarBtn.download = nombreArchivo;
            infoArchivo.textContent = nombreArchivo;

            cargando.classList.remove('d-none');
            visorImagen.classList.add('d-none');
            visorPDF.classList.add('d-none');
            visorError.classList.add('d-none');

            modal.show();

            modalElement.addEventListener('shown.bs.modal', function onShown() {
                modalElement.removeEventListener('shown.bs.modal', onShown);

                const modalBody = modalElement.querySelector('.modal-body');
                const modalHeader = modalElement.querySelector('.modal-header');
                const modalFooter = modalElement.querySelector('.modal-footer');

                const headerHeight = modalHeader ? modalHeader.offsetHeight : 0;
                const footerHeight = modalFooter ? modalFooter.offsetHeight : 0;
                const windowHeight = window.innerHeight;
                const maxModalHeight = windowHeight * 0.9;
                const modalBodyHeight = maxModalHeight - headerHeight - footerHeight - 40;

                if (tipoArchivo === 'imagen') {
                    const img = new Image();
                    img.onload = function() {
                        imagenVisor.src = rutaArchivo;
                        cargando.classList.add('d-none');
                        visorImagen.classList.remove('d-none');

                        const maxWidth = modalBody.offsetWidth - 40;
                        const maxHeight = modalBodyHeight - 40;

                        if (this.width > maxWidth || this.height > maxHeight) {
                            const ratio = Math.min(maxWidth / this.width, maxHeight / this.height);
                            imagenVisor.style.width = (this.width * ratio) + 'px';
                            imagenVisor.style.height = (this.height * ratio) + 'px';
                        }

                        infoArchivo.textContent = `${nombreArchivo} (${this.width}×${this.height}px)`;
                    };
                    img.onerror = function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    };
                    img.src = rutaArchivo;

                } else if (tipoArchivo === 'pdf') {
                    pdfVisor.src = rutaArchivo + '#view=fitH';
                    pdfVisor.style.height = modalBodyHeight + 'px';

                    const onPDFLoad = function() {
                        cargando.classList.add('d-none');
                        visorPDF.classList.remove('d-none');
                    };

                    pdfVisor.onload = onPDFLoad;
                    pdfVisor.onerror = function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    };

                    setTimeout(function() {
                        if (!cargando.classList.contains('d-none')) {
                            onPDFLoad();
                        }
                    }, 3000);
                } else {
                    setTimeout(function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    }, 500);
                }

                modalBody.style.display = 'none';
                modalBody.offsetHeight;
                modalBody.style.display = 'block';
            });
        };

        $(document).on('click', '.ver-archivo', function(e) {
            e.preventDefault();
            const ruta = $(this).data('archivo');
            const tipo = $(this).data('tipo');
            const nombre = $(this).data('nombre');
            const titulo = $(this).data('titulo');

            if (ruta && tipo && nombre && titulo) {
                if (typeof window.abrirArchivoModal === 'function') {
                    window.abrirArchivoModal(ruta, tipo, nombre, titulo);
                } else {
                    window.open(ruta, '_blank');
                }
            }
        });

        $('#modalArchivo').on('hidden.bs.modal', function() {
            const imagenVisor = document.getElementById('imagenVisor');
            const pdfVisor = document.getElementById('pdfVisor');

            if (imagenVisor) {
                imagenVisor.src = '';
                imagenVisor.style.width = '';
                imagenVisor.style.height = '';
            }
            if (pdfVisor) {
                pdfVisor.src = '';
                pdfVisor.style.height = '100%';
            }

            document.getElementById('archivoCargando').classList.remove('d-none');
            document.getElementById('visorImagen').classList.add('d-none');
            document.getElementById('visorPDF').classList.add('d-none');
            document.getElementById('visorError').classList.add('d-none');
        });
    </script>
</body>

</html>