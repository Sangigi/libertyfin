<?php

$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar sesión
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Configuración optimizada
$config = [
    'servername' => 'libertyfin.com.mx',
    'username' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'dbname' => 'juanc141_ventas',
    'registros_por_pagina' => 5
];

$mensaje = '';
$tipo_mensaje = '';

// Conexión optimizada con persistencia
$conn = new mysqli($config['servername'], $config['username'], $config['password'], $config['dbname']);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Procesar acciones POST (activar/desactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    if (isset($_POST['accion']) && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $accion = $_POST['accion'];
        
        if (in_array($accion, ['activar', 'desactivar'])) {
            $activo = $accion === 'activar' ? 1 : 0;
            $sql = "UPDATE distribuidores SET activo = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $activo, $id);
            
            if ($stmt->execute()) {
                $mensaje = "Distribuidor " . ($accion === 'activar' ? 'activado' : 'desactivado') . " correctamente";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al procesar la acción";
                $tipo_mensaje = "danger";
            }
            $stmt->close();
        }
    }
}

// Cache de estadísticas (5 minutos)
$cache_file = sys_get_temp_dir() . '/distribuidores_stats_' . md5(__FILE__);
$estadisticas = [];

if (file_exists($cache_file) && (time() - filemtime($cache_file) < 300)) {
    $estadisticas = unserialize(file_get_contents($cache_file));
} else {
    try {
        $sql_stats = "SELECT 
            COUNT(*) as total,
            SUM(estado_verificacion = 'aprobado') as aprobados,
            SUM(estado_verificacion = 'pendiente') as pendientes,
            SUM(estado_verificacion = 'en_revision') as en_revision,
            SUM(estado_verificacion = 'rechazado') as rechazados
            FROM distribuidores";
        
        $result_stats = $conn->query($sql_stats);
        if ($result_stats && $result_stats->num_rows > 0) {
            $stats = $result_stats->fetch_assoc();
            $estadisticas = [
                'total_distribuidores' => (int)($stats['total'] ?? 0),
                'aprobados' => (int)($stats['aprobados'] ?? 0),
                'pendientes' => (int)($stats['pendientes'] ?? 0),
                'en_revision' => (int)($stats['en_revision'] ?? 0),
                'rechazados' => (int)($stats['rechazados'] ?? 0)
            ];
            file_put_contents($cache_file, serialize($estadisticas));
        }
    } catch (Exception $e) {
        $estadisticas = [
            'total_distribuidores' => 0,
            'aprobados' => 0,
            'pendientes' => 0,
            'en_revision' => 0,
            'rechazados' => 0
        ];
    }
}

$conn->close();

// Funciones auxiliares
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha === '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

function claseEstado($estado) {
    $map = [
        'pendiente' => 'warning',
        'en_revision' => 'info',
        'aprobado' => 'success',
        'rechazado' => 'danger'
    ];
    return $map[$estado] ?? 'secondary';
}

function textoEstado($estado) {
    $map = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Revisión',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado'
    ];
    return $map[$estado] ?? $estado;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Distribuidores - Panel de Administración</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* CSS optimizado para distribuidores.php */
    :root {
        --primary-color: #27ae60;
        --secondary-color: #2ecc71;
        --success-color: #28a745;
        --danger-color: #dc3545;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
        --purple-color: #6f42c1;
        --dark-color: #2c3e50;
        --light-bg: #f8f9fa;
        --transition-default: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    body {
        background-color: #f8f9fa;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        overflow-x: hidden;
    }

    /* Navbar */
    .navbar {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .navbar-brand img {
        transition: transform 0.3s ease;
    }

    .navbar-brand:hover img {
        transform: scale(1.05);
    }

    /* Sidebar */
    .sidebar {
        background: var(--dark-color);
        color: white;
        min-height: calc(100vh - 56px);
        transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    }

    .sidebar .nav-link {
        color: #ecf0f1;
        padding: 12px 20px;
        border-left: 3px solid transparent;
        transition: var(--transition-default);
        position: relative;
        overflow: hidden;
    }

    .sidebar .nav-link::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 0;
        height: 100%;
        background: rgba(255, 255, 255, 0.1);
        transition: width 0.3s ease;
        z-index: 0;
    }

    .sidebar .nav-link:hover::before,
    .sidebar .nav-link.active::before {
        width: 100%;
    }

    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
        background: rgba(255, 255, 255, 0.1);
        border-left-color: var(--secondary-color);
        color: white;
    }

    .sidebar .nav-link i {
        width: 20px;
        margin-right: 10px;
        position: relative;
        z-index: 1;
    }

    .sidebar .nav-link span {
        position: relative;
        z-index: 1;
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        transition: var(--transition-default);
        overflow: hidden;
    }

    .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
    }

    /* Welcome Card */
    .welcome-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        position: relative;
        overflow: hidden;
    }

    .welcome-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
        transform: rotate(30deg);
    }

    .welcome-card .card-body {
        position: relative;
        z-index: 1;
    }

    /* Stat Cards */
    .stat-card {
        cursor: pointer;
        transition: var(--transition-default);
        border-left: 4px solid var(--primary-color);
        position: relative;
        overflow: hidden;
    }

    .stat-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .stat-card:hover::after {
        transform: scaleX(1);
    }

    .stat-card .bg-opacity-10 {
        transition: var(--transition-default);
    }

    .stat-card:hover .bg-opacity-10 {
        transform: scale(1.1);
    }

    /* Filtros Card */
    .filtros-card {
        background-color: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .filtros-card .form-label {
        font-weight: 500;
        color: var(--dark-color);
        margin-bottom: 8px;
    }

    .filtros-card .form-control,
    .filtros-card .form-select {
        border-radius: 8px;
        border: 1px solid #dee2e6;
        transition: var(--transition-default);
    }

    .filtros-card .form-control:focus,
    .filtros-card .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(39, 174, 96, 0.25);
    }

    /* Tabla Container */
    .table-container {
        position: relative;
        min-height: 400px;
        overflow-x: auto;
    }

    /* Tabla mejorada */
    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-bottom: 2px solid var(--primary-color);
        font-weight: 600;
        color: var(--dark-color);
        padding: 12px;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .table tbody tr {
        transition: var(--transition-default);
        border-left: 3px solid transparent;
    }

    .table tbody tr:hover {
        background: linear-gradient(90deg, rgba(39, 174, 96, 0.05), rgba(39, 174, 96, 0.02));
        transform: translateX(2px);
    }

    .table tbody td {
        padding: 12px;
        vertical-align: middle;
        border-top: 1px solid #f0f0f0;
    }

    /* Badges personalizados */
    .badge-purple {
        background-color: var(--purple-color);
        color: white;
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .badge-activo-modern {
        background: linear-gradient(135deg, var(--success-color), #20c997);
        color: white;
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .badge-inactivo-modern {
        background: linear-gradient(135deg, var(--danger-color), #c82333);
        color: white;
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .numero-control-badge {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    /* Botones */
    .btn {
        border-radius: 8px;
        padding: 0.375rem 0.75rem;
        transition: var(--transition-default);
        font-weight: 500;
    }

    .btn-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }

    .btn-primary:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
        transform: translateY(-1px);
    }

    .btn-info, .btn-warning, .btn-danger, .btn-success {
        transition: var(--transition-default);
    }

    .btn-info:hover, .btn-warning:hover, .btn-danger:hover, .btn-success:hover {
        transform: translateY(-1px);
        filter: brightness(1.05);
    }

    .btn-outline-primary {
        color: var(--primary-color);
        border-color: var(--primary-color);
    }

    .btn-outline-primary:hover {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }

    /* Botones de documentos */
    .btn-documento {
        transition: var(--transition-default);
    }

    .btn-documento:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    }

    /* Paginación */
    .pagination {
        gap: 5px;
    }

    .page-item {
        margin: 2px;
    }

    .page-link {
        border-radius: 8px;
        color: var(--dark-color);
        border: 1px solid #dee2e6;
        transition: var(--transition-default);
        padding: 0.5rem 0.75rem;
    }

    .page-link:hover {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-color: var(--primary-color);
        transform: translateY(-1px);
    }

    .page-item.active .page-link {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border-color: var(--primary-color);
        color: white;
    }

    .page-item.disabled .page-link {
        cursor: not-allowed;
        opacity: 0.6;
    }

    /* Modal mejorado */
    .modal-content {
        border-radius: 15px;
        border: none;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px 15px 0 0;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    .modal-footer {
        border-top: 1px solid #f0f0f0;
    }

    /* Visor de archivos */
    #modalArchivo .modal-dialog {
        max-width: 90%;
        height: 90vh;
        margin: 5vh auto;
    }

    #modalArchivo .modal-content {
        height: 90vh;
        display: flex;
        flex-direction: column;
    }

    #modalArchivo .modal-body {
        flex: 1;
        overflow: hidden;
        padding: 0 !important;
        position: relative;
        background-color: #f8f9fa;
    }

    #visorPDF iframe {
        width: 100%;
        height: 100%;
        border: none;
    }

    #visorImagen {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #f8f9fa;
    }

    #visorImagen img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        animation: fadeIn 0.3s ease;
    }

    /* Tarjetas para móvil */
    .distribuidor-card {
        border-left: 4px solid;
        transition: var(--transition-default);
        margin-bottom: 15px;
        border-radius: 12px;
        overflow: hidden;
    }

    .distribuidor-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .distribuidor-card .card-header {
        background: linear-gradient(135deg, #f8f9fa, #fff);
        border-bottom: 1px solid #f0f0f0;
        padding: 12px 15px;
    }

    .distribuidor-card .card-body {
        padding: 15px;
    }

    .distribuidor-card .card-footer {
        background-color: white;
        border-top: 1px solid #f0f0f0;
        padding: 12px 15px;
    }

    .distribuidor-card .text-muted {
        font-weight: 500;
        font-size: 0.8rem;
    }

    .distribuidor-card .row {
        margin-bottom: 10px;
    }

    /* Loading overlay */
    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000;
        border-radius: 10px;
    }

    .searching-indicator {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        display: none;
    }

    .searching .searching-indicator {
        display: block;
    }

    /* Animaciones */
    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes pulse {
        0% {
            transform: scale(1);
        }
        50% {
            transform: scale(1.05);
        }
        100% {
            transform: scale(1);
        }
    }

    .table-container {
        animation: fadeInUp 0.4s ease;
    }

    .distribuidor-card {
        animation: fadeInUp 0.3s ease;
    }

    .stat-card {
        animation: slideInLeft 0.3s ease;
    }

    /* Sidebar responsive */
    .sidebar-toggle {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 1.25rem;
        padding: 0.5rem;
        margin-right: 1rem;
        transition: var(--transition-default);
    }

    .sidebar-toggle:hover {
        transform: scale(1.1);
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

    /* Scrollbar personalizada */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: var(--primary-color);
    }

    /* Tooltips mejorados */
    [title] {
        position: relative;
        cursor: help;
    }

    /* Responsive Design */
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
        
        .stat-card h3 {
            font-size: 1.5rem;
        }
        
        .stat-card h6 {
            font-size: 0.7rem;
        }
        
        .filtros-card {
            padding: 15px;
        }
        
        .card-header h5 {
            font-size: 1rem;
        }
        
        .numero-control-badge {
            font-size: 0.7rem;
            padding: 3px 8px;
        }
        
        .badge-activo-modern,
        .badge-inactivo-modern {
            font-size: 0.7rem;
            padding: 4px 10px;
        }
        
        .btn-group-sm .btn {
            padding: 0.3rem 0.6rem;
            font-size: 0.7rem;
        }
        
        .pagination {
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .page-link {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .table-responsive {
            border-radius: 10px;
        }
        
        #modalArchivo .modal-dialog {
            max-width: 95%;
            margin: 2.5vh auto;
        }
        
        #modalArchivo .modal-content {
            height: 95vh;
        }
    }

    @media (max-width: 576px) {
        .stat-card h3 {
            font-size: 1.2rem;
        }
        
        .stat-card h6 {
            font-size: 0.65rem;
        }
        
        .stat-card .bg-opacity-10 {
            padding: 0.5rem !important;
        }
        
        .filtros-card .form-label {
            font-size: 0.85rem;
        }
        
        .btn-sm {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        .distribuidor-card .card-header h6 {
            font-size: 0.9rem;
        }
        
        .distribuidor-card .text-muted {
            font-size: 0.7rem;
        }
        
        .distribuidor-card .card-body {
            font-size: 0.8rem;
        }
    }

    @media (min-width: 768px) and (max-width: 991.98px) {
        .stat-card h3 {
            font-size: 1.3rem;
        }
        
        .stat-card h6 {
            font-size: 0.7rem;
        }
        
        .table thead th {
            font-size: 0.75rem;
            padding: 8px;
        }
        
        .table tbody td {
            font-size: 0.8rem;
            padding: 8px;
        }
    }

    /* Mejoras para pantallas táctiles */
    @media (hover: none) and (pointer: coarse) {
        .btn:active {
            transform: scale(0.98);
        }
        
        .distribuidor-card:active {
            transform: scale(0.99);
        }
        
        .stat-card:active {
            transform: translateY(-2px);
        }
    }

    /* Mejoras de accesibilidad */
    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }

    /* Print styles */
    @media print {
        .sidebar,
        .navbar,
        .filtros-card,
        .btn,
        .pagination,
        .card-footer {
            display: none !important;
        }
        
        .table-container {
            overflow: visible !important;
        }
        
        .table {
            width: 100% !important;
        }
        
        .badge {
            border: 1px solid #000 !important;
        }
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
                <img src="../images/LibertyfinBlanco.png" alt="Logo" style="height: 30px;">
                <span class="ms-2">Panel de Administración</span>
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
    
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="empresas.php"><i class="fas fa-building"></i>Empresas</a></li>
                        <li class="nav-item"><a class="nav-link" href="activaciones.php"><i class="fas fa-history"></i>Activaciones</a></li>
                        <li class="nav-item"><a class="nav-link" href="usuarios.php"><i class="fas fa-user-cog"></i>Usuarios Admin</a></li>
                        <li class="nav-item"><a class="nav-link" href="solicitudes.php"><i class="fas fa-file-alt"></i>Solicitudes</a></li>
                        <li class="nav-item"><a class="nav-link active" href="distribuidores.php"><i class="fas fa-users"></i>Distribuidores</a></li>
                        <li class="nav-item"><a class="nav-link" href="pagos.php"><i class="fas fa-money-bill-wave"></i>Pagos</a></li>
                        <li class="nav-item"><a class="nav-link" href="ActivacionesCaracteristicas.php"><i class="fas fa-sliders-h"></i>Características</a></li>
                    </ul>
                </div>
            </div>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Mensajes -->
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Welcome Card -->
                <div class="card welcome-card mb-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="card-title mb-1">Gestión de Distribuidores</h4>
                                <p class="card-text mb-0 opacity-75">
                                    <i class="fas fa-user me-1"></i>
                                    Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Administrador'); ?>
                                    <span class="badge bg-light text-dark ms-2">Administrador</span>
                                </p>
                            </div>
                            <div class="text-end">
                                <small class="text-white-50 d-block">Distribuidores totales</small>
                                <span class="h5 mb-0 text-white" id="totalDistribuidores"><?php echo $estadisticas['total_distribuidores']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Estadísticas optimizadas -->
                <div class="row mb-4" id="estadisticasContainer">
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total</h6>
                                        <h3 class="mb-0" id="statTotal"><?php echo $estadisticas['total_distribuidores']; ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle"><i class="fas fa-users text-primary"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100" style="border-left-color: #28a745;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Aprobados</h6>
                                        <h3 class="mb-0 text-success" id="statAprobados"><?php echo $estadisticas['aprobados']; ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded-circle"><i class="fas fa-check-circle text-success"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100" style="border-left-color: #ffc107;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Pendientes</h6>
                                        <h3 class="mb-0 text-warning" id="statPendientes"><?php echo $estadisticas['pendientes']; ?></h3>
                                    </div>
                                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle"><i class="fas fa-clock text-warning"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100" style="border-left-color: #17a2b8;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">En Revisión</h6>
                                        <h3 class="mb-0 text-info" id="statEnRevision"><?php echo $estadisticas['en_revision']; ?></h3>
                                    </div>
                                    <div class="bg-info bg-opacity-10 p-3 rounded-circle"><i class="fas fa-search text-info"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100" style="border-left-color: #dc3545;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Rechazados</h6>
                                        <h3 class="mb-0 text-danger" id="statRechazados"><?php echo $estadisticas['rechazados']; ?></h3>
                                    </div>
                                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle"><i class="fas fa-times-circle text-danger"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Activos</h6>
                                        <h3 class="mb-0" id="statActivos"><?php echo $estadisticas['aprobados']; ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle"><i class="fas fa-user-check text-primary"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filtros optimizados -->
                <div class="filtros-card">
                    <form id="filtrosForm" class="row g-3" onsubmit="return false;">
                        <div class="col-md-4">
                            <label class="form-label">Buscar</label>
                            <div class="input-group position-relative">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="busqueda" 
                                    placeholder="Nombre, email, RFC, teléfono..." autocomplete="off">
                                <div class="searching-indicator">
                                    <div class="spinner-border spinner-border-sm text-primary"></div>
                                </div>
                            </div>
                            <small class="text-muted">Escribe para buscar automáticamente...</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Estado de verificación</label>
                            <select class="form-select" id="estado_verificacion">
                                <option value="">Todos los estados</option>
                                <option value="pendiente">Pendiente</option>
                                <option value="en_revision">En Revisión</option>
                                <option value="aprobado">Aprobado</option>
                                <option value="rechazado">Rechazado</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Estado</label>
                            <select class="form-select" id="estado_activo">
                                <option value="">Todos</option>
                                <option value="activos">Activos</option>
                                <option value="inactivos">Inactivos</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="btn btn-outline-secondary w-100" id="btnLimpiarFiltros">
                                <i class="fas fa-eraser me-2"></i>Limpiar
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Tabla de Distribuidores -->
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users me-2 text-primary"></i>Listado de Distribuidores</h5>
                        <div>
                            <button class="btn btn-success btn-sm" onclick="window.location.href='distribuidor_nuevo.php'">
                                <i class="fas fa-plus me-1"></i>Nuevo
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="exportarExcel()">
                                <i class="fas fa-file-excel me-1"></i>Exportar
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-container" id="tablaContainer">
                            <div id="tablaContent">
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary"></div>
                                    <p class="mt-2">Cargando distribuidores...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modales -->
    <div class="modal fade" id="modalArchivo" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalArchivoTitulo">Visor de Archivos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="archivoCargando" class="d-flex align-items-center justify-content-center h-100">
                        <div class="text-center"><div class="spinner-border text-primary"></div><p>Cargando...</p></div>
                    </div>
                    <div id="visorImagen" class="d-none h-100"><img id="imagenVisor" class="img-fluid"></div>
                    <div id="visorPDF" class="d-none h-100"><iframe id="pdfVisor"></iframe></div>
                    <div id="visorError" class="d-none h-100">
                        <div class="text-center"><i class="fas fa-exclamation-triangle fa-4x text-warning"></i><h5>Error al cargar</h5>
                        <a id="descargarArchivo" class="btn btn-primary"><i class="fas fa-download"></i>Descargar</a></div>
                    </div>
                </div>
                <div class="modal-footer"><small class="text-muted" id="infoArchivo"></small><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalConfirmar" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Confirmar Acción</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><p id="mensajeConfirmacion"></p></div>
            <div class="modal-footer">
                <form method="POST" id="formConfirmar"><input type="hidden" name="id" id="confirmarId"><input type="hidden" name="accion" id="confirmarAccion">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnConfirmar">Confirmar</button></form>
            </div>
        </div></div>
    </div>
    
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Detalles del Distribuidor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="detalleDistribuidor"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
        </div></div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Variables optimizadas
    let currentPage = 1;
    let isLoading = false;
    let searchTimeout = null;
    let abortController = null;
    let lastFilters = { busqueda: '', estado_verificacion: '', estado_activo: '' };
    
    // Función optimizada para cargar datos
    function cargarDistribuidores(page = 1, force = false) {
        if (isLoading) return;
        
        const filters = {
            pagina: page,
            busqueda: $('#busqueda').val().trim(),
            estado_verificacion: $('#estado_verificacion').val(),
            estado_activo: $('#estado_activo').val()
        };
        
        // Verificar si los filtros cambiaron
        const filtersChanged = JSON.stringify(filters) !== JSON.stringify(lastFilters);
        if (!force && !filtersChanged && page === currentPage) return;
        
        if (abortController) abortController.abort();
        abortController = new AbortController();
        isLoading = true;
        
        // Mostrar loading
        $('#tablaContent').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Cargando...</p></div>');
        
        $.ajax({
            url: 'ajax_distribuidores.php',
            method: 'GET',
            data: filters,
            dataType: 'json',
            signal: abortController.signal,
            timeout: 10000,
            success: function(response) {
                if (response.success) {
                    $('#tablaContent').html(response.html);
                    
                    // Actualizar estadísticas si cambiaron
                    if (response.estadisticas && filtersChanged) {
                        $('#statTotal, #totalDistribuidores').text(response.estadisticas.total || 0);
                        $('#statAprobados, #statActivos').text(response.estadisticas.aprobados || 0);
                        $('#statPendientes').text(response.estadisticas.pendientes || 0);
                        $('#statEnRevision').text(response.estadisticas.en_revision || 0);
                        $('#statRechazados').text(response.estadisticas.rechazados || 0);
                    }
                    
                    currentPage = page;
                    lastFilters = {...filters};
                } else {
                    $('#tablaContent').html(`<div class="text-center py-5"><i class="fas fa-exclamation-triangle fa-3x text-warning"></i><p>${response.mensaje || 'Error al cargar'}</p></div>`);
                }
            },
            error: function(xhr) {
                if (xhr.statusText !== 'abort') {
                    $('#tablaContent').html('<div class="text-center py-5"><i class="fas fa-exclamation-circle fa-3x text-danger"></i><p>Error de conexión. Reintentando...</p></div>');
                    setTimeout(() => cargarDistribuidores(page, true), 3000);
                }
            },
            complete: function() {
                isLoading = false;
                abortController = null;
                $('.input-group').removeClass('searching');
            }
        });
    }
    
    // Búsqueda con debounce optimizado
    function buscarConDebounce() {
        $('.input-group').addClass('searching');
        if (searchTimeout) clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            cargarDistribuidores(1);
        }, 300);
    }
    
    // Exportar Excel
    function exportarExcel() {
        const params = new URLSearchParams({
            busqueda: $('#busqueda').val(),
            estado_verificacion: $('#estado_verificacion').val(),
            estado_activo: $('#estado_activo').val()
        });
        window.location.href = 'exportar_distribuidores.php?' + params.toString();
    }
    
    // Limpiar filtros
    function limpiarFiltros() {
        $('#busqueda').val('');
        $('#estado_verificacion').val('');
        $('#estado_activo').val('');
        cargarDistribuidores(1);
    }
    
    // Ver detalles
    window.verDetalle = function(id) {
        const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));
        $('#detalleDistribuidor').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><p>Cargando...</p></div>');
        modal.show();
        
        $.ajax({
            url: 'ajax_detalle_distribuidor.php',
            method: 'GET',
            data: { id: id },
            success: function(response) { $('#detalleDistribuidor').html(response); },
            error: function() { $('#detalleDistribuidor').html('<div class="alert alert-danger">Error al cargar los detalles</div>'); }
        });
    };
    
    // Confirmar acciones
    window.confirmarAccion = function(id, accion) {
        $('#confirmarId').val(id);
        $('#confirmarAccion').val(accion);
        $('#mensajeConfirmacion').text(accion === 'desactivar' ? '¿Desactivar este distribuidor?' : '¿Activar este distribuidor?');
        $('#btnConfirmar').removeClass('btn-primary btn-danger').addClass(accion === 'desactivar' ? 'btn-danger' : 'btn-success');
        new bootstrap.Modal(document.getElementById('modalConfirmar')).show();
    };
    
    // Visor de archivos optimizado
    window.abrirArchivoModal = function(ruta, tipo, nombre, titulo) {
        const modal = new bootstrap.Modal(document.getElementById('modalArchivo'));
        const url = ruta.startsWith('http') ? ruta : (ruta.startsWith('/') ? ruta : '/' + ruta);
        
        $('#modalArchivoTitulo').text(titulo);
        $('#descargarArchivo').attr('href', url);
        $('#infoArchivo').text(nombre);
        
        $('#archivoCargando').removeClass('d-none');
        $('#visorImagen, #visorPDF, #visorError').addClass('d-none');
        
        modal.show();
        
        setTimeout(() => {
            if (tipo === 'imagen') {
                const img = new Image();
                img.onload = () => {
                    $('#imagenVisor').attr('src', url);
                    $('#archivoCargando').addClass('d-none');
                    $('#visorImagen').removeClass('d-none');
                };
                img.onerror = () => { 
                    $('#archivoCargando, #visorImagen').addClass('d-none'); 
                    $('#visorError').removeClass('d-none'); 
                };
                img.src = url;
            } else if (tipo === 'pdf') {
                $('#pdfVisor').attr('src', url + '#view=fitH');
                $('#archivoCargando').addClass('d-none');
                $('#visorPDF').removeClass('d-none');
            } else {
                $('#archivoCargando').addClass('d-none');
                $('#visorError').removeClass('d-none');
            }
        }, 100);
    };
    
    // Cambiar página con scroll suave
    function cambiarPagina(page) {
        if (page !== currentPage) {
            cargarDistribuidores(page);
            // Scroll suave al inicio de la tabla en móvil
            if (window.innerWidth < 768) {
                $('html, body').animate({
                    scrollTop: $('#tablaContainer').offset().top - 70
                }, 300);
            }
        }
    }
    
    // Eventos
    $(document).ready(function() {
        cargarDistribuidores(1);
        
        $('#busqueda').on('input', buscarConDebounce);
        $('#estado_verificacion, #estado_activo').on('change', () => cargarDistribuidores(1));
        $('#btnLimpiarFiltros').on('click', limpiarFiltros);
        
        $(document).on('click', '.ver-archivo', function(e) {
            e.preventDefault();
            window.abrirArchivoModal($(this).data('archivo'), $(this).data('tipo'), $(this).data('nombre'), $(this).data('titulo'));
        });
        
        $(document).on('click', '.page-link', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) cambiarPagina(page);
        });
        
        // Sidebar
        const sidebar = $('#sidebar'), backdrop = $('#sidebarBackdrop'), toggle = $('#sidebarToggle');
        toggle.on('click', () => { sidebar.toggleClass('show'); backdrop.toggleClass('show'); });
        backdrop.on('click', () => { sidebar.removeClass('show'); backdrop.removeClass('show'); });
        $(window).on('resize', () => { if (window.innerWidth >= 768) { sidebar.removeClass('show'); backdrop.removeClass('show'); } });
    });
    
    // Detectar cambios de orientación y recargar si es necesario
    let lastOrientation = window.orientation;
    window.addEventListener('orientationchange', function() {
        setTimeout(() => {
            if (window.orientation !== lastOrientation) {
                lastOrientation = window.orientation;
                cargarDistribuidores(currentPage, true);
            }
        }, 100);
    });
    
    // Mejorar la experiencia táctil en móviles
    if ('ontouchstart' in window) {
        $(document).on('touchstart', '.distribuidor-card .btn', function(e) {
            // Prevenir el zoom doble tap
            e.preventDefault();
            $(this).trigger('click');
        });
    }
    
    // Limpiar modales al cerrar
    $('#modalArchivo').on('hidden.bs.modal', function() {
        $('#imagenVisor').attr('src', '');
        $('#pdfVisor').attr('src', '');
        $('#archivoCargando, #visorImagen, #visorPDF, #visorError').removeClass('d-none').addClass('d-none');
        $('#archivoCargando').removeClass('d-none');
    });
    
    $('#modalDetalle').on('hidden.bs.modal', function() { $('#detalleDistribuidor').html(''); });
    </script>
</body>
</html>