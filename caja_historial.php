<?php

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}
// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
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

// Conectar a la base de datos de la empresa
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener información de la empresa y colores personalizados
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

    // Si no hay colores configurados, usar valores por defecto
    $color_primario = $empresa_info['color_primario'] ?? '#27ae60';
    $color_secundario = $empresa_info['color_secundario'] ?? '#2ecc71';

    // Convertir color hexadecimal a RGB para CSS
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

    // Obtener lista de usuarios para el filtro
    $sql_usuarios = "SELECT id, nombre FROM usuarios WHERE sucursal_id = ? ORDER BY nombre";
    $stmt_usuarios = $conn->prepare($sql_usuarios);
    $stmt_usuarios->bind_param("i", $_SESSION['sucursal_id']);
    $stmt_usuarios->execute();
    $usuarios_result = $stmt_usuarios->get_result();
    $usuarios = $usuarios_result->fetch_all(MYSQLI_ASSOC);

    // Construir consulta base con filtros
    $sql = "SELECT c.*, u.nombre as usuario_nombre, s.nombre as sucursal_nombre 
            FROM caja c 
            JOIN usuarios u ON c.usuario_id = u.id 
            JOIN sucursales s ON c.sucursal_id = s.id 
            WHERE c.sucursal_id = ?";

    $params = array($_SESSION['sucursal_id']);
    $types = "i";

    // Aplicar filtros si existen
    if (isset($_GET['fecha_desde']) && !empty($_GET['fecha_desde'])) {
        $sql .= " AND DATE(c.fecha_apertura) >= ?";
        $params[] = $_GET['fecha_desde'];
        $types .= "s";
    }

    if (isset($_GET['fecha_hasta']) && !empty($_GET['fecha_hasta'])) {
        $sql .= " AND DATE(c.fecha_apertura) <= ?";
        $params[] = $_GET['fecha_hasta'];
        $types .= "s";
    }

    if (isset($_GET['usuario']) && !empty($_GET['usuario'])) {
        $sql .= " AND c.usuario_id = ?";
        $params[] = $_GET['usuario'];
        $types .= "i";
    }

    if (isset($_GET['estado']) && !empty($_GET['estado'])) {
        $sql .= " AND c.estado = ?";
        $params[] = $_GET['estado'];
        $types .= "s";
    }

    $sql .= " ORDER BY c.fecha_apertura DESC LIMIT 50";

    // Preparar y ejecutar consulta
    $stmt = $conn->prepare($sql);

    if (count($params) > 1) {
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param("i", $_SESSION['sucursal_id']);
    }

    $stmt->execute();
    $cajas = $stmt->get_result();
    $cajas_data = $cajas->fetch_all(MYSQLI_ASSOC);

    // Contadores para estadísticas
    $cajas_abiertas = 0;
    $mi_caja_abierta = false;
    foreach ($cajas_data as $caja) {
        if ($caja['estado'] == 'abierta') {
            $cajas_abiertas++;
            if ($caja['usuario_id'] == $_SESSION['usuario_id']) {
                $mi_caja_abierta = true;
            }
        }
    }
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
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars($color_primario); ?>;
            --secondary-color: <?php echo htmlspecialchars($color_secundario); ?>;
            --primary-color-rgb: <?php echo $color_primario_rgb; ?>;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: background-color 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-x: hidden;
            -webkit-tap-highlight-color: transparent;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

         .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform;
            transform: translate3d(0, 0, 0);
            -webkit-transform: translate3d(0, 0, 0);
        }

        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card:hover {
            transform: translateY(-2px);
        }

        /* Botones con colores personalizados */
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

        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
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
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
            transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-backdrop.show {
            display: block;
            opacity: 1;
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
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
                touch-action: pan-y;
                transform: translate3d(-100%, 0, 0);
                -webkit-transform: translate3d(-100%, 0, 0);
                transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                will-change: transform;
            }

            .sidebar.show {
                transform: translate3d(0, 0, 0);
                -webkit-transform: translate3d(0, 0, 0);
                box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
            }

            main {
                margin-left: 0 !important;
                transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                will-change: transform;
            }

            .sidebar-open main {
                transform: translate3d(280px, 0, 0);
                -webkit-transform: translate3d(280px, 0, 0);
            }

            .btn,
            .nav-link,
            .form-control,
            .form-select {
                min-height: 44px;
            }

            .btn-group-actions .btn {
                padding: 0.5rem;
                min-width: 44px;
                min-height: 44px;
            }

            .table-responsive {
                -webkit-overflow-scrolling: touch;
                overflow-x: auto;
            }

            @supports (-webkit-touch-callout: none) {

                input,
                select,
                textarea {
                    font-size: 16px;
                }
            }

            /* Feedback de swipe */
            body.swipe-right {
                background: linear-gradient(to right, rgba(var(--primary-color-rgb), 0.05), transparent 100px);
            }

            body.swipe-left {
                background: linear-gradient(to left, rgba(255, 59, 48, 0.05), transparent 100px);
            }

            /* Mejorar el desplazamiento */
            * {
                -webkit-overflow-scrolling: touch;
                scroll-behavior: smooth;
            }
        }

        @media (max-width: 575.98px) {
            .btn-group-actions .btn {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }

            .swipe-threshold {
                min-width: 30px;
            }
        }

        /* Mejoras visuales para la tabla */
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            background-color: #f8f9fa;
        }

        .badge {
            font-size: 0.75em;
        }

        .filter-active {
            background-color: #e7f3ff !important;
            border-left: 4px solid var(--primary-color) !important;
        }

        .stat-card {
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        /* Colores personalizados para badges */
        .badge.bg-primary {
            background-color: var(--primary-color) !important;
        }

        .badge.bg-success {
            background-color: var(--secondary-color) !important;
        }

        .metric-value.text-primary {
            color: var(--primary-color) !important;
        }

        .metric-value.text-success {
            color: var(--secondary-color) !important;
        }

        .fa-2x.text-primary {
            color: var(--primary-color) !important;
        }

        .fa-2x.text-success {
            color: var(--secondary-color) !important;
        }

        /* Indicador de swipe */
        .swipe-hint {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(0);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 10px 18px;
            border-radius: 25px;
            font-size: 13px;
            z-index: 9999;
            animation: pulse 2s infinite, float 3s ease-in-out infinite;
            backdrop-filter: blur(10px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 0.8;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            }

            50% {
                opacity: 1;
                box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            }
        }

        @keyframes float {

            0%,
            100% {
                transform: translateX(-50%) translateY(0);
            }

            50% {
                transform: translateX(-50%) translateY(-5px);
            }
        }

        /* Smooth scrolling para toda la página */
        html {
            scroll-behavior: smooth;
        }

        /* Transiciones suaves para elementos interactivos */
        .btn,
        .nav-link,
        .form-control,
        .form-select,
        .badge {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Mejorar el rendimiento de animaciones */
        .sidebar,
        .sidebar-backdrop,
        main {
            will-change: transform, opacity;
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
        }

        /* Efecto de overlay en el contenido cuando el sidebar está abierto */
        .content-overlay {
            position: fixed;
            top: 56px;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1045;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .content-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        /* Efecto parallax suave */
        .parallax-item {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Loading spinner suave */
        .spinner-border {
            animation: spinner-border 0.75s linear infinite;
        }

        @keyframes spinner-border {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
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
                            <a class="nav-link" href="Inicio">
                                <i class="fas fa-tachometer-alt"></i>
                                Inicio
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Usuarios">
                                    <i class="fas fa-user-cog"></i>
                                    Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="Caja">
                                <i class="fas fa-cash-register"></i>
                                Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Productos">
                                <i class="fas fa-boxes"></i>
                                Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Clientes">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Ventas">
                                <i class="fas fa-receipt"></i>
                                Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="CortesCaja">
                                <i class="fas fa-cash-register"></i>
                                Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Proveedores">
                                <i class="fas fa-truck"></i>
                                Proveedores
                            </a>
                        </li>
                         <!-- MENÚ DE SUCURSALES CONDICIONAL -->
                          <?php if ($empresa_plan !== 'basico'  && $_SESSION['usuario_rol'] === 'admin' ): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Sucursales">
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
                            <a class="nav-link" href="Reportes">
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
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i>
                                    Comisiones
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="Configuracion">
                                    <i class="fas fa-cogs"></i>
                                    Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
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
                            <div class="col-md-3">
                                <label for="fecha_desde" class="form-label">Fecha Desde</label>
                                <input type="date" class="form-control" id="fecha_desde" name="fecha_desde"
                                    value="<?php echo isset($_GET['fecha_desde']) ? htmlspecialchars($_GET['fecha_desde']) : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_hasta" class="form-label">Fecha Hasta</label>
                                <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta"
                                    value="<?php echo isset($_GET['fecha_hasta']) ? htmlspecialchars($_GET['fecha_hasta']) : ''; ?>">
                            </div>
                            <div class="col-md-3">
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
                            <div class="col-md-3">
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

                                <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                    <span class="ms-2 text-muted">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Mostrando resultados filtrados
                                        </small>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Estadísticas rápidas -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])) ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Cortes</div>
                                        <div class="metric-value text-primary"><?php echo count($cajas_data); ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-cash-register fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                                <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Filtrado</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
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
                                <?php if (isset($_GET['estado']) && $_GET['estado'] == 'abierta'): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Filtrado por abiertas</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'cerrada') ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Cajas Cerradas</div>
                                        <div class="metric-value text-success"><?php echo count($cajas_data) - $cajas_abiertas; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-lock fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                                <?php if (isset($_GET['estado']) && $_GET['estado'] == 'cerrada'): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Filtrado por cerradas</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
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
                            <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                <small class="text-muted ms-2">(resultados filtrados)</small>
                            <?php endif; ?>
                        </h5>
                        <span class="badge bg-primary"><?php echo count($cajas_data); ?> registros</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($cajas_data) > 0): ?>
                            <div class="table-responsive">
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

    <!-- Indicador de swipe para nuevos usuarios -->
    <?php if (!isset($_COOKIE['swipe_hint_seen']) && !isset($_SESSION['swipe_hint_seen'])): ?>
        <div class="swipe-hint d-md-none">
            <i class="fas fa-arrows-left-right me-2"></i>Desliza para abrir/cerrar menú
        </div>
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            const mainContent = document.querySelector('main');

            // Variables para control de swipe
            let touchStartX = 0;
            let touchEndX = 0;
            let touchStartY = 0;
            let touchEndY = 0;
            let isSwiping = false;
            let swipeThreshold = 50; // Mínimo de píxeles para considerar swipe
            let verticalThreshold = 30; // Umbral vertical para evitar swipes accidentales

            // Función para mostrar/ocultar sidebar
            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';

                // Ocultar indicador de swipe si está visible
                const swipeHint = document.querySelector('.swipe-hint');
                if (swipeHint) {
                    swipeHint.style.display = 'none';
                    // Guardar en cookie para no mostrar de nuevo
                    document.cookie = "swipe_hint_seen=true; max-age=86400; path=/";
                    <?php $_SESSION['swipe_hint_seen'] = true; ?>
                }
            }

            // Event listeners básicos
            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarBackdrop.addEventListener('click', toggleSidebar);

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        toggleSidebar();
                    }
                });
            });

            // Función para detectar swipe
            function handleSwipe() {
                const distanceX = touchEndX - touchStartX;
                const distanceY = Math.abs(touchEndY - touchStartY);

                // Solo procesar si es principalmente horizontal y en móvil
                if (window.innerWidth >= 768) return false;

                // Solo procesar si es principalmente horizontal
                if (Math.abs(distanceX) > distanceY && distanceY < verticalThreshold) {
                    // Swipe de derecha a izquierda (cerrar sidebar)
                    if (distanceX < -swipeThreshold && sidebar.classList.contains('show')) {
                        toggleSidebar();
                        return true;
                    }
                    // Swipe de izquierda a derecha (abrir sidebar)
                    else if (distanceX > swipeThreshold && !sidebar.classList.contains('show')) {
                        toggleSidebar();
                        return true;
                    }
                }
                return false;
            }

            // Event listeners para swipe en todo el documento
            document.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
                touchStartY = e.changedTouches[0].screenY;
                isSwiping = true;
            }, {
                passive: true
            });

            document.addEventListener('touchmove', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;

                // Prevenir scroll vertical durante swipe horizontal
                const distanceX = Math.abs(touchEndX - touchStartX);
                const distanceY = Math.abs(touchEndY - touchStartY);

                if (distanceX > distanceY && distanceX > 10) {
                    e.preventDefault();
                }
            }, {
                passive: false
            });

            document.addEventListener('touchend', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;

                handleSwipe();
                isSwiping = false;

                // Remover clases de feedback
                document.body.classList.remove('swipe-right', 'swipe-left');
            }, {
                passive: true
            });

            // Event listener para cancelar swipe
            document.addEventListener('touchcancel', function() {
                isSwiping = false;
                document.body.classList.remove('swipe-right', 'swipe-left');
            }, {
                passive: true
            });

            // Swipe específico en el sidebar para cerrar
            sidebar.addEventListener('touchstart', function(e) {
                touchStartX = e.touches[0].clientX;
            }, {
                passive: true
            });

            // Configuración de fechas
            const today = new Date().toISOString().split('T')[0];
            const fechaDesdeInput = document.getElementById('fecha_desde');
            const fechaHastaInput = document.getElementById('fecha_hasta');

            if (fechaDesdeInput) fechaDesdeInput.max = today;
            if (fechaHastaInput) fechaHastaInput.max = today;

            // Validación de fechas
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

            // Mejoras visuales para las métricas
            const metricValues = document.querySelectorAll('.metric-value');
            metricValues.forEach(metric => {
                metric.style.fontSize = '1.8rem';
                metric.style.fontWeight = '700';
            });

            const metricLabels = document.querySelectorAll('.metric-label');
            metricLabels.forEach(label => {
                label.style.fontSize = '0.875rem';
                label.style.color = '#6c757d';
                label.style.textTransform = 'uppercase';
                label.style.letterSpacing = '0.5px';
            });

            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                if (!card.classList.contains('filter-active')) {
                    card.style.borderLeft = '4px solid var(--primary-color)';
                }
            });

            // Prevenir scroll del body cuando el sidebar está abierto
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

            observer.observe(sidebar, {
                attributes: true
            });

            // Detectar cambios en el tamaño de la ventana
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768 && sidebar.classList.contains('show')) {
                    toggleSidebar();
                }
            });

            // Feedback visual durante el swipe
            document.addEventListener('touchmove', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                const touch = e.touches[0];
                const distanceX = touch.screenX - touchStartX;

                // Solo mostrar feedback si es un swipe horizontal significativo
                if (Math.abs(distanceX) > 10) {
                    // Agregar clase al body para feedback visual
                    if (distanceX > 0 && !sidebar.classList.contains('show')) {
                        document.body.classList.add('swipe-right');
                        document.body.classList.remove('swipe-left');
                    } else if (distanceX < 0 && sidebar.classList.contains('show')) {
                        document.body.classList.add('swipe-left');
                        document.body.classList.remove('swipe-right');
                    }
                }
            }, {
                passive: true
            });

            // Ocultar indicador de swipe después de 5 segundos
            const swipeHint = document.querySelector('.swipe-hint');
            if (swipeHint) {
                setTimeout(function() {
                    swipeHint.style.display = 'none';
                    // Guardar en cookie para no mostrar de nuevo
                    document.cookie = "swipe_hint_seen=true; max-age=86400; path=/";
                    <?php $_SESSION['swipe_hint_seen'] = true; ?>
                }, 5000);
            }

            // Asegurar que el sidebar se cierre al tocar fuera en móvil
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 768 &&
                    sidebar.classList.contains('show') &&
                    !sidebar.contains(e.target) &&
                    !sidebarToggle.contains(e.target)) {
                    toggleSidebar();
                }
            });

            // Mejorar accesibilidad del sidebar
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                    toggleSidebar();
                }
            });

            // Inicializar tooltips de Bootstrap
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>

</html>
