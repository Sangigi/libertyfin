<?php

$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

// empresas.php (VERSIÓN CORREGIDA PARA MÓVIL)
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

// Filtros
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_estado_verificacion = isset($_GET['estado_verificacion']) ? $_GET['estado_verificacion'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$filtro_fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$filtro_fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

// Paginación
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 20;

// Obtener estadísticas
$estadisticas = [
    'total_empresas' => 0,
    'activas' => 0,
    'inactivas' => 0,
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

// Procesar activar/desactivar empresa
if (isset($_GET['accion']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $empresa_id = intval($_GET['id']);
    $accion = $_GET['accion'];
    
    if ($accion === 'activar' || $accion === 'desactivar') {
        $nuevo_estado = ($accion === 'activar') ? 1 : 0;
        
        $sql_update = "UPDATE empresas SET activo = ? WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ii", $nuevo_estado, $empresa_id);
        
        if ($stmt_update->execute()) {
            $mensaje = "Empresa " . ($accion === 'activar' ? 'activada' : 'desactivada') . " exitosamente";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al " . ($accion === 'activar' ? 'activar' : 'desactivar') . " la empresa";
            $tipo_mensaje = "danger";
        }
        $stmt_update->close();
    }
}

try {
    // Obtener estadísticas
    $sql_stats = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activas,
        SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivas,
        SUM(CASE WHEN estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobadas,
        SUM(CASE WHEN estado_verificacion = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
        SUM(CASE WHEN estado_verificacion = 'en_revision' THEN 1 ELSE 0 END) as en_revision,
        SUM(CASE WHEN estado_verificacion = 'rechazado' THEN 1 ELSE 0 END) as rechazadas
        FROM empresas";

    $result_stats = $conn->query($sql_stats);
    if ($result_stats) {
        $stats = $result_stats->fetch_assoc();
        $estadisticas['total_empresas'] = $stats['total'] ?? 0;
        $estadisticas['activas'] = $stats['activas'] ?? 0;
        $estadisticas['inactivas'] = $stats['inactivas'] ?? 0;
        $estadisticas['aprobadas'] = $stats['aprobadas'] ?? 0;
        $estadisticas['pendientes'] = $stats['pendientes'] ?? 0;
        $estadisticas['en_revision'] = $stats['en_revision'] ?? 0;
        $estadisticas['rechazadas'] = $stats['rechazadas'] ?? 0;
    }

    // Construir consulta base con filtros
    $sql_where = "WHERE 1=1";
    $params = [];
    $types = "";

    // Filtro por estado activo/inactivo
    if ($filtro_estado === 'activa') {
        $sql_where .= " AND e.activo = 1";
    } elseif ($filtro_estado === 'inactiva') {
        $sql_where .= " AND e.activo = 0";
    }

    // Filtro por estado de verificación
    if (!empty($filtro_estado_verificacion) && $filtro_estado_verificacion !== 'todos') {
        $sql_where .= " AND e.estado_verificacion = ?";
        $params[] = $filtro_estado_verificacion;
        $types .= "s";
    }

    // Filtro por búsqueda
    if (!empty($filtro_busqueda)) {
        $sql_where .= " AND (e.nombre_empresa LIKE ? OR e.email LIKE ? OR e.rfc LIKE ? OR e.telefono LIKE ? OR e.nombre_contacto LIKE ?)";
        $busqueda_param = "%" . $filtro_busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $types .= "sssss";
    }

    // Filtro por fecha
    if (!empty($filtro_fecha_desde)) {
        $sql_where .= " AND DATE(e.fecha_creacion) >= ?";
        $params[] = $filtro_fecha_desde;
        $types .= "s";
    }

    if (!empty($filtro_fecha_hasta)) {
        $sql_where .= " AND DATE(e.fecha_creacion) <= ?";
        $params[] = $filtro_fecha_hasta;
        $types .= "s";
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

// Función para obtener la clase del estado de verificación
function claseEstadoVerificacion($estado)
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

// Función para obtener el texto del estado de verificación
function textoEstadoVerificacion($estado)
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
    <title>Empresas - Panel de Administración</title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
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
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        /* SIDEBAR CORREGIDO */
        .sidebar {
            background: #2c3e50;
            color: white;
            height: calc(100vh - 56px);
            position: fixed;
            top: 56px;
            left: 0;
            width: 280px;
            z-index: 1030;
            transition: transform 0.3s ease-in-out;
            overflow-y: auto;
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

        /* CONTENIDO PRINCIPAL CORREGIDO */
        main {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s ease-in-out;
            width: calc(100% - 280px);
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
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

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }

        .filtros-card {
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .pagination .page-link {
            color: var(--primary-color);
        }

        .pagination .page-link:hover {
            color: var(--secondary-color);
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
            z-index: 1040;
        }

        /* Backdrop para móvil */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1025;
        }

        /* ESTILOS PARA MÓVIL */
        @media (max-width: 991.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 15px !important;
            }

            .sidebar-backdrop.show {
                display: block;
            }

            /* Ajustes específicos para tabla en móvil */
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .table th, .table td {
                padding: 0.5rem;
            }
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            
            /* Ajustes para filtros en móvil */
            .filtros-card .col-md-3,
            .filtros-card .col-md-4,
            .filtros-card .col-md-12 {
                margin-bottom: 10px;
            }
            
            /* Ajustes para estadísticas en móvil */
            .col-md-2 {
                flex: 0 0 33.333%;
                max-width: 33.333%;
                margin-bottom: 10px;
            }
        }

        /* ESTILOS PARA TABLETS PEQUEÑAS */
        @media (max-width: 767.98px) {
            .col-md-2 {
                flex: 0 0 50%;
                max-width: 50%;
            }
            
            .d-md-flex {
                flex-direction: column;
            }
            
            .d-md-flex .btn {
                margin-bottom: 10px;
                width: 100%;
            }
        }

        /* ESTILOS PARA MÓVILES MUY PEQUEÑOS */
        @media (max-width: 575.98px) {
            .col-md-2 {
                flex: 0 0 100%;
                max-width: 100%;
            }
            
            .table {
                display: block;
            }
            
            .table thead {
                display: none;
            }
            
            .table tbody tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 10px;
                background: white;
            }
            
            .table tbody td {
                display: block;
                text-align: right;
                padding: 5px 10px;
                position: relative;
                border: none;
            }
            
            .table tbody td::before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                font-weight: 600;
                color: #2c3e50;
            }
            
            .table tbody td .btn-group {
                justify-content: flex-end;
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

            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../images/LibertyfinBlanco.png" alt="Logo" class="me-2" style="height: 30px;">
                <span class="d-none d-md-inline">Panel de Administración</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin'); ?></span>
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
            <div class="col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="empresas.php">
                                <i class="fas fa-building"></i>
                                Empresas
                            </a>
                        </li>
                         <li class="nav-item">
                            <a class="nav-link" href="activaciones.php">
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
                        <!-- <li class="nav-item">
                            <a class="nav-link" href="#">
                                <i class="fas fa-cogs"></i>
                                Configuración
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li> -->
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-lg-10 px-md-4 py-4">
                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Título y botón nueva empresa -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
                    <div class="mb-3 mb-md-0">
                        <h1 class="h4 mb-1">
                            <i class="fas fa-building me-2"></i>Gestión de Empresas
                        </h1>
                        <p class="text-muted mb-0">
                            <span class="d-inline-block me-2">
                                <i class="fas fa-layer-group me-1"></i>Total: <?php echo $estadisticas['total_empresas']; ?>
                            </span>
                            <span class="d-inline-block me-2">
                                <i class="fas fa-check-circle text-success me-1"></i>Activas: <?php echo $estadisticas['activas']; ?>
                            </span>
                            <span class="d-inline-block">
                                <i class="fas fa-times-circle text-danger me-1"></i>Inactivas: <?php echo $estadisticas['inactivas']; ?>
                            </span>
                        </p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="nueva_empresa.php" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-1"></i><span class="d-none d-md-inline">Nueva Empresa</span>
                        </a>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i><span class="d-none d-md-inline">Dashboard</span>
                        </a>
                    </div>
                </div>

                <!-- Filtros Avanzados -->
                <div class="filtros-card mb-4">
                    <h5 class="mb-3">
                        <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                    </h5>
                    <form method="GET" class="row g-3">
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Estado de la Empresa:</label>
                            <select name="estado" class="form-select">
                                <option value="">Todos</option>
                                <option value="activa" <?php echo $filtro_estado === 'activa' ? 'selected' : ''; ?>>Solo Activas</option>
                                <option value="inactiva" <?php echo $filtro_estado === 'inactiva' ? 'selected' : ''; ?>>Solo Inactivas</option>
                            </select>
                        </div>
                        
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Estado de Verificación:</label>
                            <select name="estado_verificacion" class="form-select">
                                <option value="todos">Todos los estados</option>
                                <option value="pendiente" <?php echo $filtro_estado_verificacion === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                <option value="en_revision" <?php echo $filtro_estado_verificacion === 'en_revision' ? 'selected' : ''; ?>>En Revisión</option>
                                <option value="aprobado" <?php echo $filtro_estado_verificacion === 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                <option value="rechazado" <?php echo $filtro_estado_verificacion === 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                            </select>
                        </div>
                        
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Fecha Desde:</label>
                            <input type="date" name="fecha_desde" class="form-control"
                                value="<?php echo htmlspecialchars($filtro_fecha_desde); ?>">
                        </div>
                        
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Fecha Hasta:</label>
                            <input type="date" name="fecha_hasta" class="form-control"
                                value="<?php echo htmlspecialchars($filtro_fecha_hasta); ?>">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Buscar:</label>
                            <input type="text" name="busqueda" class="form-control"
                                placeholder="Buscar por nombre, email, RFC, teléfono o contacto..."
                                value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                        </div>
                        
                        <div class="col-12">
                            <div class="d-flex flex-column flex-md-row justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i>Filtrar
                                </button>
                                <a href="empresas.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-1"></i>Limpiar Filtros
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Tabla de Empresas -->
                <div class="card">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                        <h5 class="mb-2 mb-md-0">
                            <i class="fas fa-list-ul me-2"></i>Lista de Empresas
                        </h5>
                        <span class="badge bg-primary"><?php echo $total_registros; ?> registros</span>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                      
                                        <th>Empresa</th>
                                        <th class="d-none d-md-table-cell">Contacto</th>
                                        <th class="d-none d-lg-table-cell">Email</th>
                                        <th>Estado</th>
                                        <th class="d-none d-sm-table-cell">Verificación</th>
                                        <th class="d-none d-md-table-cell">Registro</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($empresas)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-building fa-3x d-block mb-3"></i>
                                                    No se encontraron empresas con los filtros aplicados
                                                    <?php if (!empty($filtro_busqueda) || !empty($filtro_estado) || !empty($filtro_estado_verificacion)): ?>
                                                        <br>
                                                        <a href="empresas.php" class="btn btn-outline-primary mt-3">
                                                            <i class="fas fa-times me-1"></i>Limpiar filtros
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($empresas as $empresa): ?>
                                            <tr>
                                               
                                                <td data-label="Empresa">
                                                    <strong><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?>
                                                    </small>
                                                    <div class="d-md-none">
                                                        <small>
                                                            <i class="fas fa-user me-1"></i>
                                                            <?php echo htmlspecialchars($empresa['nombre_contacto']); ?>
                                                        </small>
                                                        <br>
                                                        <small>
                                                            <i class="fas fa-envelope me-1"></i>
                                                            <?php echo htmlspecialchars($empresa['email']); ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td data-label="Contacto" class="d-none d-md-table-cell">
                                                    <?php echo htmlspecialchars($empresa['nombre_contacto']); ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?></small>
                                                </td>
                                                <td data-label="Email" class="d-none d-lg-table-cell">
                                                    <a href="mailto:<?php echo htmlspecialchars($empresa['email']); ?>">
                                                        <?php echo htmlspecialchars($empresa['email']); ?>
                                                    </a>
                                                </td>
                                                <td data-label="Estado">
                                                    <?php if ($empresa['activo'] == 1): ?>
                                                        <span class="badge bg-success badge-estado">
                                                            <i class="fas fa-check-circle me-1"></i>Activa
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger badge-estado">
                                                            <i class="fas fa-times-circle me-1"></i>Inactiva
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Verificación" class="d-none d-sm-table-cell">
                                                    <span class="badge bg-<?php echo claseEstadoVerificacion($empresa['estado_verificacion']); ?> badge-estado">
                                                        <?php echo textoEstadoVerificacion($empresa['estado_verificacion']); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Registro" class="d-none d-md-table-cell">
                                                    <small><?php echo formatearFecha($empresa['fecha_creacion']); ?></small>
                                                </td>
                                                <td data-label="Acciones">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-sm btn-info"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalDetalle"
                                                            data-id="<?php echo $empresa['id']; ?>"
                                                            title="Ver detalles">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="gestionar_empresa.php?id=<?php echo $empresa['id']; ?>"
                                                            class="btn btn-sm btn-warning"
                                                            title="Editar">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($empresa['activo'] == 1): ?>
                                                            <a href="?accion=desactivar&id=<?php echo $empresa['id']; ?><?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>"
                                                                class="btn btn-sm btn-danger"
                                                                onclick="return confirm('¿Está seguro de desactivar esta empresa?')"
                                                                title="Desactivar">
                                                                <i class="fas fa-toggle-off"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="?accion=activar&id=<?php echo $empresa['id']; ?><?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>"
                                                                class="btn btn-sm btn-success"
                                                                onclick="return confirm('¿Está seguro de activar esta empresa?')"
                                                                title="Activar">
                                                                <i class="fas fa-toggle-on"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación -->
                        <?php if ($total_paginas > 1): ?>
                            <div class="card-footer">
                                <nav aria-label="Paginación">
                                    <ul class="pagination justify-content-center mb-0">
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link"
                                                href="?pagina=<?php echo $pagina_actual - 1; ?>&estado=<?php echo $filtro_estado; ?>&estado_verificacion=<?php echo $filtro_estado_verificacion; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>

                                        <?php 
                                        // Mostrar solo algunas páginas alrededor de la actual
                                        $inicio = max(1, $pagina_actual - 2);
                                        $fin = min($total_paginas, $pagina_actual + 2);
                                        
                                        for ($i = $inicio; $i <= $fin; $i++): ?>
                                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                <a class="page-link"
                                                    href="?pagina=<?php echo $i; ?>&estado=<?php echo $filtro_estado; ?>&estado_verificacion=<?php echo $filtro_estado_verificacion; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link"
                                                href="?pagina=<?php echo $pagina_actual + 1; ?>&estado=<?php echo $filtro_estado; ?>&estado_verificacion=<?php echo $filtro_estado_verificacion; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Resumen de Estadísticas -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Resumen de Empresas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 col-md-2 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-primary"><?php echo $estadisticas['total_empresas']; ?></h3>
                                            <small class="text-muted">Total Empresas</small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-success"><?php echo $estadisticas['activas']; ?></h3>
                                            <small class="text-muted">Activas</small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-danger"><?php echo $estadisticas['inactivas']; ?></h3>
                                            <small class="text-muted">Inactivas</small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-success"><?php echo $estadisticas['aprobadas']; ?></h3>
                                            <small class="text-muted">Aprobadas</small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-warning"><?php echo $estadisticas['pendientes']; ?></h3>
                                            <small class="text-muted">Pendientes</small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-danger"><?php echo $estadisticas['rechazadas']; ?></h3>
                                            <small class="text-muted">Rechazadas</small>
                                        </div>
                                    </div>
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
            }

            function closeSidebar() {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                document.body.style.overflow = '';
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebar);
            }

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 992) {
                        closeSidebar();
                    }
                });
            });

            // Cerrar sidebar al redimensionar a pantalla grande
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 992) {
                    closeSidebar();
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

            // Auto-seleccionar el último mes en el filtro de fechas
            const hoy = new Date();
            const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
            const fechaDesdeInput = document.querySelector('input[name="fecha_desde"]');
            const fechaHastaInput = document.querySelector('input[name="fecha_hasta"]');

            if (fechaDesdeInput && !fechaDesdeInput.value) {
                fechaDesdeInput.value = primerDiaMes.toISOString().split('T')[0];
            }
            if (fechaHastaInput && !fechaHastaInput.value) {
                fechaHastaInput.value = hoy.toISOString().split('T')[0];
            }

            // Hacer la tabla responsiva en móviles
            function makeTableResponsive() {
                if (window.innerWidth < 576) {
                    const tableCells = document.querySelectorAll('tbody td');
                    const headers = ['ID', 'Empresa', 'Contacto', 'Email', 'Estado', 'Verificación', 'Registro', 'Acciones'];
                    
                    tableCells.forEach((cell, index) => {
                        const headerIndex = index % headers.length;
                        cell.setAttribute('data-label', headers[headerIndex]);
                    });
                }
            }

            makeTableResponsive();
            window.addEventListener('resize', makeTableResponsive);
        });
    </script>
</body>
</html>