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
$empresas = [];
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 10;

// Obtener estadísticas
$estadisticas = [
    'total_empresas' => 0,
    'aprobadas' => 0,
    'pendientes' => 0,
    'en_revision' => 0,
    'rechazadas' => 0
];

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

try {
    // Obtener estadísticas
    $sql_stats = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobadas,
        SUM(CASE WHEN estado_verificacion = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
        SUM(CASE WHEN estado_verificacion = 'en_revision' THEN 1 ELSE 0 END) as en_revision,
        SUM(CASE WHEN estado_verificacion = 'rechazado' THEN 1 ELSE 0 END) as rechazadas
        FROM empresas";

    $result_stats = $conn->query($sql_stats);
    if ($result_stats) {
        $stats = $result_stats->fetch_assoc();
        $estadisticas['total_empresas'] = $stats['total'] ?? 0;
        $estadisticas['aprobadas'] = $stats['aprobadas'] ?? 0;
        $estadisticas['pendientes'] = $stats['pendientes'] ?? 0;
        $estadisticas['en_revision'] = $stats['en_revision'] ?? 0;
        $estadisticas['rechazadas'] = $stats['rechazadas'] ?? 0;
    }

    // Construir consulta base con filtros
    $sql_where = "WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($filtro_estado) && $filtro_estado !== 'todos') {
        $sql_where .= " AND e.estado_verificacion = ?";
        $params[] = $filtro_estado;
        $types .= "s";
    }

    if (!empty($filtro_busqueda)) {
        $sql_where .= " AND (e.nombre_empresa LIKE ? OR e.email LIKE ? OR e.rfc LIKE ? OR e.telefono LIKE ?)";
        $busqueda_param = "%" . $filtro_busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $types .= "ssss";
    }

    // Contar total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM empresas e $sql_where";
    $stmt_count = $conn->prepare($sql_count);

    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }

    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_registros = $result_count->fetch_assoc()['total'];
    $stmt_count->close();

    // Calcular paginación
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina_actual - 1) * $registros_por_pagina;

    // Consulta principal con paginación
    $sql = "SELECT 
                e.id,
                e.nombre_empresa,
                e.giro_comercial,
                g.nombre as nombre_giro,
                e.rfc,
                e.telefono,
                e.email,
                e.direccion,
                e.nombre_contacto,
                e.usuario_admin,
                e.email_admin,
                e.nombre_base_datos,
                e.usuario_base_datos,
                e.constancia_fiscal,
                e.credencial_identificacion,
                e.fecha_subida_constancia,
                e.fecha_subida_credencial,
                e.declaracion_veracidad,
                e.estado_verificacion,
                e.observaciones_verificacion,
                e.fecha_verificacion,
                e.correo_enviado,
                e.fecha_envio_correo,
                e.fecha_creacion,
                e.activo
            FROM empresas e
            LEFT JOIN giro_comercial g ON e.giro_comercial = g.id
            $sql_where 
            ORDER BY e.fecha_creacion DESC 
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);

    // Agregar parámetros de filtro si existen
    if (!empty($params)) {
        $types .= "ii";
        $params[] = $registros_por_pagina;
        $params[] = $offset;
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param("ii", $registros_por_pagina, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    // Obtener todas las empresas
    while ($row = $result->fetch_assoc()) {
        $empresas[] = $row;
    }

    $stmt->close();
} catch (Exception $e) {
    $mensaje = "Error al cargar las empresas: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

$conn->close();

// Función para formatear fecha
function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

// Función para obtener la clase del estado
function claseEstado($estado)
{
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
function textoEstado($estado)
{
    $estados = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Revisión',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado'
    ];
    return $estados[$estado] ?? $estado;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Panel de Administración</title>
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

        .welcome-card .logo-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .ingresos-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
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

        /* Mejoras para el sidebar táctil */
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

        .accion-btn {
            margin: 0 2px;
            padding: 5px 10px;
            font-size: 0.85rem;
        }

        .filtros-card {
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        /* Mejoras para estadísticas en móvil */
        @media (max-width: 575.98px) {
            .col-md-2 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .col-md-4 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .metric-value {
                font-size: 1.5rem;
            }
        }

        /* Agrega este CSS al estilo existente */
        .logo-placeholder img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
        }

        /* Para el logo en el navbar */
        .navbar-brand img {
            height: 30px;
            width: auto;
        }

        /* Estilos para imágenes responsivas */
        .img-logo-navbar {
            height: 30px;
            width: auto;
            max-height: 100%;
        }

        .img-logo-welcome {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
        }

        /* ========================================= */
        /* ESTILOS CORREGIDOS PARA EL VISOR DE ARCHIVOS */
        /* ========================================= */

        #modalArchivo .modal-body {
            min-height: 500px;
            padding: 0 !important;
            position: relative;
        }

        #modalArchivo .modal-dialog {
            max-width: 90%;
            height: 90vh;
        }

        #modalArchivo .modal-content {
            height: 90vh;
        }

        #modalArchivo .modal-header,
        #modalArchivo .modal-footer {
            flex-shrink: 0;
        }

        #modalArchivo .modal-body {
            flex: 1;
            overflow: hidden;
        }

        #archivoCargando {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.9);
            z-index: 10;
        }

        #visorImagen {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            padding: 20px;
        }

        #visorImagen img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        #visorPDF {
            width: 100%;
            height: 100%;
            background-color: #f8f9fa;
        }

        #visorPDF iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        #visorError {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            padding: 20px;
        }

        /* Para móviles */
        @media (max-width: 767.98px) {
            .img-logo-navbar {
                height: 25px;
            }

            .img-logo-welcome {
                width: 50px;
                height: 50px;
            }

            #modalArchivo .modal-dialog {
                max-width: 95%;
                height: 80vh;
                margin: 10px auto;
            }

            #modalArchivo .modal-content {
                height: 80vh;
            }

            .modal-xl {
                max-width: 95%;
            }
        }

        @media (max-width: 991.98px) {
            .modal-xl {
                max-width: 95%;
            }

            #modalArchivo .modal-dialog {
                max-width: 95%;
            }
        }

        /* ========================================= */
        /* ESTILOS PARA SCROLL TÁCTIL EN TABLA */
        /* ========================================= */

        .table-responsive {
            position: relative;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #27ae60 #f1f1f1;
            scroll-behavior: smooth;
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

        /* Prevenir selección de texto durante el swipe */
        .table-responsive * {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* Permitir selección solo en inputs y textareas dentro de la tabla */
        .table-responsive input,
        .table-responsive textarea,
        .table-responsive select {
            -webkit-user-select: auto;
            user-select: auto;
        }

        /* Feedback visual durante el scroll */
        .table-responsive.touch-scrolling {
            cursor: grabbing;
            cursor: -webkit-grabbing;
        }

        /* Indicador visual en móvil */
        @media (max-width: 767.98px) {
            .table-responsive::after {
                content: '← Desliza para ver más →';
                position: absolute;
                bottom: 5px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(39, 174, 96, 0.9);
                color: white;
                padding: 3px 10px;
                border-radius: 15px;
                font-size: 0.75rem;
                white-space: nowrap;
                pointer-events: none;
                opacity: 0;
                transition: opacity 0.3s;
                z-index: 5;
            }

            .table-responsive:hover::after,
            .table-responsive.touch-active::after {
                opacity: 1;
            }
        }

        /* Botones de navegación para scroll */
        .scroll-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(39, 174, 96, 0.8);
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            opacity: 0;
            transition: opacity 0.3s, background 0.3s;
        }

        .scroll-btn:hover {
            background: rgba(39, 174, 96, 1);
        }

        .scroll-btn-left {
            left: 10px;
        }

        .scroll-btn-right {
            right: 10px;
        }

        .table-responsive:hover .scroll-btn {
            opacity: 1;
        }

        /* Solo mostrar botones en escritorio */
        @media (max-width: 767.98px) {
            .scroll-btn {
                display: none;
            }
        }

        /* Mejora la experiencia táctil */
        .table-hover tr {
            cursor: default;
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
                <!-- Reemplazar ícono por imagen -->
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
                            <a class="nav-link active" href="dashboard.php">
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
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Welcome Card -->
                <div class="card welcome-card mb-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <!-- Sección izquierda con logo y texto -->
                            <div class="d-flex align-items-center">
                                <!-- Logo/Imagen -->
                                <div class="me-3">
                                    <img src="../images/LibertyfinBlanco.png"
                                        alt="Logo Empresa"
                                        style="height: 40px; width: auto;">
                                </div>

                                <!-- Texto de bienvenida -->
                                <div>
                                    <h4 class="card-title mb-1">Panel de Administración</h4>
                                    <div class="d-flex align-items-center">
                                        <p class="card-text mb-0 me-3 opacity-75">
                                            <i class="fas fa-user me-1"></i>
                                            Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Administrador'); ?>
                                        </p>
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-user-tag me-1"></i>Administrador
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Sección derecha con información adicional -->
                            <div class="text-end">
                                <div class="d-flex align-items-center">
                                    <div class="me-3 text-start">
                                        <small class="text-white-50 d-block">Distribuidores totales</small>
                                        <span class="h5 mb-0 text-white"><?php echo $estadisticas['total_empresas']; ?></span>
                                    </div>
                                    <div>
                                        <i class="fas fa-chart-line fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Empresas</div>
                                        <div class="metric-value text-primary"><?php echo $estadisticas['total_empresas']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-building fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Aprobadas</div>
                                        <div class="metric-value text-success"><?php echo $estadisticas['aprobadas']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Pendientes</div>
                                        <div class="metric-value text-warning"><?php echo $estadisticas['pendientes']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clock fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">En Revisión</div>
                                        <div class="metric-value text-info"><?php echo $estadisticas['en_revision']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-search fa-2x text-info opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card ingresos-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label text-white-50">Rechazadas</div>
                                        <div class="metric-value text-white"><?php echo $estadisticas['rechazadas']; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x text-white opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros y Búsqueda -->
                <div class="filtros-card mb-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Estado de Verificación:</label>
                            <select name="estado" class="form-select">
                                <option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                                <option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                <option value="en_revision" <?php echo $filtro_estado === 'en_revision' ? 'selected' : ''; ?>>En Revisión</option>
                                <option value="aprobado" <?php echo $filtro_estado === 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                <option value="rechazado" <?php echo $filtro_estado === 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Buscar:</label>
                            <input type="text" name="busqueda" class="form-control"
                                placeholder="Nombre, email, RFC..."
                                value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search me-1"></i>Filtrar
                                </button>
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-1"></i>Limpiar
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Tabla de Empresas -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list-ul me-2"></i>Empresas Registradas
                        </h5>
                        <span class="badge bg-primary"><?php echo $total_registros; ?> registros</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Empresa</th>
                                        <th>Contacto</th>
                                        <th>Email</th>
                                        <th>Teléfono</th>
                                        <th>Estado</th>
                                        <th>Registro</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($empresas)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-building display-6 d-block mb-3"></i>
                                                    No se encontraron empresas
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($empresas as $empresa): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($empresa['nombre_contacto']); ?></td>
                                                <td>
                                                    <a href="mailto:<?php echo htmlspecialchars($empresa['email']); ?>">
                                                        <?php echo htmlspecialchars($empresa['email']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo htmlspecialchars($empresa['telefono'] ?? 'No especificado'); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo claseEstado($empresa['estado_verificacion']); ?> badge-estado">
                                                        <?php echo textoEstado($empresa['estado_verificacion']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatearFecha($empresa['fecha_creacion']); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info accion-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalDetalle"
                                                        data-id="<?php echo $empresa['id']; ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="gestionar_empresa.php?id=<?php echo $empresa['id']; ?>"
                                                        class="btn btn-sm btn-warning accion-btn">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-danger accion-btn">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación -->
                        <?php if ($total_paginas > 1): ?>
                            <nav aria-label="Paginación">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link"
                                            href="?pagina=<?php echo $pagina_actual - 1; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>

                                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                        <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                            <a class="page-link"
                                                href="?pagina=<?php echo $i; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link"
                                            href="?pagina=<?php echo $pagina_actual + 1; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Acciones Rápidas -->
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-bolt me-2"></i>Acciones Rápidas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 btn-group-actions">
                                    <div class="col-md-6">
                                        <a href="empresas.php" class="btn btn-primary w-100 text-start p-3">
                                            <i class="fas fa-building fa-2x mb-2"></i>
                                            <h6>Ver Todas las Empresas</h6>
                                            <small class="text-white-50">Gestión completa</small>
                                        </a>
                                    </div>
                                    <!-- <div class="col-md-6">
                                        <a href="nueva_empresa.php" class="btn btn-success w-100 text-start p-3">
                                            <i class="fas fa-plus-circle fa-2x mb-2"></i>
                                            <h6>Nueva Empresa</h6>
                                            <small class="text-white-50">Agregar nueva empresa</small>
                                        </a>
                                    </div> -->
                                    <div class="col-md-6">
                                        <a href="usuarios.php" class="btn btn-info w-100 text-start p-3">
                                            <i class="fas fa-user-cog fa-2x mb-2"></i>
                                            <h6>Usuarios Admin</h6>
                                            <small class="text-white-50">Administrar usuarios</small>
                                        </a>
                                    </div>
                                    <!-- <div class="col-md-6">
                                        <a href="reportes.php" class="btn btn-warning w-100 text-start p-3">
                                            <i class="fas fa-chart-bar fa-2x mb-2"></i>
                                            <h6>Reportes</h6>
                                            <small class="text-white-50">Ver estadísticas</small>
                                        </a>
                                    </div> -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Información del Sistema
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Sistema:</small>
                                    <p class="mb-1 fw-bold">Sistema de Administración</p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Empresas Registradas:</small>
                                    <p class="mb-1"><?php echo $estadisticas['total_empresas']; ?> empresas</p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Estado Actual:</small>
                                    <p class="mb-1"><span class="badge bg-success">Operativo</span></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Última Actualización:</small>
                                    <p class="mb-1"><?php echo date('d/m/Y H:i'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para ver detalles -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalles de la Empresa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleEmpresa">
                    <!-- Los detalles se cargarán aquí via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para ver archivos (compartido) -->
    <div class="modal fade" id="modalArchivo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalArchivoTitulo"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="min-height: 500px;">
                    <div class="d-flex justify-content-center align-items-center h-100" id="archivoCargando">
                        <div class="text-center">
                            <div class="spinner-border text-primary mb-3"></div>
                            <p>Cargando archivo...</p>
                        </div>
                    </div>
                    <div id="visorImagen" class="d-none text-center p-3">
                        <img id="imagenVisor" src="" alt="" class="img-fluid">
                    </div>
                    <div id="visorPDF" class="d-none h-100">
                        <iframe id="pdfVisor" src="" frameborder="0" class="w-100 h-100"></iframe>
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
                    <a href="#" id="descargarArchivo" class="btn btn-primary" download>
                        <i class="fas fa-download me-1"></i>Descargar
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
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

        // Variables para controlar el swipe
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        let isSidebarTouch = false;
        const SWIPE_THRESHOLD = 50; // Mínimo de píxeles para considerar un swipe
        const SWIPE_EDGE_ZONE = 30; // Zona del borde donde se activa el swipe
        const VERTICAL_THRESHOLD = 30; // Máxima desviación vertical permitida

        // Función para abrir el sidebar automáticamente
        function openSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.add('show');
                sidebarBackdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        // Función para cerrar el sidebar automáticamente
        function closeSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        // =============================================
        // FUNCIONALIDAD DE SWIPE HORIZONTAL PARA SCROLL DE TABLA
        // =============================================

        let tableTouchStartX = 0;
        let tableTouchStartY = 0;
        let tableTouchEndX = 0;
        let tableTouchEndY = 0;
        let tableIsScrolling = false;
        let tableTouchStartTime = 0;
        let tableScrollVelocity = 0;
        let tableLastScrollLeft = 0;

        // Función para detectar si estamos dentro de la tabla
        function isInsideTable(element) {
            while (element) {
                if (element.classList && element.classList.contains('table-responsive')) {
                    return true;
                }
                if (element.classList && element.classList.contains('table')) {
                    return true;
                }
                if (element.classList && element.classList.contains('table-hover')) {
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

        // Detectar inicio del touch en cualquier parte
        document.addEventListener('touchstart', function(e) {
            // Solo en dispositivos móviles
            if (window.innerWidth >= 768) return;

            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;

            // Verificar si es para el sidebar (tocar cerca del borde izquierdo y NO en la tabla)
            if (touchX <= SWIPE_EDGE_ZONE && !isInsideTable(e.target)) {
                isSidebarTouch = true;
                touchStartX = touchX;
                touchStartY = touchY;
                touchEndX = touchStartX;
                touchEndY = touchStartY;
            }

            // Verificar si es para la tabla
            if (isInsideTable(e.target)) {
                tableTouchStartX = touchX;
                tableTouchStartY = touchY;
                tableTouchEndX = tableTouchStartX;
                tableTouchEndY = tableTouchStartY;
                tableIsScrolling = false;
                tableTouchStartTime = Date.now();
                tableScrollVelocity = 0;

                // Obtener el contenedor de tabla
                const tableContainer = e.target.closest('.table-responsive');
                if (tableContainer) {
                    tableLastScrollLeft = tableContainer.scrollLeft;
                    tableContainer.classList.add('touch-active');
                }
            }
        }, {
            passive: true
        });

        // Detectar movimiento del touch - VERSIÓN CORREGIDA
        document.addEventListener('touchmove', function(e) {
            // Solo procesar en dispositivos móviles
            if (window.innerWidth >= 768) return;

            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;

            // Procesar movimiento para tabla
            if (tableTouchStartX > 0 && isInsideTable(e.target)) {
                tableTouchEndX = touchX;
                tableTouchEndY = touchY;

                const deltaX = tableTouchEndX - tableTouchStartX;
                const deltaY = tableTouchEndY - tableTouchStartY;
                const timeDelta = Date.now() - tableTouchStartTime;

                // Calcular velocidad
                tableScrollVelocity = deltaX / Math.max(timeDelta, 1);

                // Determinar si es movimiento horizontal o vertical
                const isHorizontalScroll = Math.abs(deltaX) > Math.abs(deltaY);

                if (isHorizontalScroll && Math.abs(deltaX) > 5) {
                    tableIsScrolling = true;

                    // Encontrar el contenedor de tabla más cercano
                    let tableContainer = e.target.closest('.table-responsive');
                    if (!tableContainer) {
                        return;
                    }

                    // Verificar si el contenedor ya tiene scroll horizontal
                    const canScrollHorizontally = tableContainer.scrollWidth > tableContainer.clientWidth;
                    if (!canScrollHorizontally) {
                        return;
                    }

                    // Agregar clase para feedback visual
                    tableContainer.classList.add('touch-scrolling');

                    // Calcular nueva posición de scroll
                    const newScrollLeft = tableLastScrollLeft - deltaX;

                    // Verificar límites
                    const maxScroll = tableContainer.scrollWidth - tableContainer.clientWidth;
                    const boundedScrollLeft = Math.max(0, Math.min(maxScroll, newScrollLeft));

                    // Aplicar el scroll
                    tableContainer.scrollLeft = boundedScrollLeft;

                    // Solo prevenir scroll vertical si estamos scrolleando horizontalmente
                    // y no estamos en los extremos del scroll
                    if (boundedScrollLeft > 0 && boundedScrollLeft < maxScroll) {
                        e.preventDefault();
                    }
                }
            }

            // Procesar movimiento para sidebar
            if (isSidebarTouch) {
                touchEndX = touchX;
                touchEndY = touchY;

                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                // Solo prevenir si es un movimiento horizontal significativo
                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                    e.preventDefault();
                }
            }
        }, {
            passive: false
        });

        // Detectar fin del touch
        document.addEventListener('touchend', function(e) {
            // Solo en dispositivos móviles
            if (window.innerWidth >= 768) return;

            // Procesar fin de touch para sidebar
            if (isSidebarTouch) {
                isSidebarTouch = false;

                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                // Verificar que sea un swipe horizontal válido
                if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
                    return; // Demasiada desviación vertical
                }

                const sidebar = document.getElementById('sidebar');
                const isSidebarOpen = sidebar && sidebar.classList.contains('show');

                // SWIPE DE IZQUIERDA A DERECHA (para abrir)
                if (deltaX > SWIPE_THRESHOLD) {
                    // Solo abrir si empezó cerca del borde izquierdo
                    if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                        openSidebarAuto();
                    }
                }
                // SWIPE DE DERECHA A IZQUIERDA (para cerrar)
                else if (deltaX < -SWIPE_THRESHOLD) {
                    // Cerrar si el sidebar está abierto
                    if (isSidebarOpen) {
                        closeSidebarAuto();
                    }
                }
            }

            // Procesar fin de touch para tabla
            if (tableTouchStartX > 0) {
                // Aplicar inercia al scroll si hay velocidad
                if (tableIsScrolling && Math.abs(tableScrollVelocity) > 0.5) {
                    const tableContainer = e.target.closest('.table-responsive');
                    if (tableContainer && tableContainer.scrollWidth > tableContainer.clientWidth) {
                        const inertiaDistance = tableScrollVelocity * 150; // Aumentado para mejor inercia
                        const currentScroll = tableContainer.scrollLeft;
                        const maxScroll = tableContainer.scrollWidth - tableContainer.clientWidth;

                        // Calcular scroll final con límites
                        const targetScroll = currentScroll - inertiaDistance;
                        const boundedTargetScroll = Math.max(0, Math.min(maxScroll, targetScroll));

                        // Aplicar scroll con animación suave
                        tableContainer.scrollTo({
                            left: boundedTargetScroll,
                            behavior: 'smooth'
                        });
                    }
                }

                // Remover clases
                if (tableIsScrolling) {
                    const tableContainer = e.target.closest('.table-responsive');
                    if (tableContainer) {
                        setTimeout(() => {
                            tableContainer.classList.remove('touch-scrolling');
                            tableContainer.classList.remove('touch-active');
                        }, 300);
                    }
                }
            }

            // Resetear variables de tabla
            tableTouchStartX = 0;
            tableTouchStartY = 0;
            tableTouchEndX = 0;
            tableTouchEndY = 0;
            tableIsScrolling = false;
            tableScrollVelocity = 0;
            tableLastScrollLeft = 0;

            // Resetear variables de sidebar
            touchStartX = 0;
            touchStartY = 0;
            touchEndX = 0;
            touchEndY = 0;
        }, {
            passive: true
        });

        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            // Función para mostrar/ocultar sidebar
            function toggleSidebar() {
                if (sidebar.classList.contains('show')) {
                    closeSidebarAuto();
                } else {
                    openSidebarAuto();
                }
            }

            // Event listeners para el botón hamburguesa
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebarAuto);
            }

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebarAuto();
                    }
                });
            });

            // Ajustar altura de cards en móvil
            function adjustCardHeights() {
                if (window.innerWidth < 576) {
                    const statCards = document.querySelectorAll('.stat-card');
                    let maxHeight = 0;

                    statCards.forEach(card => {
                        const height = card.offsetHeight;
                        if (height > maxHeight) {
                            maxHeight = height;
                        }
                    });

                    statCards.forEach(card => {
                        card.style.minHeight = maxHeight + 'px';
                    });
                } else {
                    const statCards = document.querySelectorAll('.stat-card');
                    statCards.forEach(card => {
                        card.style.minHeight = '';
                    });
                }
            }

            // Ejecutar al cargar y al redimensionar
            adjustCardHeights();
            window.addEventListener('resize', adjustCardHeights);

            // Ajustar sidebar en redimensionamiento
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebarAuto();
                }
            });

            // Agregar indicador visual de zona de swipe (opcional)
            const swipeZoneIndicator = document.createElement('div');
            swipeZoneIndicator.id = 'swipeZoneIndicator';
            swipeZoneIndicator.style.cssText = `
                position: fixed;
                top: 56px;
                left: 0;
                width: ${SWIPE_EDGE_ZONE}px;
                height: calc(100vh - 56px);
                background: linear-gradient(90deg, rgba(39, 174, 96, 0.2), transparent);
                z-index: 9999;
                pointer-events: none;
                opacity: 0;
                transition: opacity 0.3s;
                display: none;
            `;
            document.body.appendChild(swipeZoneIndicator);

            // Mostrar/ocultar indicador según el tamaño de pantalla
            function updateSwipeZone() {
                if (window.innerWidth < 768) {
                    swipeZoneIndicator.style.display = 'block';
                } else {
                    swipeZoneIndicator.style.display = 'none';
                }
            }

            updateSwipeZone();
            window.addEventListener('resize', updateSwipeZone);

            // Mostrar indicador al tocar cerca del borde
            document.addEventListener('touchstart', function(e) {
                if (window.innerWidth >= 768) return;

                const touchX = e.touches[0].clientX;
                if (touchX <= SWIPE_EDGE_ZONE && !isInsideTable(e.target)) {
                    swipeZoneIndicator.style.opacity = '0.5';
                }
            });

            document.addEventListener('touchend', function() {
                swipeZoneIndicator.style.opacity = '0';
            });

            // Mejorar la experiencia táctil del sidebar
            let sidebarStartX = 0;
            let sidebarCurrentX = 0;

            sidebar.addEventListener('touchstart', (e) => {
                sidebarStartX = e.touches[0].clientX;
            }, {
                passive: true
            });

            sidebar.addEventListener('touchmove', (e) => {
                sidebarCurrentX = e.touches[0].clientX;
                const diff = sidebarStartX - sidebarCurrentX;

                if (diff > 50) { // Deslizar hacia la izquierda para cerrar
                    closeSidebarAuto();
                }
            }, {
                passive: true
            });

            // Agregar botones de navegación para scroll en la tabla
            function addTableNavigationButtons() {
                const tableResponsives = document.querySelectorAll('.table-responsive');

                tableResponsives.forEach(container => {
                    // Verificar si ya tiene botones
                    if (container.querySelector('.scroll-btn')) return;

                    // Crear botones
                    const leftBtn = document.createElement('button');
                    leftBtn.className = 'scroll-btn scroll-btn-left';
                    leftBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
                    leftBtn.setAttribute('aria-label', 'Desplazar izquierda');
                    leftBtn.setAttribute('type', 'button');

                    const rightBtn = document.createElement('button');
                    rightBtn.className = 'scroll-btn scroll-btn-right';
                    rightBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
                    rightBtn.setAttribute('aria-label', 'Desplazar derecha');
                    rightBtn.setAttribute('type', 'button');

                    // Funcionalidad de los botones
                    leftBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        container.scrollBy({
                            left: -200,
                            behavior: 'smooth'
                        });
                    });

                    rightBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        container.scrollBy({
                            left: 200,
                            behavior: 'smooth'
                        });
                    });

                    // Agregar botones al contenedor
                    container.style.position = 'relative';
                    container.appendChild(leftBtn);
                    container.appendChild(rightBtn);

                    // Actualizar visibilidad de botones según scroll
                    function updateButtonVisibility() {
                        const scrollLeft = container.scrollLeft;
                        const maxScroll = container.scrollWidth - container.clientWidth;

                        // Botón izquierdo
                        if (scrollLeft <= 10) {
                            leftBtn.style.opacity = '0';
                            leftBtn.style.pointerEvents = 'none';
                        } else {
                            leftBtn.style.opacity = '0.8';
                            leftBtn.style.pointerEvents = 'auto';
                        }

                        // Botón derecho
                        if (scrollLeft >= maxScroll - 10) {
                            rightBtn.style.opacity = '0';
                            rightBtn.style.pointerEvents = 'none';
                        } else {
                            rightBtn.style.opacity = '0.8';
                            rightBtn.style.pointerEvents = 'auto';
                        }
                    }

                    container.addEventListener('scroll', updateButtonVisibility);

                    // Actualizar visibilidad al pasar el mouse
                    container.addEventListener('mouseenter', () => {
                        updateButtonVisibility();
                    });

                    // Inicializar visibilidad
                    updateButtonVisibility();
                });
            }

            // Solo agregar botones en escritorio
            if (window.innerWidth > 767.98) {
                addTableNavigationButtons();
            }

            // Volver a agregar botones si se cambia el tamaño a escritorio
            window.addEventListener('resize', function() {
                if (window.innerWidth > 767.98) {
                    addTableNavigationButtons();
                }
            });

            // Manejar el modal de detalles
            $('#modalDetalle').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var empresaId = button.data('id');
                var modal = $(this);

                // Mostrar cargando
                modal.find('#detalleEmpresa').html(`
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2">Cargando información...</p>
                    </div>
                `);

                // Cargar detalles via AJAX
                $.ajax({
                    url: 'ajax_detalle_empresa.php',
                    method: 'GET',
                    data: {
                        id: empresaId
                    },
                    success: function(response) {
                        modal.find('#detalleEmpresa').html(response);
                    },
                    error: function(xhr, status, error) {
                        modal.find('#detalleEmpresa').html(`
                            <div class="alert alert-danger">
                                <h6>Error al cargar los detalles</h6>
                                <p class="mb-0">No se pudieron cargar los detalles de la empresa. Intente nuevamente.</p>
                            </div>
                        `);
                    }
                });
            });

            // Limpiar modal cuando se cierra
            $('#modalDetalle').on('hidden.bs.modal', function() {
                $(this).find('#detalleEmpresa').html('');
            });
        });

        // =============================================
        // FUNCIONALIDAD PARA EL VISOR DE ARCHIVOS
        // =============================================

        // Función global para abrir archivos en el modal
        window.abrirArchivoModal = function(rutaArchivo, tipoArchivo, nombreArchivo, titulo) {
            console.log('Abriendo archivo:', {
                rutaArchivo,
                tipoArchivo,
                nombreArchivo,
                titulo
            });

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

            // Configurar modal
            modalTitulo.textContent = titulo;
            descargarBtn.href = rutaArchivo;
            descargarBtn.download = nombreArchivo;
            infoArchivo.textContent = nombreArchivo;

            // Resetear todos los visores
            cargando.classList.remove('d-none');
            visorImagen.classList.add('d-none');
            visorPDF.classList.add('d-none');
            visorError.classList.add('d-none');

            // Mostrar modal primero
            modal.show();

            // Ajustar el modal para que ocupe más espacio
            modalElement.addEventListener('shown.bs.modal', function onShown() {
                // Remover el event listener para que no se ejecute múltiples veces
                modalElement.removeEventListener('shown.bs.modal', onShown);

                // Obtener dimensiones del modal body
                const modalBody = modalElement.querySelector('.modal-body');
                const modalHeader = modalElement.querySelector('.modal-header');
                const modalFooter = modalElement.querySelector('.modal-footer');

                const headerHeight = modalHeader ? modalHeader.offsetHeight : 0;
                const footerHeight = modalFooter ? modalFooter.offsetHeight : 0;
                const windowHeight = window.innerHeight;
                const maxModalHeight = windowHeight * 0.9;
                const modalBodyHeight = maxModalHeight - headerHeight - footerHeight - 40; // 40px de padding/margin

                console.log('Dimensiones:', {
                    windowHeight,
                    headerHeight,
                    footerHeight,
                    maxModalHeight,
                    modalBodyHeight
                });

                // Configurar visor según tipo de archivo
                if (tipoArchivo === 'imagen') {
                    // Precargar imagen
                    const img = new Image();
                    img.onload = function() {
                        imagenVisor.src = rutaArchivo;
                        cargando.classList.add('d-none');
                        visorImagen.classList.remove('d-none');

                        // Ajustar tamaño de la imagen
                        const maxWidth = modalBody.offsetWidth - 40;
                        const maxHeight = modalBodyHeight - 40;

                        if (this.width > maxWidth || this.height > maxHeight) {
                            const ratio = Math.min(maxWidth / this.width, maxHeight / this.height);
                            imagenVisor.style.width = (this.width * ratio) + 'px';
                            imagenVisor.style.height = (this.height * ratio) + 'px';
                        }

                        // Añadir información de tamaño
                        infoArchivo.textContent = `${nombreArchivo} (${this.width}×${this.height}px)`;
                    };
                    img.onerror = function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    };
                    img.src = rutaArchivo;

                } else if (tipoArchivo === 'pdf') {
                    // Configurar iframe para PDF
                    pdfVisor.src = rutaArchivo + '#view=fitH';

                    // Configurar altura del iframe
                    pdfVisor.style.height = modalBodyHeight + 'px';

                    // Evento cuando el PDF se carga
                    const onPDFLoad = function() {
                        cargando.classList.add('d-none');
                        visorPDF.classList.remove('d-none');
                        console.log('PDF cargado, altura iframe:', pdfVisor.offsetHeight);
                    };

                    pdfVisor.onload = onPDFLoad;
                    pdfVisor.onerror = function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    };

                    // Timeout de seguridad
                    setTimeout(function() {
                        if (!cargando.classList.contains('d-none')) {
                            onPDFLoad();
                        }
                    }, 3000);
                } else {
                    // Tipo no reconocido
                    setTimeout(function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    }, 500);
                }

                // Forzar redibujado
                modalBody.style.display = 'none';
                modalBody.offsetHeight; // Trigger reflow
                modalBody.style.display = 'block';
            });
        };

        // Delegación de eventos para botones dinámicos (IMPORTANTE para contenido AJAX)
        $(document).on('click', '.ver-archivo', function(e) {
            e.preventDefault();
            const ruta = $(this).data('archivo');
            const tipo = $(this).data('tipo');
            const nombre = $(this).data('nombre');
            const titulo = $(this).data('titulo');

            console.log('Botón clickeado:', {
                ruta,
                tipo,
                nombre,
                titulo
            });

            if (ruta && tipo && nombre && titulo) {
                if (typeof window.abrirArchivoModal === 'function') {
                    window.abrirArchivoModal(ruta, tipo, nombre, titulo);
                } else {
                    console.error('La función abrirArchivoModal no está definida');
                    // Fallback: abrir en nueva pestaña
                    window.open(ruta, '_blank');
                }
            } else {
                console.error('Faltan datos para abrir el archivo');
            }
        });

        // Limpiar modal de archivos al cerrar
        $('#modalArchivo').on('hidden.bs.modal', function() {
            const imagenVisor = document.getElementById('imagenVisor');
            const pdfVisor = document.getElementById('pdfVisor');

            // Limpiar recursos
            if (imagenVisor) {
                imagenVisor.src = '';
                imagenVisor.style.width = '';
                imagenVisor.style.height = '';
            }
            if (pdfVisor) {
                pdfVisor.src = '';
                pdfVisor.style.height = '100%';
            }

            // Ocultar todos los visores
            document.getElementById('archivoCargando').classList.remove('d-none');
            document.getElementById('visorImagen').classList.add('d-none');
            document.getElementById('visorPDF').classList.add('d-none');
            document.getElementById('visorError').classList.add('d-none');
        });

        // Ajustar altura cuando se redimensiona la ventana
        $(window).on('resize', function() {
            const modal = document.getElementById('modalArchivo');
            if (modal && modal.classList.contains('show')) {
                const pdfVisor = document.getElementById('pdfVisor');
                if (pdfVisor && !pdfVisor.classList.contains('d-none')) {
                    const modalBody = modal.querySelector('.modal-body');
                    const modalHeader = modal.querySelector('.modal-header');
                    const modalFooter = modal.querySelector('.modal-footer');

                    if (modalBody && modalHeader && modalFooter) {
                        const headerHeight = modalHeader.offsetHeight;
                        const footerHeight = modalFooter.offsetHeight;
                        const windowHeight = window.innerHeight;
                        const maxModalHeight = windowHeight * 0.9;
                        const modalBodyHeight = maxModalHeight - headerHeight - footerHeight - 40;

                        pdfVisor.style.height = modalBodyHeight + 'px';
                    }
                }
            }
        });
    </script>
</body>

</html>