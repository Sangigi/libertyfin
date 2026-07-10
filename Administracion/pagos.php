<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

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

// Variables para mensajes y filtros
$mensaje = '';
$tipo_mensaje = '';

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

try {
    // =============================================
    // OBTENER ESTADÍSTICAS POR SEPARADO
    // =============================================
    
    // Verificar si la tabla pagos_paypal existe
    $table_check = $conn->query("SHOW TABLES LIKE 'pagos_paypal'");
    $paypal_table_exists = $table_check && $table_check->num_rows > 0;
    
    $table_check_liga = $conn->query("SHOW TABLES LIKE 'pagos_liga'");
    $liga_table_exists = $table_check_liga && $table_check_liga->num_rows > 0;
    
    // Verificar si la tabla spei_transacciones existe
    $table_check_spei = $conn->query("SHOW TABLES LIKE 'spei_transacciones'");
    $spei_table_exists = $table_check_spei && $table_check_spei->num_rows > 0;
    
    // Estadísticas de pagos PayPal
    $stats_paypal = [
        'total_pagos' => 0,
        'total_monto' => 0,
        'completados' => 0,
        'pendientes' => 0,
        'fallidos' => 0,
        'monto_completados' => 0
    ];
    
    if ($paypal_table_exists) {
        $sql_stats_paypal = "SELECT 
            COUNT(*) as total_pagos,
            COALESCE(SUM(amount), 0) as total_monto,
            SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completados,
            SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN status NOT IN ('COMPLETED', 'PENDING') OR status IS NULL THEN 1 ELSE 0 END) as fallidos,
            COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN amount ELSE 0 END), 0) as monto_completados
            FROM pagos_paypal";
        
        $result_stats_paypal = $conn->query($sql_stats_paypal);
        if ($result_stats_paypal) {
            $stats_paypal = $result_stats_paypal->fetch_assoc();
        }
    }
    
    // Estadísticas de pagos con liga
    $stats_liga = [
        'total_pagos' => 0,
        'total_monto' => 0,
        'completados' => 0,
        'pendientes' => 0,
        'fallidos' => 0,
        'monto_completados' => 0
    ];
    
    if ($liga_table_exists) {
        // Primero, obtener los valores distintos de response para saber qué estados existen
        $sql_check_responses = "SELECT DISTINCT response FROM pagos_liga";
        $result_responses = $conn->query($sql_check_responses);
        $responses = [];
        if ($result_responses) {
            while ($row = $result_responses->fetch_assoc()) {
                $responses[] = $row['response'];
            }
        }
        
        // Si hay respuestas, construir la consulta basada en los valores reales
        if (!empty($responses)) {
            // Identificar qué valores representan completados y fallidos
            $completed_values = [];
            $failed_values = [];
            
            foreach ($responses as $resp) {
                $resp_lower = strtolower($resp);
                if (in_array($resp_lower, ['approved', 'a', 'aprobado', 'completado', 'success', '1'])) {
                    $completed_values[] = $resp;
                } elseif (in_array($resp_lower, ['denied', 'd', 'declined', 'rejected', 'fallido', 'error', '0', 'failed'])) {
                    $failed_values[] = $resp;
                }
            }
            
            // Construir la consulta dinámicamente
            $sql_stats_liga = "SELECT 
                COUNT(*) as total_pagos,
                COALESCE(SUM(amount), 0) as total_monto,";
            
            if (!empty($completed_values)) {
                $completed_list = "'" . implode("','", array_map([$conn, 'real_escape_string'], $completed_values)) . "'";
                $sql_stats_liga .= "
                SUM(CASE WHEN response IN ($completed_list) THEN 1 ELSE 0 END) as completados,
                COALESCE(SUM(CASE WHEN response IN ($completed_list) THEN amount ELSE 0 END), 0) as monto_completados,";
            } else {
                $sql_stats_liga .= " 0 as completados, 0 as monto_completados,";
            }
            
            if (!empty($failed_values)) {
                $failed_list = "'" . implode("','", array_map([$conn, 'real_escape_string'], $failed_values)) . "'";
                $sql_stats_liga .= "
                SUM(CASE WHEN response IN ($failed_list) THEN 1 ELSE 0 END) as fallidos,";
            } else {
                $sql_stats_liga .= " 0 as fallidos,";
            }
            
            // Los pendientes son los que no están ni en completados ni en fallidos
            $sql_stats_liga .= "
                SUM(CASE WHEN response NOT IN (" . (!empty($completed_values) ? $completed_list : "''") . 
                (!empty($failed_values) ? "," . $failed_list : "") . ") OR response IS NULL THEN 1 ELSE 0 END) as pendientes
                FROM pagos_liga";
            
            $result_stats_liga = $conn->query($sql_stats_liga);
            if ($result_stats_liga) {
                $stats_liga = $result_stats_liga->fetch_assoc();
            }
        } else {
            // Si no hay datos, usar valores por defecto
            $sql_stats_liga = "SELECT 
                COUNT(*) as total_pagos,
                COALESCE(SUM(amount), 0) as total_monto,
                0 as completados,
                0 as pendientes,
                0 as fallidos,
                0 as monto_completados
                FROM pagos_liga";
            
            $result_stats_liga = $conn->query($sql_stats_liga);
            if ($result_stats_liga) {
                $stats_liga = $result_stats_liga->fetch_assoc();
            }
        }
    }
    
    // Estadísticas de transferencias SPEI
    $stats_spei = [
        'total_transacciones' => 0,
        'total_monto' => 0,
        'total_respuesta_positiva' => 0,
        'total_respuesta_negativa' => 0,
        'monto_exitoso' => 0
    ];
    
    if ($spei_table_exists) {
        $sql_stats_spei = "SELECT 
            COUNT(*) as total_transacciones,
            COALESCE(SUM(monto), 0) as total_monto,
            SUM(CASE WHEN codigo_respuesta = 200 THEN 1 ELSE 0 END) as total_respuesta_positiva,
            SUM(CASE WHEN codigo_respuesta != 200 AND codigo_respuesta != 0 THEN 1 ELSE 0 END) as total_respuesta_negativa,
            COALESCE(SUM(CASE WHEN codigo_respuesta = 200 THEN monto ELSE 0 END), 0) as monto_exitoso
            FROM spei_transacciones";
        
        $result_stats_spei = $conn->query($sql_stats_spei);
        if ($result_stats_spei) {
            $stats_spei = $result_stats_spei->fetch_assoc();
        }
    }
    
    // Estadísticas combinadas para el resumen
    $estadisticas = [
        'total_pagos' => ($stats_paypal['total_pagos'] ?? 0) + ($stats_liga['total_pagos'] ?? 0) + ($stats_spei['total_transacciones'] ?? 0),
        'total_monto' => ($stats_paypal['total_monto'] ?? 0) + ($stats_liga['total_monto'] ?? 0) + ($stats_spei['total_monto'] ?? 0),
        'completados' => ($stats_paypal['completados'] ?? 0) + ($stats_liga['completados'] ?? 0) + ($stats_spei['total_respuesta_positiva'] ?? 0),
        'pendientes' => ($stats_paypal['pendientes'] ?? 0) + ($stats_liga['pendientes'] ?? 0),
        'fallidos' => ($stats_paypal['fallidos'] ?? 0) + ($stats_liga['fallidos'] ?? 0) + ($stats_spei['total_respuesta_negativa'] ?? 0),
        'monto_completados' => ($stats_paypal['monto_completados'] ?? 0) + ($stats_liga['monto_completados'] ?? 0) + ($stats_spei['monto_exitoso'] ?? 0)
    ];
    
} catch (Exception $e) {
    $mensaje = "Error al cargar las estadísticas: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

$conn->close();

// Funciones auxiliares
function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

function formatearMoneda($monto, $moneda = 'MXN')
{
    if ($monto === null) return 'N/A';
    return number_format($monto, 2) . ' ' . $moneda;
}

function claseEstado($estado)
{
    $estado_lower = strtolower($estado);
    switch ($estado_lower) {
        case 'completed':
        case 'approved':
        case 'a':
        case 'aprobado':
        case 'success':
            return 'success';
        case 'pending':
        case 'p':
        case 'pendiente':
            return 'warning';
        case 'created':
            return 'info';
        case 'failed':
        case 'denied':
        case 'd':
        case 'declined':
        case 'rejected':
        case 'fallido':
        case 'cancelled':
        case 'expired':
        case 'c':
        case 'error':
            return 'danger';
        default:
            return 'secondary';
    }
}

function textoEstado($estado)
{
    $estado_lower = strtolower($estado);
    $estados = [
        'completed' => 'Completado',
        'approved' => 'Completado',
        'a' => 'Completado',
        'aprobado' => 'Completado',
        'success' => 'Completado',
        'pending' => 'Pendiente',
        'p' => 'Pendiente',
        'pendiente' => 'Pendiente',
        'created' => 'Creado',
        'failed' => 'Fallido',
        'denied' => 'Fallido',
        'd' => 'Fallido',
        'declined' => 'Fallido',
        'rejected' => 'Fallido',
        'fallido' => 'Fallido',
        'cancelled' => 'Cancelado',
        'c' => 'Cancelado',
        'expired' => 'Expirado',
        'error' => 'Error'
    ];
    return $estados[$estado_lower] ?? $estado;
}

function truncarTexto($texto, $longitud = 30)
{
    if (strlen($texto) <= $longitud) return $texto;
    return substr($texto, 0, $longitud) . '...';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Pagos - Panel de Administración</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
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
            border-left: 4px solid var(--primary-color);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        .welcome-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
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
                box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
                transition: transform 0.3s ease-out;
            }

            body.sidebar-open main {
                transform: translateX(280px);
            }
            
            /* Estilos para vista móvil - Tarjetas */
            .desktop-view {
                display: none;
            }
            
            .mobile-view {
                display: block;
            }
            
            .pago-card {
                background: white;
                border-radius: 12px;
                padding: 1rem;
                margin-bottom: 1rem;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                transition: all 0.2s ease;
                border-left: 4px solid #27ae60;
            }
            
            .pago-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            }
            
            .pago-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 0.75rem;
                padding-bottom: 0.75rem;
                border-bottom: 1px solid #e9ecef;
            }
            
            .pago-id {
                font-family: monospace;
                font-size: 0.85rem;
                color: #6c757d;
                font-weight: 500;
            }
            
            .pago-fecha {
                font-size: 0.75rem;
                color: #6c757d;
            }
            
            .pago-info {
                margin-bottom: 0.75rem;
            }
            
            .pago-info-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 0.5rem;
                font-size: 0.875rem;
            }
            
            .pago-label {
                color: #6c757d;
                font-weight: 500;
            }
            
            .pago-value {
                font-weight: 500;
                color: #2c3e50;
                text-align: right;
                word-break: break-word;
                max-width: 60%;
            }
            
            .pago-monto {
                font-size: 1.25rem;
                font-weight: 700;
                color: #27ae60;
            }
            
            .pago-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 0.75rem;
                padding-top: 0.75rem;
                border-top: 1px solid #e9ecef;
            }
            
            .badge-estado-mobile {
                padding: 0.25rem 0.75rem;
                border-radius: 20px;
                font-size: 0.75rem;
                font-weight: 500;
            }
            
            .btn-detalle-mobile {
                padding: 0.25rem 0.75rem;
                font-size: 0.75rem;
            }
            
            .pago-transaccion {
                font-family: monospace;
                font-size: 0.7rem;
                color: #6c757d;
                word-break: break-all;
            }
        }
        
        @media (min-width: 768px) {
            .desktop-view {
                display: block;
            }
            
            .mobile-view {
                display: none;
            }
        }

        .badge-estado {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .table th {
            border-top: none;
            font-weight: 600;
            color: #2c3e50;
        }

        .filtros-card {
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .table-responsive {
            position: relative;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #27ae60 #f1f1f1;
        }

        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #27ae60;
            border-radius: 4px;
        }

        .monto-positivo {
            color: #27ae60;
            font-weight: 600;
        }

        .tooltip-custom {
            cursor: help;
            border-bottom: 1px dotted #999;
        }

        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: #27ae60;
            border-bottom: 3px solid #27ae60;
        }
        
        .nav-tabs .nav-link i {
            margin-right: 5px;
        }
        
        .tab-pane {
            padding-top: 20px;
        }
        
        .search-box {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .search-box label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }
        
        .loading-overlay {
            position: relative;
            min-height: 200px;
        }
        
        .loading-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
        }
        
        .table-container {
            position: relative;
        }
        
        .table-loading {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .filter-group {
            transition: all 0.3s ease;
        }
        
        .filter-group input, .filter-group select {
            transition: all 0.2s ease;
        }
        
        .pagination-info {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .page-link {
            cursor: pointer;
        }
        
        /* Grid para tarjetas en móvil */
        .cards-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../images/LibertyfinBlanco.png" alt="Logo" class="me-2" style="height: 30px;">
                <span>Panel de Administración</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu">
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
                        <li class="nav-item">
                            <a class="nav-link" href="empresas.php">
                                <i class="fas fa-building"></i>
                                Empresas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="activaciones.php">
                                <i class="fas fa-history"></i>
                                Activaciones
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
                                <i class="fas fa-file-alt"></i>
                                Solicitudes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="distribuidores.php">
                                <i class="fas fa-users"></i>
                                Distribuidores
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="pagos.php">
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
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Mensajes de alerta -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Welcome Card -->
                <div class="card welcome-card mb-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="me-3">
                                    <img src="../images/LibertyfinBlanco.png"
                                        alt="Logo Empresa"
                                        style="height: 40px; width: auto;">
                                </div>
                                <div>
                                    <h4 class="card-title mb-1">Panel de Pagos</h4>
                                    <div class="d-flex align-items-center">
                                        <p class="card-text mb-0 me-3 opacity-75">
                                            <i class="fas fa-user me-1"></i>
                                            Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Administrador'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="d-flex align-items-center">
                                    <div class="me-3 text-start">
                                        <small class="text-white-50 d-block">Total pagos</small>
                                        <span class="h5 mb-0 text-white"><?php echo $estadisticas['total_pagos']; ?></span>
                                    </div>
                                    <div>
                                        <i class="fas fa-chart-line fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas Combinadas -->
                <div class="row mb-4" id="estadisticasContainer">
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Pagos</div>
                                        <div class="metric-value text-primary" id="totalPagos"><?php echo $estadisticas['total_pagos']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-credit-card fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card bg-success text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label text-white-50">Total Ingresos</div>
                                        <div class="metric-value text-white" id="totalIngresos"><?php echo formatearMoneda($estadisticas['total_monto']); ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-dollar-sign fa-2x text-white opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Completados</div>
                                        <div class="metric-value text-success" id="completadosCount"><?php echo $estadisticas['completados']; ?></div>
                                        <small class="text-muted" id="completadosMonto"><?php echo formatearMoneda($estadisticas['monto_completados']); ?></small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Pendientes</div>
                                        <div class="metric-value text-warning" id="pendientesCount"><?php echo $estadisticas['pendientes']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clock fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Fallidos</div>
                                        <div class="metric-value text-danger" id="fallidosCount"><?php echo $estadisticas['fallidos']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-circle fa-2x text-danger opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Ticket Promedio</div>
                                        <div class="metric-value text-info" id="ticketPromedio">
                                            <?php
                                            $promedio = $estadisticas['completados'] > 0
                                                ? $estadisticas['monto_completados'] / $estadisticas['completados']
                                                : 0;
                                            echo formatearMoneda($promedio);
                                            ?>
                                        </div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-chart-line fa-2x text-info opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros Generales (Estado y Fechas) - SIN RECARGAR -->
                <div class="filtros-card mb-4">
                    <form id="filtrosForm" class="row g-3 align-items-end">
                        <div class="col-md-3 col-6">
                            <label class="form-label">Estado:</label>
                            <select name="estado" id="filtroEstado" class="form-select">
                                <option value="">Todos los estados</option>
                                <option value="COMPLETED">Completados</option>
                                <option value="PENDING" id="opcionPendiente">Pendientes</option>
                                <option value="FAILED">Fallidos</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Fecha inicio:</label>
                            <input type="date" name="fecha_inicio" id="filtroFechaInicio" class="form-control">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Fecha fin:</label>
                            <input type="date" name="fecha_fin" id="filtroFechaFin" class="form-control">
                        </div>
                        <div class="col-md-3 col-6">
                            <button type="button" id="btnLimpiarFiltros" class="btn btn-secondary w-100">
                                <i class="fas fa-times me-1"></i>Limpiar
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Selector de registros por página -->
                <div class="row mb-3">
                    <div class="col-md-6 col-12">
                        <div class="d-flex align-items-center gap-2 justify-content-md-start justify-content-between">
                            <label class="text-muted small fw-bold">Registros por página:</label>
                            <select id="registrosPorPagina" class="form-select form-select-sm w-auto">
                                <option value="5" selected>5</option>
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tabs para los tres tipos de pago -->
                <ul class="nav nav-tabs" id="pagosTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="paypal-tab" data-bs-toggle="tab" data-bs-target="#paypal" type="button" role="tab">
                            <i class="fab fa-paypal"></i> PayPal
                            <span class="badge bg-secondary ms-2" id="paypalTotalBadge"><?php echo $stats_paypal['total_pagos'] ?? 0; ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="liga-tab" data-bs-toggle="tab" data-bs-target="#liga" type="button" role="tab">
                            <i class="fas fa-link"></i> Liga
                            <span class="badge bg-secondary ms-2" id="ligaTotalBadge"><?php echo $stats_liga['total_pagos'] ?? 0; ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="spei-tab" data-bs-toggle="tab" data-bs-target="#spei" type="button" role="tab">
                            <i class="fas fa-exchange-alt"></i> Transferencias SPEI
                            <span class="badge bg-secondary ms-2" id="speiTotalBadge"><?php echo $stats_spei['total_transacciones'] ?? 0; ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="pagosTabsContent">
                    <!-- Tab PayPal -->
                    <div class="tab-pane fade show active" id="paypal" role="tabpanel" aria-labelledby="paypal-tab">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fab fa-paypal me-2 text-primary"></i>Pagos con PayPal
                                </h5>
                                <span class="badge bg-primary" id="paypalRegistrosCount">0 registros</span>
                            </div>
                            <div class="card-body">
                                <!-- Búsqueda específica para PayPal -->
                                <div class="search-box">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Buscar en PayPal:</label>
                                            <div class="input-group">
                                                <input type="text" id="busquedaPaypal" class="form-control" 
                                                    placeholder="ID de pago, email, nombre, transacción...">
                                                <button type="button" id="btnBuscarPaypal" class="btn btn-primary">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                                <button type="button" id="btnLimpiarBusquedaPaypal" class="btn btn-secondary" style="display: none;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-container">
                                    <div id="paypalLoading" class="text-center py-4" style="display: none;">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Cargando...</span>
                                        </div>
                                        <p class="text-muted mt-2">Cargando pagos PayPal...</p>
                                    </div>
                                    
                                    <!-- Vista Desktop (Tabla) -->
                                    <div id="paypalTablaContainer" class="desktop-view">
                                        <div class="table-responsive">
                                            <table class="table table-hover" id="paypalTabla">
                                                <thead>
                                                    <tr>
                                                        <th>ID Pago</th>
                                                        <th>Fecha</th>
                                                        <th>Pagador</th>
                                                        <th>Email</th>
                                                        <th>Monto</th>
                                                        <th>Estado</th>
                                                        <th>Transacción</th>
                                                        <th>Acciones</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="paypalTablaBody">
                                                    <tr>
                                                        <td colspan="8" class="text-center py-4">
                                                            <div class="text-muted">
                                                                <i class="fab fa-paypal fa-3x d-block mb-3"></i>
                                                                Selecciona filtros para cargar los datos
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <!-- Vista Móvil (Tarjetas) -->
                                    <div id="paypalCardsContainer" class="mobile-view">
                                        <div id="paypalCardsBody" class="cards-grid">
                                            <div class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fab fa-paypal fa-3x d-block mb-3"></i>
                                                    Selecciona filtros para cargar los datos
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Paginación PayPal -->
                                <div id="paypalPaginacion" class="mt-3" style="display: none;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Liga de Pago -->
                    <div class="tab-pane fade" id="liga" role="tabpanel" aria-labelledby="liga-tab">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-link me-2 text-info"></i>Pagos con Liga
                                </h5>
                                <span class="badge bg-primary" id="ligaRegistrosCount">0 registros</span>
                            </div>
                            <div class="card-body">
                                <!-- Búsqueda específica para Liga -->
                                <div class="search-box">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Buscar en Liga:</label>
                                            <div class="input-group">
                                                <input type="text" id="busquedaLiga" class="form-control" 
                                                    placeholder="Referencia, email, nombre, folio, autorización...">
                                                <button type="button" id="btnBuscarLiga" class="btn btn-primary">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                                <button type="button" id="btnLimpiarBusquedaLiga" class="btn btn-secondary" style="display: none;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-container">
                                    <div id="ligaLoading" class="text-center py-4" style="display: none;">
                                        <div class="spinner-border text-info" role="status">
                                            <span class="visually-hidden">Cargando...</span>
                                        </div>
                                        <p class="text-muted mt-2">Cargando pagos con Liga...</p>
                                    </div>
                                    
                                    <!-- Vista Desktop (Tabla) -->
                                    <div id="ligaTablaContainer" class="desktop-view">
                                        <div class="table-responsive">
                                            <table class="table table-hover" id="ligaTabla">
                                                <thead>
                                                    <tr>
                                                        <th>Folio</th>
                                                        <th>Fecha</th>
                                                        <th>Nombre</th>
                                                        <th>Email</th>
                                                        <th>Monto</th>
                                                        <th>Estado</th>
                                                        <th>Referencia</th>
                                                        <th>Autorización</th>
                                                        <th>Tarjeta</th>
                                                        <th>Acciones</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="ligaTablaBody">
                                                    <tr>
                                                        <td colspan="10" class="text-center py-4">
                                                            <div class="text-muted">
                                                                <i class="fas fa-link fa-3x d-block mb-3"></i>
                                                                Selecciona filtros para cargar los datos
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <!-- Vista Móvil (Tarjetas) -->
                                    <div id="ligaCardsContainer" class="mobile-view">
                                        <div id="ligaCardsBody" class="cards-grid">
                                            <div class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-link fa-3x d-block mb-3"></i>
                                                    Selecciona filtros para cargar los datos
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Paginación Liga -->
                                <div id="ligaPaginacion" class="mt-3" style="display: none;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Transferencias SPEI -->
                    <div class="tab-pane fade" id="spei" role="tabpanel" aria-labelledby="spei-tab">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-exchange-alt me-2 text-warning"></i>Transferencias SPEI
                                </h5>
                                <span class="badge bg-primary" id="speiRegistrosCount">0 registros</span>
                            </div>
                            <div class="card-body">
                                <!-- Búsqueda específica para SPEI -->
                                <div class="search-box">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Buscar en Transferencias:</label>
                                            <div class="input-group">
                                                <input type="text" id="busquedaSpei" class="form-control" 
                                                    placeholder="CLABE, transacción externa, autorización, mensaje...">
                                                <button type="button" id="btnBuscarSpei" class="btn btn-primary">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                                <button type="button" id="btnLimpiarBusquedaSpei" class="btn btn-secondary" style="display: none;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-container">
                                    <div id="speiLoading" class="text-center py-4" style="display: none;">
                                        <div class="spinner-border text-warning" role="status">
                                            <span class="visually-hidden">Cargando...</span>
                                        </div>
                                        <p class="text-muted mt-2">Cargando transferencias SPEI...</p>
                                    </div>
                                    
                                    <!-- Vista Desktop (Tabla) -->
                                    <div id="speiTablaContainer" class="desktop-view">
                                        <div class="table-responsive">
                                            <table class="table table-hover" id="speiTabla">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Fecha Solicitud</th>
                                                        <th>CLABE</th>
                                                        <th>Monto</th>
                                                        <th>Transacción Ext.</th>
                                                        <th>Código Resp.</th>
                                                        <th>Autorización</th>
                                                        <th>Mensaje Respuesta</th>
                                                        <th>Acciones</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="speiTablaBody">
                                                    <tr>
                                                        <td colspan="9" class="text-center py-4">
                                                            <div class="text-muted">
                                                                <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                                                                Selecciona filtros para cargar los datos
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <!-- Vista Móvil (Tarjetas) -->
                                    <div id="speiCardsContainer" class="mobile-view">
                                        <div id="speiCardsBody" class="cards-grid">
                                            <div class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                                                    Selecciona filtros para cargar los datos
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Paginación SPEI -->
                                <div id="speiPaginacion" class="mt-3" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal PayPal -->
    <div class="modal fade" id="modalDetallePayPal" tabindex="-1" aria-labelledby="modalDetallePayPalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalDetallePayPalLabel">
                        <i class="fab fa-paypal me-2"></i>Detalles del Pago PayPal
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="detallePayPalCargando" class="text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="text-muted">Cargando información del pago...</p>
                    </div>
                    <div id="detallePayPalContenido" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Liga -->
    <div class="modal fade" id="modalDetalleLiga" tabindex="-1" aria-labelledby="modalDetalleLigaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="modalDetalleLigaLabel">
                        <i class="fas fa-link me-2"></i>Detalles del Pago con Liga
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="detalleLigaCargando" class="text-center py-4">
                        <div class="spinner-border text-info mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="text-muted">Cargando información del pago...</p>
                    </div>
                    <div id="detalleLigaContenido" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal SPEI -->
    <div class="modal fade" id="modalDetalleSpei" tabindex="-1" aria-labelledby="modalDetalleSpeiLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="modalDetalleSpeiLabel">
                        <i class="fas fa-exchange-alt me-2"></i>Detalles de la Transferencia SPEI
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="detalleSpeiCargando" class="text-center py-4">
                        <div class="spinner-border text-warning mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="text-muted">Cargando información de la transferencia...</p>
                    </div>
                    <div id="detalleSpeiContenido" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
// =============================================
// VARIABLES GLOBALES
// =============================================
let tabActiva = 'paypal';
let paginaPaypal = 1;
let paginaLiga = 1;
let paginaSpei = 1;
let registrosPorPagina = 5;

// =============================================
// FUNCIONES DE CARGA AJAX
// =============================================

function cargarPayPal() {
    const filtroEstado = $('#filtroEstado').val();
    const filtroFechaInicio = $('#filtroFechaInicio').val();
    const filtroFechaFin = $('#filtroFechaFin').val();
    const busqueda = $('#busquedaPaypal').val();
    
    $('#paypalLoading').show();
    $('#paypalTablaContainer, #paypalCardsContainer').addClass('opacity-50');
    
    $.ajax({
        url: 'ajax_pagos.php',
        type: 'POST',
        data: {
            action: 'get_paypal',
            pagina: paginaPaypal,
            registros_por_pagina: registrosPorPagina,
            estado: filtroEstado,
            fecha_inicio: filtroFechaInicio,
            fecha_fin: filtroFechaFin,
            busqueda: busqueda
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                actualizarTablaPayPal(response);
                actualizarCardsPayPal(response);
                actualizarPaginacionPayPal(response.total_paginas, response.pagina_actual, response.total_registros);
                $('#paypalRegistrosCount').text(response.total_registros + ' registros');
            } else {
                mostrarErrorPayPal(response.message || 'Error al cargar los datos');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error AJAX:', error);
            mostrarErrorPayPal('Error de conexión al servidor');
        },
        complete: function() {
            $('#paypalLoading').hide();
            $('#paypalTablaContainer, #paypalCardsContainer').removeClass('opacity-50');
        }
    });
}

function cargarLiga() {
    const filtroEstado = $('#filtroEstado').val();
    const filtroFechaInicio = $('#filtroFechaInicio').val();
    const filtroFechaFin = $('#filtroFechaFin').val();
    const busqueda = $('#busquedaLiga').val();
    
    $('#ligaLoading').show();
    $('#ligaTablaContainer, #ligaCardsContainer').addClass('opacity-50');
    
    $.ajax({
        url: 'ajax_pagos.php',
        type: 'POST',
        data: {
            action: 'get_liga',
            pagina: paginaLiga,
            registros_por_pagina: registrosPorPagina,
            estado: filtroEstado,
            fecha_inicio: filtroFechaInicio,
            fecha_fin: filtroFechaFin,
            busqueda: busqueda
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                actualizarTablaLiga(response);
                actualizarCardsLiga(response);
                actualizarPaginacionLiga(response.total_paginas, response.pagina_actual, response.total_registros);
                $('#ligaRegistrosCount').text(response.total_registros + ' registros');
            } else {
                mostrarErrorLiga(response.message || 'Error al cargar los datos');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error AJAX:', error);
            mostrarErrorLiga('Error de conexión al servidor');
        },
        complete: function() {
            $('#ligaLoading').hide();
            $('#ligaTablaContainer, #ligaCardsContainer').removeClass('opacity-50');
        }
    });
}

function cargarSpei() {
    const filtroEstado = $('#filtroEstado').val();
    const filtroFechaInicio = $('#filtroFechaInicio').val();
    const filtroFechaFin = $('#filtroFechaFin').val();
    const busqueda = $('#busquedaSpei').val();
    
    $('#speiLoading').show();
    $('#speiTablaContainer, #speiCardsContainer').addClass('opacity-50');
    
    $.ajax({
        url: 'ajax_pagos.php',
        type: 'POST',
        data: {
            action: 'get_spei',
            pagina: paginaSpei,
            registros_por_pagina: registrosPorPagina,
            estado: filtroEstado,
            fecha_inicio: filtroFechaInicio,
            fecha_fin: filtroFechaFin,
            busqueda: busqueda
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                actualizarTablaSpei(response);
                actualizarCardsSpei(response);
                actualizarPaginacionSpei(response.total_paginas, response.pagina_actual, response.total_registros);
                $('#speiRegistrosCount').text(response.total_registros + ' registros');
            } else {
                mostrarErrorSpei(response.message || 'Error al cargar los datos');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error AJAX:', error);
            mostrarErrorSpei('Error de conexión al servidor');
        },
        complete: function() {
            $('#speiLoading').hide();
            $('#speiTablaContainer, #speiCardsContainer').removeClass('opacity-50');
        }
    });
}

// Actualizar tabla de PayPal (Desktop)
function actualizarTablaPayPal(data) {
    const tbody = $('#paypalTablaBody');
    tbody.empty();
    
    if (data.pagos.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="8" class="text-center py-4">
                    <div class="text-muted">
                        <i class="fab fa-paypal fa-3x d-block mb-3"></i>
                        No se encontraron pagos con PayPal
                    </div>
                </td>
            </tr>
        `);
        return;
    }
    
    data.pagos.forEach(pago => {
        const estadoClass = getEstadoClass(pago.status);
        const estadoText = getEstadoText(pago.status);
        const montoFormateado = formatearMoneda(pago.amount, pago.currency || 'MXN');
        
        // Escapar datos para HTML
        const paymentIdEscaped = escapeHtml(pago.payment_id);
        const payerNameEscaped = escapeHtml(pago.payer_name || 'N/A');
        const payerEmailEscaped = escapeHtml(pago.payer_email || 'N/A');
        const transactionIdEscaped = escapeHtml(pago.transaction_id || 'N/A');
        
        // Preparar JSON para atributos data
        const paymentDataJson = pago.payment_data ? JSON.stringify(pago.payment_data) : '{}';
        const cartDataJson = pago.cart_data ? JSON.stringify(pago.cart_data) : '{}';
        const webhookDataJson = pago.webhook_data ? JSON.stringify(pago.webhook_data) : '{}';
        
        tbody.append(`
            <tr>
                <td>
                    <span class="tooltip-custom" title="${paymentIdEscaped}">
                        ${truncarTexto(paymentIdEscaped, 15)}
                    </span>
                </td>
                <td>${formatearFecha(pago.created_at)}</td>
                <td>${payerNameEscaped}</td>
                <td>${payerEmailEscaped ? `<a href="mailto:${payerEmailEscaped}">${truncarTexto(payerEmailEscaped, 20)}</a>` : 'N/A'}</td>
                <td><span class="monto-positivo">${montoFormateado}</span></td>
                <td><span class="badge bg-${estadoClass} badge-estado">${estadoText}</span></td>
                <td>${transactionIdEscaped ? `<span class="tooltip-custom" title="${transactionIdEscaped}">${truncarTexto(transactionIdEscaped, 12)}</span>` : 'N/A'}</td>
                <td>
                    <button class="btn btn-sm btn-info ver-detalle-paypal"
                        data-id="${pago.id}"
                        data-payment-id='${paymentIdEscaped}'
                        data-payer='${payerNameEscaped}'
                        data-email='${payerEmailEscaped}'
                        data-amount="${pago.amount}"
                        data-currency="${pago.currency || 'MXN'}"
                        data-status="${pago.status}"
                        data-transaction='${transactionIdEscaped}'
                        data-created="${formatearFecha(pago.created_at)}"
                        data-updated="${formatearFecha(pago.updated_at)}"
                        data-payment-data='${paymentDataJson.replace(/'/g, "\\'")}'
                        data-cart-data='${cartDataJson.replace(/'/g, "\\'")}'
                        data-webhook-data='${webhookDataJson.replace(/'/g, "\\'")}'>
                        <i class="fas fa-eye"></i>
                    </button>
                 </td>
             </tr>
        `);
    });
}

// Actualizar tarjetas de PayPal (Móvil)
function actualizarCardsPayPal(data) {
    const container = $('#paypalCardsBody');
    container.empty();
    
    if (data.pagos.length === 0) {
        container.html(`
            <div class="text-center py-4">
                <div class="text-muted">
                    <i class="fab fa-paypal fa-3x d-block mb-3"></i>
                    No se encontraron pagos con PayPal
                </div>
            </div>
        `);
        return;
    }
    
    data.pagos.forEach(pago => {
        const estadoClass = getEstadoClass(pago.status);
        const estadoText = getEstadoText(pago.status);
        const montoFormateado = formatearMoneda(pago.amount, pago.currency || 'MXN');
        const fechaFormateada = formatearFecha(pago.created_at);
        
        // Escapar datos para HTML
        const paymentIdEscaped = escapeHtml(pago.payment_id);
        const payerNameEscaped = escapeHtml(pago.payer_name || 'N/A');
        const payerEmailEscaped = escapeHtml(pago.payer_email || 'N/A');
        const transactionIdEscaped = escapeHtml(pago.transaction_id || 'N/A');
        
        // Preparar JSON para atributos data
        const paymentDataJson = pago.payment_data ? JSON.stringify(pago.payment_data) : '{}';
        const cartDataJson = pago.cart_data ? JSON.stringify(pago.cart_data) : '{}';
        const webhookDataJson = pago.webhook_data ? JSON.stringify(pago.webhook_data) : '{}';
        
        container.append(`
            <div class="pago-card">
                <div class="pago-header">
                    <span class="pago-id">
                        <i class="fab fa-paypal me-1 text-primary"></i>
                        ${truncarTexto(paymentIdEscaped, 20)}
                    </span>
                    <span class="pago-fecha">${fechaFormateada}</span>
                </div>
                <div class="pago-info">
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-user me-1"></i> Pagador:</span>
                        <span class="pago-value">${payerNameEscaped}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-envelope me-1"></i> Email:</span>
                        <span class="pago-value">${payerEmailEscaped ? truncarTexto(payerEmailEscaped, 25) : 'N/A'}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-money-bill-wave me-1"></i> Monto:</span>
                        <span class="pago-value pago-monto">${montoFormateado}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-exchange-alt me-1"></i> Transacción:</span>
                        <span class="pago-value pago-transaccion">${transactionIdEscaped ? truncarTexto(transactionIdEscaped, 15) : 'N/A'}</span>
                    </div>
                </div>
                <div class="pago-footer">
                    <span class="badge bg-${estadoClass} badge-estado-mobile">${estadoText}</span>
                    <button class="btn btn-sm btn-info btn-detalle-mobile ver-detalle-paypal-mobile"
                        data-id="${pago.id}"
                        data-payment-id='${paymentIdEscaped}'
                        data-payer='${payerNameEscaped}'
                        data-email='${payerEmailEscaped}'
                        data-amount="${pago.amount}"
                        data-currency="${pago.currency || 'MXN'}"
                        data-status="${pago.status}"
                        data-transaction='${transactionIdEscaped}'
                        data-created="${fechaFormateada}"
                        data-updated="${formatearFecha(pago.updated_at)}"
                        data-payment-data='${paymentDataJson.replace(/'/g, "\\'")}'
                        data-cart-data='${cartDataJson.replace(/'/g, "\\'")}'
                        data-webhook-data='${webhookDataJson.replace(/'/g, "\\'")}'>
                        <i class="fas fa-eye me-1"></i>Ver detalle
                    </button>
                </div>
            </div>
        `);
    });
}

// Actualizar tabla de Liga (Desktop)
function actualizarTablaLiga(data) {
    const tbody = $('#ligaTablaBody');
    tbody.empty();
    
    if (data.pagos.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="10" class="text-center py-4">
                    <div class="text-muted">
                        <i class="fas fa-link fa-3x d-block mb-3"></i>
                        No se encontraron pagos con Liga
                    </div>
                 </td>
             </tr>
        `);
        return;
    }
    
    data.pagos.forEach(pago => {
        const estadoClass = getEstadoClass(pago.response);
        const estadoText = getEstadoText(pago.response);
        const montoFormateado = formatearMoneda(pago.amount, 'MXN');
        
        // Escapar datos para HTML
        const folioEscaped = escapeHtml(pago.foliocpagos || '');
        const ccNameEscaped = escapeHtml(pago.cc_name || 'N/A');
        const emailEscaped = escapeHtml(pago.email || '');
        const referenceEscaped = escapeHtml(pago.reference || '');
        const authEscaped = escapeHtml(pago.auth || '');
        const ccMaskEscaped = escapeHtml(pago.cc_mask || '');
        const ccTypeEscaped = escapeHtml(pago.cc_type || '');
        
        // Preparar JSON para atributos data
        const rawResponseJson = pago.raw_response ? JSON.stringify(pago.raw_response) : '{}';
        
        tbody.append(`
            <tr>
                <td>${truncarTexto(folioEscaped || 'N/A', 12)}</td>
                <td>${formatearFecha(pago.fecha_registro)}</td>
                <td>${ccNameEscaped}</td>
                <td>${emailEscaped ? `<a href="mailto:${emailEscaped}">${truncarTexto(emailEscaped, 20)}</a>` : 'N/A'}</td>
                <td><span class="monto-positivo">${montoFormateado}</span></td>
                <td><span class="badge bg-${estadoClass} badge-estado">${estadoText}</span></td>
                <td>${referenceEscaped ? truncarTexto(referenceEscaped, 10) : 'N/A'}</td>
                <td>${authEscaped ? `<span class="badge bg-success">${authEscaped}</span>` : 'N/A'}</td>
                <td>${ccMaskEscaped ? `<span class="tooltip-custom" title="${ccTypeEscaped}"><i class="fas fa-credit-card me-1"></i>${ccMaskEscaped}</span>` : 'N/A'}</td>
                <td>
                    <button class="btn btn-sm btn-info ver-detalle-liga"
                        data-id="${pago.id}"
                        data-folio='${folioEscaped}'
                        data-reference='${referenceEscaped}'
                        data-response="${pago.response}"
                        data-auth='${authEscaped}'
                        data-cc-name='${ccNameEscaped}'
                        data-email='${emailEscaped}'
                        data-amount="${pago.amount}"
                        data-cc-type='${ccTypeEscaped}'
                        data-cc-mask='${ccMaskEscaped}'
                        data-fecha="${formatearFecha(pago.fecha_registro)}"
                        data-raw-response='${rawResponseJson.replace(/'/g, "\\'")}'
                        data-cd-response="${escapeHtml(pago.cd_response || '')}"
                        data-cd-error="${escapeHtml(pago.cd_error || '')}"
                        data-nb-error="${escapeHtml(pago.nb_error || '')}"
                        data-nb-company="${escapeHtml(pago.nb_company || '')}"
                        data-nb-merchant="${escapeHtml(pago.nb_merchant || '')}">
                        <i class="fas fa-eye"></i>
                    </button>
                 </td>
             </tr>
        `);
    });
}

// Actualizar tarjetas de Liga (Móvil)
function actualizarCardsLiga(data) {
    const container = $('#ligaCardsBody');
    container.empty();
    
    if (data.pagos.length === 0) {
        container.html(`
            <div class="text-center py-4">
                <div class="text-muted">
                    <i class="fas fa-link fa-3x d-block mb-3"></i>
                    No se encontraron pagos con Liga
                </div>
            </div>
        `);
        return;
    }
    
    data.pagos.forEach(pago => {
        const estadoClass = getEstadoClass(pago.response);
        const estadoText = getEstadoText(pago.response);
        const montoFormateado = formatearMoneda(pago.amount, 'MXN');
        const fechaFormateada = formatearFecha(pago.fecha_registro);
        
        // Escapar datos para HTML
        const folioEscaped = escapeHtml(pago.foliocpagos || '');
        const referenceEscaped = escapeHtml(pago.reference || '');
        const authEscaped = escapeHtml(pago.auth || '');
        const ccNameEscaped = escapeHtml(pago.cc_name || '');
        const emailEscaped = escapeHtml(pago.email || '');
        const ccTypeEscaped = escapeHtml(pago.cc_type || '');
        const ccMaskEscaped = escapeHtml(pago.cc_mask || '');
        const cdResponseEscaped = escapeHtml(pago.cd_response || '');
        const cdErrorEscaped = escapeHtml(pago.cd_error || '');
        const nbErrorEscaped = escapeHtml(pago.nb_error || '');
        const nbCompanyEscaped = escapeHtml(pago.nb_company || '');
        const nbMerchantEscaped = escapeHtml(pago.nb_merchant || '');
        
        // Preparar JSON para atributos data
        const rawResponseJson = pago.raw_response ? JSON.stringify(pago.raw_response) : '{}';
        
        container.append(`
            <div class="pago-card">
                <div class="pago-header">
                    <span class="pago-id">
                        <i class="fas fa-link me-1 text-info"></i>
                        ${truncarTexto(folioEscaped || 'Sin folio', 20)}
                    </span>
                    <span class="pago-fecha">${fechaFormateada}</span>
                </div>
                <div class="pago-info">
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-user me-1"></i> Nombre:</span>
                        <span class="pago-value">${ccNameEscaped || 'N/A'}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-envelope me-1"></i> Email:</span>
                        <span class="pago-value">${emailEscaped ? truncarTexto(emailEscaped, 25) : 'N/A'}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-money-bill-wave me-1"></i> Monto:</span>
                        <span class="pago-value pago-monto">${montoFormateado}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-qrcode me-1"></i> Referencia:</span>
                        <span class="pago-value pago-transaccion">${referenceEscaped ? truncarTexto(referenceEscaped, 15) : 'N/A'}</span>
                    </div>
                    ${ccMaskEscaped ? `
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-credit-card me-1"></i> Tarjeta:</span>
                        <span class="pago-value">${ccMaskEscaped}</span>
                    </div>
                    ` : ''}
                    ${authEscaped ? `
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-key me-1"></i> Autorización:</span>
                        <span class="pago-value"><span class="badge bg-success">${authEscaped}</span></span>
                    </div>
                    ` : ''}
                </div>
                <div class="pago-footer">
                    <span class="badge bg-${estadoClass} badge-estado-mobile">${estadoText}</span>
                    <button class="btn btn-sm btn-info btn-detalle-mobile ver-detalle-liga-mobile"
                        data-id="${pago.id}"
                        data-folio='${folioEscaped}'
                        data-reference='${referenceEscaped}'
                        data-response="${pago.response}"
                        data-auth='${authEscaped}'
                        data-cc-name='${ccNameEscaped}'
                        data-email='${emailEscaped}'
                        data-amount="${pago.amount}"
                        data-cc-type='${ccTypeEscaped}'
                        data-cc-mask='${ccMaskEscaped}'
                        data-fecha="${fechaFormateada}"
                        data-raw-response='${rawResponseJson.replace(/'/g, "\\'")}'
                        data-cd-response='${cdResponseEscaped}'
                        data-cd-error='${cdErrorEscaped}'
                        data-nb-error='${nbErrorEscaped}'
                        data-nb-company='${nbCompanyEscaped}'
                        data-nb-merchant='${nbMerchantEscaped}'>
                        <i class="fas fa-eye me-1"></i>Ver detalle
                    </button>
                </div>
            </div>
        `);
    });
}

// Actualizar tabla SPEI (Desktop)
function actualizarTablaSpei(data) {
    const tbody = $('#speiTablaBody');
    tbody.empty();
    
    if (data.transacciones.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="9" class="text-center py-4">
                    <div class="text-muted">
                        <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                        No se encontraron transferencias SPEI
                    </div>
                </td>
            </tr>
        `);
        return;
    }
    
    data.transacciones.forEach(trans => {
        const estadoClass = trans.codigo_respuesta == 200 ? 'success' : 'danger';
        const estadoText = trans.codigo_respuesta == 200 ? 'Completado' : 'Fallido';
        const montoFormateado = formatearMoneda(trans.monto, 'MXN');
        
        // Escapar datos para HTML
        const clabeEscaped = escapeHtml(trans.clabe);
        const transaccionExternaEscaped = escapeHtml(trans.transaccion_externa);
        const autorizacionEscaped = escapeHtml(trans.autorizacion || 'N/A');
        const mensajeRespuestaEscaped = escapeHtml(trans.mensaje_respuesta);
        
        tbody.append(`
            <tr>
                <td>${trans.id}</td>
                <td>${formatearFecha(trans.fecha_solicitud)}</td>
                <td><span class="tooltip-custom" title="${clabeEscaped}">${truncarTexto(clabeEscaped, 12)}</span></td>
                <td><span class="monto-positivo">${montoFormateado}</span></td>
                <td><span class="tooltip-custom" title="${transaccionExternaEscaped}">${truncarTexto(transaccionExternaEscaped, 15)}</span></td>
                <td><span class="badge bg-${estadoClass} badge-estado">${trans.codigo_respuesta}</span></td>
                <td>${autorizacionEscaped ? `<span class="badge bg-success">${autorizacionEscaped}</span>` : 'N/A'}</td>
                <td><span class="tooltip-custom" title="${mensajeRespuestaEscaped}">${truncarTexto(mensajeRespuestaEscaped, 20)}</span></td>
                <td>
                    <button class="btn btn-sm btn-info ver-detalle-spei"
                        data-id="${trans.id}"
                        data-clabe="${clabeEscaped}"
                        data-monto="${trans.monto}"
                        data-transaccion-externa="${transaccionExternaEscaped}"
                        data-codigo-respuesta="${trans.codigo_respuesta}"
                        data-autorizacion="${autorizacionEscaped}"
                        data-mensaje-respuesta="${mensajeRespuestaEscaped}"
                        data-fecha-solicitud="${formatearFecha(trans.fecha_solicitud)}"
                        data-fecha-respuesta="${trans.fecha_respuesta || 'No registrada'}"
                        data-ip-origen="${escapeHtml(trans.ip_origen || 'N/A')}"
                        data-user-agent="${escapeHtml(trans.user_agent || 'N/A')}"
                        data-fecha-registro="${formatearFecha(trans.fecha_registro)}">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `);
    });
}

// Actualizar tarjetas SPEI (Móvil)
function actualizarCardsSpei(data) {
    const container = $('#speiCardsBody');
    container.empty();
    
    if (data.transacciones.length === 0) {
        container.html(`
            <div class="text-center py-4">
                <div class="text-muted">
                    <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                    No se encontraron transferencias SPEI
                </div>
            </div>
        `);
        return;
    }
    
    data.transacciones.forEach(trans => {
        const estadoClass = trans.codigo_respuesta == 200 ? 'success' : 'danger';
        const estadoText = trans.codigo_respuesta == 200 ? 'Completado' : 'Fallido';
        const montoFormateado = formatearMoneda(trans.monto, 'MXN');
        const fechaFormateada = formatearFecha(trans.fecha_solicitud);
        
        // Escapar datos para HTML
        const clabeEscaped = escapeHtml(trans.clabe);
        const transaccionExternaEscaped = escapeHtml(trans.transaccion_externa);
        const autorizacionEscaped = escapeHtml(trans.autorizacion || 'N/A');
        const mensajeRespuestaEscaped = escapeHtml(trans.mensaje_respuesta);
        
        container.append(`
            <div class="pago-card">
                <div class="pago-header">
                    <span class="pago-id">
                        <i class="fas fa-exchange-alt me-1 text-warning"></i>
                        ID: ${trans.id}
                    </span>
                    <span class="pago-fecha">${fechaFormateada}</span>
                </div>
                <div class="pago-info">
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-university me-1"></i> CLABE:</span>
                        <span class="pago-value pago-transaccion">${truncarTexto(clabeEscaped, 20)}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-money-bill-wave me-1"></i> Monto:</span>
                        <span class="pago-value pago-monto">${montoFormateado}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-exchange-alt me-1"></i> Transacción:</span>
                        <span class="pago-value pago-transaccion">${truncarTexto(transaccionExternaEscaped, 20)}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-key me-1"></i> Autorización:</span>
                        <span class="pago-value">${autorizacionEscaped !== 'N/A' ? `<span class="badge bg-success">${autorizacionEscaped}</span>` : 'N/A'}</span>
                    </div>
                    <div class="pago-info-row">
                        <span class="pago-label"><i class="fas fa-comment me-1"></i> Mensaje:</span>
                        <span class="pago-value pago-transaccion">${truncarTexto(mensajeRespuestaEscaped, 30)}</span>
                    </div>
                </div>
                <div class="pago-footer">
                    <span class="badge bg-${estadoClass} badge-estado-mobile">${estadoText}</span>
                    <button class="btn btn-sm btn-info btn-detalle-mobile ver-detalle-spei-mobile"
                        data-id="${trans.id}"
                        data-clabe="${clabeEscaped}"
                        data-monto="${trans.monto}"
                        data-transaccion-externa="${transaccionExternaEscaped}"
                        data-codigo-respuesta="${trans.codigo_respuesta}"
                        data-autorizacion="${autorizacionEscaped}"
                        data-mensaje-respuesta="${mensajeRespuestaEscaped}"
                        data-fecha-solicitud="${fechaFormateada}"
                        data-fecha-respuesta="${trans.fecha_respuesta || 'No registrada'}"
                        data-ip-origen="${escapeHtml(trans.ip_origen || 'N/A')}"
                        data-user-agent="${escapeHtml(trans.user_agent || 'N/A')}">
                        <i class="fas fa-eye me-1"></i>Ver detalle
                    </button>
                </div>
            </div>
        `);
    });
}

// Actualizar paginación PayPal
function actualizarPaginacionPayPal(totalPaginas, paginaActual, totalRegistros) {
    const container = $('#paypalPaginacion');
    
    if (totalPaginas <= 1 && totalRegistros <= registrosPorPagina) {
        container.hide();
        return;
    }
    
    container.show();
    
    let html = '<nav aria-label="Paginación PayPal"><ul class="pagination justify-content-center flex-wrap">';
    
    html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="1">
                    <i class="fas fa-angle-double-left"></i>
                </a>
             </li>`;
    
    html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${paginaActual - 1}">
                    <i class="fas fa-chevron-left"></i>
                </a>
             </li>`;
    
    let startPage = Math.max(1, paginaActual - 2);
    let endPage = Math.min(totalPaginas, startPage + 4);
    
    if (endPage - startPage < 4 && startPage > 1) {
        startPage = Math.max(1, endPage - 4);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<li class="page-item ${i == paginaActual ? 'active' : ''}">
                    <a class="page-link" href="#" data-pagina="${i}">${i}</a>
                 </li>`;
    }
    
    html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${paginaActual + 1}">
                    <i class="fas fa-chevron-right"></i>
                </a>
             </li>`;
    
    html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${totalPaginas}">
                    <i class="fas fa-angle-double-right"></i>
                </a>
             </li>`;
    
    html += '</ul>';
    
    const desde = ((paginaActual - 1) * registrosPorPagina) + 1;
    const hasta = Math.min(paginaActual * registrosPorPagina, totalRegistros);
    html += `<div class="text-center text-muted mt-2 small">
                Mostrando ${desde} - ${hasta} de ${totalRegistros} registros
            </div>`;
    
    html += '</nav>';
    container.html(html);
    
    container.find('.page-link').click(function(e) {
        e.preventDefault();
        const nuevaPagina = parseInt($(this).data('pagina'));
        if (!isNaN(nuevaPagina) && nuevaPagina != paginaPaypal && nuevaPagina >= 1 && nuevaPagina <= totalPaginas) {
            paginaPaypal = nuevaPagina;
            cargarPayPal();
            $('html, body').animate({
                scrollTop: $('#paypal').offset().top - 100
            }, 300);
        }
    });
}

// Actualizar paginación Liga
function actualizarPaginacionLiga(totalPaginas, paginaActual, totalRegistros) {
    const container = $('#ligaPaginacion');
    
    if (totalPaginas <= 1 && totalRegistros <= registrosPorPagina) {
        container.hide();
        return;
    }
    
    container.show();
    
    let html = '<nav aria-label="Paginación Liga"><ul class="pagination justify-content-center flex-wrap">';
    
    html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="1">
                    <i class="fas fa-angle-double-left"></i>
                </a>
             </li>`;
    
    html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${paginaActual - 1}">
                    <i class="fas fa-chevron-left"></i>
                </a>
             </li>`;
    
    let startPage = Math.max(1, paginaActual - 2);
    let endPage = Math.min(totalPaginas, startPage + 4);
    
    if (endPage - startPage < 4 && startPage > 1) {
        startPage = Math.max(1, endPage - 4);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<li class="page-item ${i == paginaActual ? 'active' : ''}">
                    <a class="page-link" href="#" data-pagina="${i}">${i}</a>
                 </li>`;
    }
    
    html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${paginaActual + 1}">
                    <i class="fas fa-chevron-right"></i>
                </a>
             </li>`;
    
    html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${totalPaginas}">
                    <i class="fas fa-angle-double-right"></i>
                </a>
             </li>`;
    
    html += '</ul>';
    
    const desde = ((paginaActual - 1) * registrosPorPagina) + 1;
    const hasta = Math.min(paginaActual * registrosPorPagina, totalRegistros);
    html += `<div class="text-center text-muted mt-2 small">
                Mostrando ${desde} - ${hasta} de ${totalRegistros} registros
            </div>`;
    
    html += '</nav>';
    container.html(html);
    
    container.find('.page-link').click(function(e) {
        e.preventDefault();
        const nuevaPagina = parseInt($(this).data('pagina'));
        if (!isNaN(nuevaPagina) && nuevaPagina != paginaLiga && nuevaPagina >= 1 && nuevaPagina <= totalPaginas) {
            paginaLiga = nuevaPagina;
            cargarLiga();
            $('html, body').animate({
                scrollTop: $('#liga').offset().top - 100
            }, 300);
        }
    });
}

// Actualizar paginación SPEI
function actualizarPaginacionSpei(totalPaginas, paginaActual, totalRegistros) {
    const container = $('#speiPaginacion');
    
    if (totalPaginas <= 1 && totalRegistros <= registrosPorPagina) {
        container.hide();
        return;
    }
    
    container.show();
    
    let html = '<nav aria-label="Paginación SPEI"><ul class="pagination justify-content-center flex-wrap">';
    
    html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="1">
                    <i class="fas fa-angle-double-left"></i>
                </a>
             </li>`;
    
    html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${paginaActual - 1}">
                    <i class="fas fa-chevron-left"></i>
                </a>
             </li>`;
    
    let startPage = Math.max(1, paginaActual - 2);
    let endPage = Math.min(totalPaginas, startPage + 4);
    
    if (endPage - startPage < 4 && startPage > 1) {
        startPage = Math.max(1, endPage - 4);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<li class="page-item ${i == paginaActual ? 'active' : ''}">
                    <a class="page-link" href="#" data-pagina="${i}">${i}</a>
                 </li>`;
    }
    
    html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${paginaActual + 1}">
                    <i class="fas fa-chevron-right"></i>
                </a>
             </li>`;
    
    html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                <a class="page-link" href="#" data-pagina="${totalPaginas}">
                    <i class="fas fa-angle-double-right"></i>
                </a>
             </li>`;
    
    html += '</ul>';
    
    const desde = ((paginaActual - 1) * registrosPorPagina) + 1;
    const hasta = Math.min(paginaActual * registrosPorPagina, totalRegistros);
    html += `<div class="text-center text-muted mt-2 small">
                Mostrando ${desde} - ${hasta} de ${totalRegistros} registros
            </div>`;
    
    html += '</nav>';
    container.html(html);
    
    container.find('.page-link').click(function(e) {
        e.preventDefault();
        const nuevaPagina = parseInt($(this).data('pagina'));
        if (!isNaN(nuevaPagina) && nuevaPagina != paginaSpei && nuevaPagina >= 1 && nuevaPagina <= totalPaginas) {
            paginaSpei = nuevaPagina;
            cargarSpei();
            $('html, body').animate({
                scrollTop: $('#spei').offset().top - 100
            }, 300);
        }
    });
}

// Mostrar error en PayPal
function mostrarErrorPayPal(mensaje) {
    $('#paypalTablaBody').html(`
        <tr>
            <td colspan="8" class="text-center py-4">
                <div class="text-danger">${mensaje}</div>
            </td>
        </tr>
    `);
    $('#paypalCardsBody').html(`
        <div class="text-center py-4">
            <div class="text-danger">${mensaje}</div>
        </div>
    `);
}

// Mostrar error en Liga
function mostrarErrorLiga(mensaje) {
    $('#ligaTablaBody').html(`
        <tr>
            <td colspan="10" class="text-center py-4">
                <div class="text-danger">${mensaje}</div>
            </td>
        </tr>
    `);
    $('#ligaCardsBody').html(`
        <div class="text-center py-4">
            <div class="text-danger">${mensaje}</div>
        </div>
    `);
}

// Mostrar error en SPEI
function mostrarErrorSpei(mensaje) {
    $('#speiTablaBody').html(`
        <tr>
            <td colspan="9" class="text-center py-4">
                <div class="text-danger">${mensaje}</div>
            </td>
        </tr>
    `);
    $('#speiCardsBody').html(`
        <div class="text-center py-4">
            <div class="text-danger">${mensaje}</div>
        </div>
    `);
}

// =============================================
// FUNCIONES AUXILIARES
// =============================================

function getEstadoClass(estado) {
    const estadoLower = String(estado).toLowerCase();
    if (['completed', 'approved', 'a', 'aprobado', 'success'].includes(estadoLower)) {
        return 'success';
    }
    if (['pending', 'p', 'pendiente'].includes(estadoLower)) {
        return 'warning';
    }
    if (['created'].includes(estadoLower)) {
        return 'info';
    }
    if (['failed', 'denied', 'd', 'declined', 'rejected', 'fallido', 'cancelled', 'expired', 'c', 'error'].includes(estadoLower)) {
        return 'danger';
    }
    return 'secondary';
}

function getEstadoText(estado) {
    const estadoLower = String(estado).toLowerCase();
    const estados = {
        'completed': 'Completado',
        'approved': 'Completado',
        'a': 'Completado',
        'aprobado': 'Completado',
        'success': 'Completado',
        'pending': 'Pendiente',
        'p': 'Pendiente',
        'pendiente': 'Pendiente',
        'created': 'Creado',
        'failed': 'Fallido',
        'denied': 'Fallido',
        'd': 'Fallido',
        'declined': 'Fallido',
        'rejected': 'Fallido',
        'fallido': 'Fallido',
        'cancelled': 'Cancelado',
        'c': 'Cancelado',
        'expired': 'Expirado',
        'error': 'Error'
    };
    return estados[estadoLower] || estado;
}

function formatearFecha(fecha) {
    if (!fecha || fecha === '0000-00-00 00:00:00') return 'No registrada';
    const date = new Date(fecha);
    return date.toLocaleDateString('es-MX') + ' ' + date.toLocaleTimeString('es-MX', {hour: '2-digit', minute:'2-digit'});
}

function formatearMoneda(monto, moneda = 'MXN') {
    if (monto === null || monto === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: moneda,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(monto);
}

function truncarTexto(texto, longitud = 30) {
    if (!texto || texto.length <= longitud) return texto;
    return texto.substring(0, longitud) + '...';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================
// FUNCIONES DE MODALES
// =============================================

// Función segura para parsear JSON
function safeJSONParse(str) {
    if (!str || str === '{}' || str === 'null' || str === 'undefined') return {};
    try {
        if (typeof str === 'object') return str;
        return JSON.parse(str);
    } catch (e) {
        console.error('Error parsing JSON:', e);
        return {};
    }
}

// Generar contenido modal PayPal
function generarContenidoModalPayPal(datos) {
    function calcularIVA(montoTotal) {
        const monto = parseFloat(montoTotal) || 0;
        const subtotal = monto / 1.16;
        const iva = monto - subtotal;
        return { subtotal, iva };
    }
    
    const ivaCalculado = calcularIVA(datos.amount);
    const estadoClass = getEstadoClass(datos.status);
    const estadoText = getEstadoText(datos.status);
    
    return `
        <div class="container-fluid px-0">
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2 text-primary"></i>Información del Pago</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr><th class="text-muted">ID Pago:</th><td><code>${escapeHtml(datos.payment_id)}</code></td></tr>
                                <tr><th class="text-muted">Transacción:</th><td><code>${escapeHtml(datos.transaction)}</code></td></tr>
                                <tr><th class="text-muted">Estado:</th><td><span class="badge bg-${estadoClass}">${estadoText}</span></td></tr>
                                <tr><th class="text-muted">Fecha creación:</th><td>${datos.created}</td></tr>
                                <tr><th class="text-muted">Última actualización:</th><td>${datos.updated}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-user me-2 text-primary"></i>Información del Pagador</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr><th class="text-muted">Nombre:</th><td class="fw-bold">${escapeHtml(datos.payer)}</td></tr>
                                <tr><th class="text-muted">Email:</th><td><a href="mailto:${escapeHtml(datos.email)}">${escapeHtml(datos.email)}</a></td></tr>
                                <tr><th class="text-muted">ID Interno:</th><td><small class="text-muted">${datos.id}</small></td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2 text-success"></i>Detalle de Montos</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3 mb-md-0">
                                    <div class="text-center p-3 bg-light rounded">
                                        <small class="text-muted d-block">Subtotal (sin IVA)</small>
                                        <span class="h4 mb-0">${formatearMoneda(ivaCalculado.subtotal, datos.currency)}</span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3 mb-md-0">
                                    <div class="text-center p-3 bg-light rounded">
                                        <small class="text-muted d-block">IVA (16%)</small>
                                        <span class="h4 mb-0 text-primary">${formatearMoneda(ivaCalculado.iva, datos.currency)}</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center p-3 bg-success text-white rounded">
                                        <small class="text-white-50 d-block">Total (con IVA)</small>
                                        <span class="h4 mb-0">${formatearMoneda(datos.amount, datos.currency)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            ${generarAccordionJSON(datos.payment_data, 'Datos del Pago (payment_data)', 'fa-credit-card', 'collapsePayment')}
            ${generarAccordionJSON(datos.cart_data, 'Datos del Carrito (cart_data)', 'fa-shopping-cart', 'collapseCart')}
            ${generarAccordionJSON(datos.webhook_data, 'Datos del Webhook (webhook_data)', 'fa-webhook', 'collapseWebhook')}
        </div>
    `;
}

// Generar contenido modal Liga
function generarContenidoModalLiga(datos) {
    const estadoClass = getEstadoClass(datos.response);
    const estadoText = getEstadoText(datos.response);
    
    return `
        <div class="container-fluid px-0">
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Información del Pago</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th class="text-muted">Folio:</th>
                                    <td><code>${escapeHtml(datos.folio || 'N/A')}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Referencia:</th>
                                    <td><code>${escapeHtml(datos.reference || 'N/A')}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Estado:</th>
                                    <td><span class="badge bg-${estadoClass}">${estadoText}</span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Código Autorización:</th>
                                    <td><span class="badge bg-success">${escapeHtml(datos.auth || 'N/A')}</span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Fecha:</th>
                                    <td>${datos.fecha}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-user me-2 text-primary"></i>Información del Pagador</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th class="text-muted">Nombre:</th>
                                    <td class="fw-bold">${escapeHtml(datos.cc_name || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Email:</th>
                                    <td><a href="mailto:${escapeHtml(datos.email)}">${escapeHtml(datos.email || 'N/A')}</a></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">ID Interno:</th>
                                    <td><small class="text-muted">${datos.id}</small></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-credit-card me-2 text-primary"></i>Información de la Tarjeta</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th class="text-muted">Tipo:</th>
                                    <td>${escapeHtml(datos.cc_type || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Número:</th>
                                    <td>${escapeHtml(datos.cc_mask || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Titular:</th>
                                    <td>${escapeHtml(datos.cc_name || 'N/A')}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2 text-success"></i>Monto</h6>
                        </div>
                        <div class="card-body">
                            <div class="text-center p-4 bg-success text-white rounded">
                                <small class="text-white-50 d-block">Total</small>
                                <span class="h2 mb-0">${formatearMoneda(datos.amount, 'MXN')}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            ${(datos.cd_response || datos.cd_error || datos.nb_error) ? `
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-exchange-alt me-2 text-info"></i>Detalles de la Transacción</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                ${datos.cd_response ? `<div class="col-md-4 mb-3"><div class="p-3 bg-light rounded"><small class="text-muted d-block">Código Respuesta</small><span class="h5 mb-0">${escapeHtml(datos.cd_response)}</span></div></div>` : ''}
                                ${datos.cd_error ? `<div class="col-md-4 mb-3"><div class="p-3 bg-light rounded"><small class="text-muted d-block">Código Error</small><span class="h5 mb-0 text-danger">${escapeHtml(datos.cd_error)}</span></div></div>` : ''}
                                ${datos.nb_error ? `<div class="col-md-4 mb-3"><div class="p-3 bg-light rounded"><small class="text-muted d-block">Mensaje Error</small><span class="h6 mb-0">${escapeHtml(datos.nb_error)}</span></div></div>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            ` : ''}
            ${generarAccordionJSON(datos.raw_response, 'Respuesta Completa (raw_response)', 'fa-database', 'collapseRawResponse')}
        </div>
    `;
}

// Generar contenido modal SPEI
function generarContenidoModalSpei(datos) {
    const estadoClass = datos.codigo_respuesta == 200 ? 'success' : 'danger';
    const estadoText = datos.codigo_respuesta == 200 ? 'Completado' : 'Fallido';
    
    return `
        <div class="container-fluid px-0">
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Información de la Transferencia</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th class="text-muted">ID:</th>
                                    <td><code>${datos.id}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">CLABE:</th>
                                    <td><code>${escapeHtml(datos.clabe)}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Monto:</th>
                                    <td><span class="h5 mb-0 text-success">${formatearMoneda(datos.monto, 'MXN')}</span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Estado:</th>
                                    <td><span class="badge bg-${estadoClass}">${estadoText}</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-exchange-alt me-2 text-primary"></i>Datos de la Transacción</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th class="text-muted">Transacción Externa:</th>
                                    <td><code>${escapeHtml(datos.transaccion_externa)}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Código Respuesta:</th>
                                    <td><span class="badge bg-${estadoClass}">${datos.codigo_respuesta}</span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Autorización:</th>
                                    <td>${datos.autorizacion !== 'N/A' ? `<span class="badge bg-success">${escapeHtml(datos.autorizacion)}</span>` : 'N/A'}</span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Mensaje Respuesta:</th>
                                    <td>${escapeHtml(datos.mensaje_respuesta)}</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-calendar-alt me-2 text-primary"></i>Fechas</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 bg-light rounded text-center">
                                        <small class="text-muted d-block">Fecha Solicitud</small>
                                        <span class="fw-bold">${datos.fecha_solicitud}</span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 bg-light rounded text-center">
                                        <small class="text-muted d-block">Fecha Respuesta</small>
                                        <span class="fw-bold">${datos.fecha_respuesta}</span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 bg-light rounded text-center">
                                        <small class="text-muted d-block">Fecha Registro</small>
                                        <span class="fw-bold">${datos.fecha_registro}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            ${datos.ip_origen !== 'N/A' ? `
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-network-wired me-2 text-primary"></i>Información Técnica</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr><th class="text-muted">IP Origen:</th><td><code>${escapeHtml(datos.ip_origen)}</code></td></tr>
                                <tr><th class="text-muted">User Agent:</th><td><small>${escapeHtml(datos.user_agent)}</small></td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            ` : ''}
        </div>
    `;
}

// Generar acordeón JSON
function generarAccordionJSON(jsonString, titulo, icono, targetId) {
    try {
        if (!jsonString || jsonString === '{}' || jsonString === 'null' || jsonString === '') {
            return '';
        }
        let jsonData;
        if (typeof jsonString === 'string') {
            jsonData = JSON.parse(jsonString);
        } else {
            jsonData = jsonString;
        }
        if (Object.keys(jsonData).length === 0) {
            return '';
        }
        return `
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <button class="btn btn-link text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#${targetId}" aria-expanded="false">
                            <i class="fas ${icono} me-2 text-primary"></i>${titulo}
                        </button>
                    </h6>
                </div>
                <div id="${targetId}" class="collapse">
                    <div class="card-body">
                        <pre class="bg-light p-3 rounded mb-0" style="max-height: 300px; overflow-y: auto;"><code>${JSON.stringify(jsonData, null, 2)}</code></pre>
                    </div>
                </div>
            </div>
        `;
    } catch (e) {
        console.error('Error parsing JSON:', e);
        return '';
    }
}

// =============================================
// EVENTOS
// =============================================

// Evento para registros por página
$('#registrosPorPagina').on('change', function() {
    registrosPorPagina = parseInt($(this).val());
    paginaPaypal = 1;
    paginaLiga = 1;
    paginaSpei = 1;
    if (tabActiva === 'paypal') {
        cargarPayPal();
    } else if (tabActiva === 'liga') {
        cargarLiga();
    } else {
        cargarSpei();
    }
});

// Evento para filtros
$('#filtroEstado, #filtroFechaInicio, #filtroFechaFin').on('change', function() {
    paginaPaypal = 1;
    paginaLiga = 1;
    paginaSpei = 1;
    
    if (tabActiva === 'paypal') {
        $('#opcionPendiente').show();
    } else if (tabActiva === 'liga') {
        $('#opcionPendiente').hide();
        if ($('#filtroEstado').val() === 'PENDING') {
            $('#filtroEstado').val('');
        }
    } else {
        $('#opcionPendiente').hide();
        if ($('#filtroEstado').val() === 'PENDING') {
            $('#filtroEstado').val('');
        }
    }
    
    if (tabActiva === 'paypal') {
        cargarPayPal();
    } else if (tabActiva === 'liga') {
        cargarLiga();
    } else {
        cargarSpei();
    }
});

// Botón limpiar filtros
$('#btnLimpiarFiltros').click(function() {
    $('#filtroEstado').val('');
    $('#filtroFechaInicio').val('');
    $('#filtroFechaFin').val('');
    $('#busquedaPaypal').val('');
    $('#busquedaLiga').val('');
    $('#busquedaSpei').val('');
    $('#btnLimpiarBusquedaPaypal').hide();
    $('#btnLimpiarBusquedaLiga').hide();
    $('#btnLimpiarBusquedaSpei').hide();
    paginaPaypal = 1;
    paginaLiga = 1;
    paginaSpei = 1;
    
    if (tabActiva === 'paypal') {
        cargarPayPal();
    } else if (tabActiva === 'liga') {
        cargarLiga();
    } else {
        cargarSpei();
    }
});

// Búsqueda PayPal
$('#btnBuscarPaypal').click(function() {
    paginaPaypal = 1;
    cargarPayPal();
    $('#btnLimpiarBusquedaPaypal').show();
});

$('#busquedaPaypal').on('keypress', function(e) {
    if (e.which === 13) {
        $('#btnBuscarPaypal').click();
    }
});

$('#btnLimpiarBusquedaPaypal').click(function() {
    $('#busquedaPaypal').val('');
    $(this).hide();
    paginaPaypal = 1;
    cargarPayPal();
});

// Búsqueda Liga
$('#btnBuscarLiga').click(function() {
    paginaLiga = 1;
    cargarLiga();
    $('#btnLimpiarBusquedaLiga').show();
});

$('#busquedaLiga').on('keypress', function(e) {
    if (e.which === 13) {
        $('#btnBuscarLiga').click();
    }
});

$('#btnLimpiarBusquedaLiga').click(function() {
    $('#busquedaLiga').val('');
    $(this).hide();
    paginaLiga = 1;
    cargarLiga();
});

// Búsqueda SPEI
$('#btnBuscarSpei').click(function() {
    paginaSpei = 1;
    cargarSpei();
    $('#btnLimpiarBusquedaSpei').show();
});

$('#busquedaSpei').on('keypress', function(e) {
    if (e.which === 13) {
        $('#btnBuscarSpei').click();
    }
});

$('#btnLimpiarBusquedaSpei').click(function() {
    $('#busquedaSpei').val('');
    $(this).hide();
    paginaSpei = 1;
    cargarSpei();
});

// Cambio de tab
$('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
    const targetId = $(e.target).attr('data-bs-target');
    tabActiva = targetId === '#paypal' ? 'paypal' : (targetId === '#liga' ? 'liga' : 'spei');
    
    if (tabActiva === 'paypal') {
        $('#opcionPendiente').show();
        if ($('#paypalTablaBody').find('tr').length === 1 && $('#paypalTablaBody').find('td').text().includes('Selecciona filtros')) {
            cargarPayPal();
        }
    } else if (tabActiva === 'liga') {
        $('#opcionPendiente').hide();
        if ($('#filtroEstado').val() === 'PENDING') {
            $('#filtroEstado').val('');
        }
        if ($('#ligaTablaBody').find('tr').length === 1 && $('#ligaTablaBody').find('td').text().includes('Selecciona filtros')) {
            cargarLiga();
        }
    } else {
        $('#opcionPendiente').hide();
        if ($('#filtroEstado').val() === 'PENDING') {
            $('#filtroEstado').val('');
        }
        if ($('#speiTablaBody').find('tr').length === 1 && $('#speiTablaBody').find('td').text().includes('Selecciona filtros')) {
            cargarSpei();
        }
    }
});

// Evento para botones de detalle PayPal (Desktop y Móvil)
$(document).on('click', '.ver-detalle-paypal, .ver-detalle-paypal-mobile', function() {
    const button = $(this);
    const modal = $('#modalDetallePayPal');
    
    modal.find('#detallePayPalCargando').show();
    modal.find('#detallePayPalContenido').hide().empty();
    
    const datos = {
        id: button.data('id'),
        payment_id: button.data('payment-id'),
        payer: button.data('payer'),
        email: button.data('email'),
        amount: button.data('amount'),
        currency: button.data('currency'),
        status: button.data('status'),
        transaction: button.data('transaction'),
        created: button.data('created'),
        updated: button.data('updated'),
        payment_data: safeJSONParse(button.data('payment-data')),
        cart_data: safeJSONParse(button.data('cart-data')),
        webhook_data: safeJSONParse(button.data('webhook-data'))
    };
    
    const contenido = generarContenidoModalPayPal(datos);
    
    setTimeout(() => {
        modal.find('#detallePayPalCargando').hide();
        modal.find('#detallePayPalContenido').html(contenido).show();
        modal.modal('show');
    }, 300);
});

// Evento para botones de detalle Liga (Desktop y Móvil)
$(document).on('click', '.ver-detalle-liga, .ver-detalle-liga-mobile', function() {
    const button = $(this);
    const modal = $('#modalDetalleLiga');
    
    modal.find('#detalleLigaCargando').show();
    modal.find('#detalleLigaContenido').hide().empty();
    
    const datos = {
        id: button.data('id'),
        folio: button.data('folio'),
        reference: button.data('reference'),
        response: button.data('response'),
        auth: button.data('auth'),
        cc_name: button.data('cc-name'),
        email: button.data('email'),
        amount: button.data('amount'),
        cc_type: button.data('cc-type'),
        cc_mask: button.data('cc-mask'),
        fecha: button.data('fecha'),
        raw_response: safeJSONParse(button.data('raw-response')),
        cd_response: button.data('cd-response'),
        cd_error: button.data('cd-error'),
        nb_error: button.data('nb-error'),
        nb_company: button.data('nb-company'),
        nb_merchant: button.data('nb-merchant')
    };
    
    const contenido = generarContenidoModalLiga(datos);
    
    setTimeout(() => {
        modal.find('#detalleLigaCargando').hide();
        modal.find('#detalleLigaContenido').html(contenido).show();
        modal.modal('show');
    }, 300);
});

// Evento para botones de detalle SPEI (Desktop y Móvil)
$(document).on('click', '.ver-detalle-spei, .ver-detalle-spei-mobile', function() {
    const button = $(this);
    const modal = $('#modalDetalleSpei');
    
    modal.find('#detalleSpeiCargando').show();
    modal.find('#detalleSpeiContenido').hide().empty();
    
    const datos = {
        id: button.data('id'),
        clabe: button.data('clabe'),
        monto: button.data('monto'),
        transaccion_externa: button.data('transaccion-externa'),
        codigo_respuesta: button.data('codigo-respuesta'),
        autorizacion: button.data('autorizacion'),
        mensaje_respuesta: button.data('mensaje-respuesta'),
        fecha_solicitud: button.data('fecha-solicitud'),
        fecha_respuesta: button.data('fecha-respuesta'),
        ip_origen: button.data('ip-origen'),
        user_agent: button.data('user-agent'),
        fecha_registro: button.data('fecha-registro')
    };
    
    const contenido = generarContenidoModalSpei(datos);
    
    setTimeout(() => {
        modal.find('#detalleSpeiCargando').hide();
        modal.find('#detalleSpeiContenido').html(contenido).show();
        modal.modal('show');
    }, 300);
});

// =============================================
// INICIALIZACIÓN
// =============================================
$(document).ready(function() {
    cargarPayPal();
    
    if (tabActiva === 'liga') {
        $('#opcionPendiente').hide();
    } else if (tabActiva === 'spei') {
        $('#opcionPendiente').hide();
    }
});

// =============================================
// FUNCIONALIDAD DE SWIPE PARA SIDEBAR
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
        if (element.classList && element.classList.contains('table-responsive')) return true;
        if (element.classList && element.classList.contains('table')) return true;
        if (element.tagName === 'TD' || element.tagName === 'TH' || element.tagName === 'TR' ||
            element.tagName === 'TBODY' || element.tagName === 'THEAD') return true;
        element = element.parentElement;
    }
    return false;
}

document.addEventListener('touchstart', function(e) {
    if (window.innerWidth >= 768) return;
    const touchX = e.touches[0].clientX;
    if (touchX <= SWIPE_EDGE_ZONE && !isInsideTable(e.target)) {
        isSidebarTouch = true;
        touchStartX = touchX;
        touchStartY = e.touches[0].clientY;
    }
}, { passive: true });

document.addEventListener('touchmove', function(e) {
    if (window.innerWidth >= 768) return;
    if (isSidebarTouch) {
        touchEndX = e.touches[0].clientX;
        touchEndY = e.touches[0].clientY;
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;
        if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
            e.preventDefault();
        }
    }
}, { passive: false });

document.addEventListener('touchend', function(e) {
    if (window.innerWidth >= 768) return;
    if (isSidebarTouch) {
        isSidebarTouch = false;
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;
        if (Math.abs(deltaY) > VERTICAL_THRESHOLD) return;
        const sidebar = document.getElementById('sidebar');
        const isSidebarOpen = sidebar && sidebar.classList.contains('show');
        if (deltaX > SWIPE_THRESHOLD && touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
            openSidebarAuto();
        } else if (deltaX < -SWIPE_THRESHOLD && isSidebarOpen) {
            closeSidebarAuto();
        }
    }
}, { passive: true });

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
</script>
</body>
</html>