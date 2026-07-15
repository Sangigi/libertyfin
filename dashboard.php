<?php
// dashboard.php

ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Login");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// Conectar a la base de datos de la empresa usando PDO
try {
    // Conexión a la base de datos de la empresa (usando la función de database.php)
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Obtener configuración de colores, información de la empresa Y EL LOGO
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

    // Función segura para obtener valores de configuración
    function getConfigValue($config, $key, $default = '') {
        return isset($config[$key]) ? $config[$key] : $default;
    }

    // Obtener estadísticas básicas
    $sql_estadisticas = "
        SELECT 
            (SELECT COUNT(*) FROM productos WHERE activo = TRUE) as total_productos,
            (SELECT COUNT(*) FROM clientes WHERE activo = TRUE) as total_clientes,
            (SELECT COUNT(*) FROM usuarios WHERE activo = TRUE) as total_usuarios,
            (SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()) as ventas_hoy,
            (SELECT COALESCE(SUM(total), 0) FROM ventas WHERE DATE(fecha) = CURDATE()) as ingresos_hoy
    ";
    $result_estadisticas = $conn->query($sql_estadisticas);
    $estadisticas = $result_estadisticas->fetch(PDO::FETCH_ASSOC);

    // OBTENER EL PLAN DE LA EMPRESA Y DATOS DE TIMBRES DESDE LA BASE DE DATOS PRINCIPAL
    // Usando la función getDBConnection() de database.php
    $conn_main = getDBConnection();

    // Valores por defecto
    $empresa_plan = "prueba";
    $timbres_totales = 0;
    $timbres_disponibles = 0;

    $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([$_SESSION['empresa_id']]);
    $result_empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);

    if ($result_empresa) {
        $empresa_plan = $result_empresa['plan'];
        $timbres_totales = $result_empresa['timbres_totales'] ?? 0;
        $timbres_disponibles = $result_empresa['timbres_disponibles'] ?? 0;
    }

    // Guardar el plan en la sesión
    $_SESSION['empresa_plan'] = $empresa_plan;

    // Verificar estado de caja actual
    $sql_caja_actual = "SELECT * FROM caja WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierta'";
    $stmt_caja = $conn->prepare($sql_caja_actual);
    $stmt_caja->execute([$_SESSION['usuario_id'], $_SESSION['sucursal_id']]);
    $caja_actual = $stmt_caja->fetch(PDO::FETCH_ASSOC);

    // Variable para notificaciones (si existe el módulo)
    $notification_status = null;
    if (file_exists(__DIR__ . '/../EmidaServicios/config.php')) {
        require_once __DIR__ . '/../EmidaServicios/config.php';
        if (function_exists('getNotificationStatus')) {
            $notification_status = getNotificationStatus($conn_main);
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
    <title>Inicio - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Tema unificado LibertyFin (estilo landing) -->
    <link rel="stylesheet" href="css/crm-theme.css">
    <style>
       :root {
            --primary-color: <?php echo getConfigValue($empresa_info, 'color_primario', '#27ae60'); ?>;
            --secondary-color: <?php echo getConfigValue($empresa_info, 'color_secundario', '#2ecc71'); ?>;
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
                <?php if ($logo_src_base64): ?>
                    <!-- Mostrar logo en base64 -->
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
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
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php else: ?>
                    <!-- Mostrar icono por defecto -->
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
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
                            <a class="nav-link active" href="Inicio">
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
                            <a class="nav-link" href="CortesCaja">
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
                        <?php if ($empresa_plan !== 'basico'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Sucursales">
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
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Welcome Card -->
                <div class="card welcome-card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center mb-3">
                                    <?php if ($logo_src_base64): ?>
                                        <!-- Mostrar logo en base64 -->
                                        <img src="<?php echo $logo_src_base64; ?>"
                                            alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                                            style="width: 120px; height: 120px; object-fit: contain; border-radius: 10px; margin-right: 15px;"
                                            class="me-3">
                                    <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                                        <!-- Mostrar logo por ruta de archivo (fallback) -->
                                        <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                                            alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                                            style="width: 120px; height: 120px; object-fit: contain; border-radius: 10px; margin-right: 15px;"
                                            class="me-3"
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="logo-placeholder" style="display: none;">
                                            <i class="fas fa-store"></i>
                                        </div>
                                    <?php else: ?>
                                        <!-- Mostrar icono por defecto -->
                                        <div class="logo-placeholder me-3">
                                            <i class="fas fa-store"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <h4 class="card-title mb-2">
                                            <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                                            <span class="badge bg-<?php
                                                                    echo match ($empresa_plan) {
                                                                        'premium' => 'primary',
                                                                        'emprendedor' => 'success',
                                                                        'basico' => 'warning',
                                                                        'prueba' => 'info',
                                                                        default => 'secondary'
                                                                    };
                                                                    ?> ms-2" style="font-size: 0.7rem;">
                                                Plan <?php echo ucfirst($empresa_plan); ?>
                                            </span>
                                        </h4>
                                        <p class="card-text mb-0 opacity-75">
                                            Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                                        </p>
                                        <small class="opacity-75">
                                            <i class="fas fa-user-tag me-1"></i>
                                            Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?>
                                            <?php if ($empresa_plan === 'basico'): ?>
                                                <span class="ms-2 text-warning">
                                                    <i class="fas fa-info-circle"></i> Plan Básico: Sucursales deshabilitadas
                                                </span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <i class="fas fa-cash-register fa-4x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-2 mb-3">
                        <a href="Productos" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Productos</div>
                                            <div class="metric-value text-primary"><?php echo $estadisticas['total_productos']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-boxes fa-2x text-primary opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="Clientes" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Clientes</div>
                                            <div class="metric-value text-success"><?php echo $estadisticas['total_clientes']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-users fa-2x text-success opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="Ventas" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Ventas Hoy</div>
                                            <div class="metric-value text-warning"><?php echo $estadisticas['ventas_hoy']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-shopping-cart fa-2x text-warning opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="Usuarios" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Usuarios</div>
                                            <div class="metric-value text-info"><?php echo $estadisticas['total_usuarios']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-user-check fa-2x text-info opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 mb-3">
                        <a href="Reportes" class="text-decoration-none">
                            <div class="card ingresos-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label text-white-50">Ingresos Hoy</div>
                                            <div class="metric-value text-white">$<?php echo number_format($estadisticas['ingresos_hoy'], 2); ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-money-bill-wave fa-2x text-white opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Estado de Caja -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-cash-register me-2"></i>Estado de Caja
                                </h5>
                                <?php if ($caja_actual): ?>
                                    <span class="badge bg-success">Caja Abierta</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Caja Cerrada</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <?php if ($caja_actual): ?>
                                            <p class="mb-2">Caja abierta desde: <strong><?php echo date('H:i', strtotime($caja_actual['fecha_apertura'])); ?></strong></p>
                                            <p class="mb-2">Monto inicial: <strong>$<?php echo number_format($caja_actual['monto_apertura'], 2); ?></strong></p>
                                            <p class="mb-0">Estado: <span class="badge bg-success">Abierta</span></p>
                                        <?php else: ?>
                                            <p class="mb-3">No hay una caja abierta. Debe abrir caja para poder realizar ventas.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <?php if ($caja_actual): ?>
                                            <a href="caja_cierre.php" class="btn btn-warning me-2">
                                                <i class="fas fa-lock me-1"></i>Cerrar Caja
                                            </a>
                                        <?php else: ?>
                                            <a href="caja_apertura.php" class="btn btn-primary me-2">
                                                <i class="fas fa-lock-open me-1"></i>Abrir Caja
                                            </a>
                                        <?php endif; ?>
                                        <a href="CortesCaja" class="btn btn-outline-secondary">
                                            <i class="fas fa-history me-1"></i>Historial
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Acciones Rápidas -->
                <div class="row">
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
                                        <a href="Caja" class="btn btn-primary w-100 text-start p-3">
                                            <i class="fas fa-cash-register fa-2x mb-2"></i>
                                            <h6>Nueva Venta</h6>
                                            <small class="text-white-50">Iniciar punto de venta</small>
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="Productos" class="btn btn-success w-100 text-start p-3">
                                            <i class="fas fa-boxes fa-2x mb-2"></i>
                                            <h6>Gestionar Productos</h6>
                                            <small class="text-white-50">Agregar o editar productos</small>
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="Clientes" class="btn btn-info w-100 text-start p-3">
                                            <i class="fas fa-users fa-2x mb-2"></i>
                                            <h6>Clientes</h6>
                                            <small class="text-white-50">Administrar clientes</small>
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="Reportes" class="btn btn-warning w-100 text-start p-3">
                                            <i class="fas fa-chart-bar fa-2x mb-2"></i>
                                            <h6>Reportes</h6>
                                            <small class="text-white-50">Ver estadísticas</small>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Información de la Empresa
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Empresa:</small>
                                    <p class="mb-1 fw-bold"><?php echo htmlspecialchars($empresa_info['nombre_empresa']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">RFC:</small>
                                    <p class="mb-1"><?php echo htmlspecialchars($empresa_info['rfc']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Teléfono:</small>
                                    <p class="mb-1"><?php echo htmlspecialchars($empresa_info['telefono']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Email:</small>
                                    <p class="mb-1"><?php echo htmlspecialchars($empresa_info['email']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Plan Actual:</small>
                                    <p class="mb-1">
                                        <span class="badge bg-<?php
                                                                echo match ($empresa_plan) {
                                                                    'premium' => 'primary',
                                                                    'emprendedor' => 'success',
                                                                    'basico' => 'warning',
                                                                    'prueba' => 'info',
                                                                    default => 'secondary'
                                                                };
                                                                ?>">
                                            <?php echo ucfirst($empresa_plan); ?>
                                        </span>
                                        <?php if ($empresa_plan === 'basico'): ?>
                                            <small class="text-warning d-block mt-1">
                                                <i class="fas fa-info-circle"></i> Las sucursales están deshabilitadas en este plan
                                            </small>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
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
        let isTouchActive = false;
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

        // Detectar inicio del touch
        document.addEventListener('touchstart', function(e) {
            // Solo en dispositivos móviles
            if (window.innerWidth >= 768) return;

            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            touchEndX = touchStartX;
            touchEndY = touchStartY;
            isTouchActive = true;
        });

        // Detectar movimiento del touch
        document.addEventListener('touchmove', function(e) {
            if (!isTouchActive) return;

            touchEndX = e.touches[0].clientX;
            touchEndY = e.touches[0].clientY;

            // Calcular diferencia
            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;

            // Solo prevenir el scroll si es un movimiento horizontal significativo
            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                e.preventDefault();
            }
        }, {
            passive: false
        });

        // Detectar fin del touch
        document.addEventListener('touchend', function(e) {
            if (!isTouchActive) return;

            isTouchActive = false;

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

            // Resetear valores
            touchStartX = 0;
            touchStartY = 0;
            touchEndX = 0;
            touchEndY = 0;
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
            const statCards = document.querySelectorAll('.stat-card, .ingresos-card');

            statCards.forEach(card => {
                // Para dispositivos táctiles
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                    this.style.transition = 'transform 0.1s ease';
                });

                card.addEventListener('touchend', function() {
                    this.style.transform = '';
                });

                card.addEventListener('touchcancel', function() {
                    this.style.transform = '';
                });

                // Para mouse (efecto hover)
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            });

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
                if (touchX <= SWIPE_EDGE_ZONE) {
                    swipeZoneIndicator.style.opacity = '0.5';
                }
            });

            document.addEventListener('touchend', function() {
                swipeZoneIndicator.style.opacity = '0';
            });

            // Mejorar la experiencia táctil
            let startX = 0;
            let currentX = 0;

            sidebar.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
            }, {
                passive: true
            });

            sidebar.addEventListener('touchmove', (e) => {
                currentX = e.touches[0].clientX;
                const diff = startX - currentX;

                if (diff > 50) { // Deslizar hacia la izquierda para cerrar
                    closeSidebarAuto();
                }
            }, {
                passive: true
            });
        });
    </script>
</body>

</html>
