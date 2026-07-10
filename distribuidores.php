<?php
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
$distribuidores = [];
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 10;

// Obtener estadísticas
$estadisticas = [
    'total_distribuidores' => 0,
    'aprobados' => 0,
    'pendientes' => 0,
    'en_revision' => 0,
    'rechazados' => 0
];

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Procesar acciones POST (activar/desactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion']) && isset($_POST['id'])) {
        $id = intval($_POST['id']);

        if ($_POST['accion'] === 'desactivar') {
            $sql = "UPDATE distribuidores SET activo = 0 WHERE id = ?";
            $mensaje = "Distribuidor desactivado correctamente";
            $tipo_mensaje = "success";
        } elseif ($_POST['accion'] === 'activar') {
            $sql = "UPDATE distribuidores SET activo = 1 WHERE id = ?";
            $mensaje = "Distribuidor activado correctamente";
            $tipo_mensaje = "success";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            // Éxito
        } else {
            $mensaje = "Error al procesar la acción";
            $tipo_mensaje = "danger";
        }
        $stmt->close();
    }
}

try {
    // Obtener estadísticas
    $sql_stats = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobados,
        SUM(CASE WHEN estado_verificacion = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
        SUM(CASE WHEN estado_verificacion = 'en_revision' THEN 1 ELSE 0 END) as en_revision,
        SUM(CASE WHEN estado_verificacion = 'rechazado' THEN 1 ELSE 0 END) as rechazados
        FROM distribuidores";

    $result_stats = $conn->query($sql_stats);
    if ($result_stats) {
        $stats = $result_stats->fetch_assoc();
        $estadisticas['total_distribuidores'] = $stats['total'] ?? 0;
        $estadisticas['aprobados'] = $stats['aprobados'] ?? 0;
        $estadisticas['pendientes'] = $stats['pendientes'] ?? 0;
        $estadisticas['en_revision'] = $stats['en_revision'] ?? 0;
        $estadisticas['rechazados'] = $stats['rechazados'] ?? 0;
    }

    // Construir consulta base con filtros
    $sql_where = "WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($filtro_estado) && $filtro_estado !== 'todos') {
        $sql_where .= " AND estado_verificacion = ?";
        $params[] = $filtro_estado;
        $types .= "s";
    }

    if (!empty($filtro_busqueda)) {
        $sql_where .= " AND (nombre_distribuidor LIKE ? OR email LIKE ? OR rfc LIKE ? OR telefono LIKE ? OR numero_control LIKE ?)";
        $busqueda_param = "%" . $filtro_busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $types .= "sssss";
    }

    // Contar total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM distribuidores $sql_where";
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
                id,
                numero_control,
                nombre_distribuidor,
                telefono,
                email,
                rfc,
                banco,
                numero_cuenta,
                constancia_fiscal,
                credencial_identificacion,
                fecha_subida_constancia,
                fecha_subida_credencial,
                declaracion_veracidad,
                estado_verificacion,
                observaciones_verificacion,
                fecha_verificacion,
                correo_enviado,
                fecha_envio_correo,
                fecha_registro,
                fecha_actualizacion,
                activo
            FROM distribuidores
            $sql_where 
            ORDER BY fecha_registro DESC 
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

    // Obtener todos los distribuidores
    while ($row = $result->fetch_assoc()) {
        $distribuidores[] = $row;
    }

    $stmt->close();
} catch (Exception $e) {
    $mensaje = "Error al cargar los distribuidores: " . $e->getMessage();
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
    <title>Distribuidores - Panel de Administración</title>
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

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
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
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
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

        @media (max-width: 767.98px) {
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

        .badge-activo {
            background-color: #d4edda;
            color: #155724;
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
        }

        .badge-inactivo {
            background-color: #f8d7da;
            color: #721c24;
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
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
                    <ul class="dropdown-menu dropdown-menu-end">
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
                            <a class="nav-link active" href="distribuidores.php">
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
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
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
                                    <h4 class="card-title mb-1">Gestión de Distribuidores</h4>
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
                            <div class="text-end">
                                <div class="d-flex align-items-center">
                                    <div class="me-3 text-start">
                                        <small class="text-white-50 d-block">Distribuidores totales</small>
                                        <span class="h5 mb-0 text-white"><?php echo $estadisticas['total_distribuidores']; ?></span>
                                    </div>
                                    <div>
                                        <i class="fas fa-users fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tarjetas de Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-2 col-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total</h6>
                                        <h3 class="mb-0"><?php echo $estadisticas['total_distribuidores']; ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-users text-primary"></i>
                                    </div>
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
                                        <h3 class="mb-0 text-success"><?php echo $estadisticas['aprobados']; ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-check-circle text-success"></i>
                                    </div>
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
                                        <h3 class="mb-0 text-warning"><?php echo $estadisticas['pendientes']; ?></h3>
                                    </div>
                                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-clock text-warning"></i>
                                    </div>
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
                                        <h3 class="mb-0 text-info"><?php echo $estadisticas['en_revision']; ?></h3>
                                    </div>
                                    <div class="bg-info bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-search text-info"></i>
                                    </div>
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
                                        <h3 class="mb-0 text-danger"><?php echo $estadisticas['rechazados']; ?></h3>
                                    </div>
                                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-times-circle text-danger"></i>
                                    </div>
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
                                        <h3 class="mb-0"><?php echo $estadisticas['aprobados']; ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-user-check text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros y Búsqueda -->
                <div class="filtros-card">
                    <div class="row">
                        <div class="col-md-12">
                            <form method="GET" action="" class="row g-3">
                                <div class="col-md-4">
                                    <label for="busqueda" class="form-label">Buscar</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" class="form-control" id="busqueda" name="busqueda"
                                            placeholder="Nombre, email, RFC, teléfono..."
                                            value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label for="estado" class="form-label">Estado de verificación</label>
                                    <select class="form-select" id="estado" name="estado">
                                        <option value="todos" <?php echo $filtro_estado == 'todos' || $filtro_estado == '' ? 'selected' : ''; ?>>Todos los estados</option>
                                        <option value="pendiente" <?php echo $filtro_estado == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                        <option value="en_revision" <?php echo $filtro_estado == 'en_revision' ? 'selected' : ''; ?>>En Revisión</option>
                                        <option value="aprobado" <?php echo $filtro_estado == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                        <option value="rechazado" <?php echo $filtro_estado == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="activo" class="form-label">Estado</label>
                                    <select class="form-select" id="activo" name="activo">
                                        <option value="todos">Todos</option>
                                        <option value="activos">Activos</option>
                                        <option value="inactivos">Inactivos</option>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter me-2"></i>Filtrar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Mensajes de alerta -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Tabla de Distribuidores -->
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-users me-2 text-primary"></i>
                            Listado de Distribuidores
                        </h5>
                        <div>
                            <button class="btn btn-success btn-sm" onclick="window.location.href='distribuidor_nuevo.php'">
                                <i class="fas fa-plus me-1"></i> Nuevo Distribuidor
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="exportarExcel()">
                                <i class="fas fa-file-excel me-1"></i> Exportar
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th># Control</th>
                                        <th>Distribuidor</th>
                                        <th>Contacto</th>
                                        <th>RFC</th>
                                        <th>Banco/Cuenta</th>
                                        <th>Verificación</th>
                                        <th>Documentos</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($distribuidores)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-users fa-3x mb-3"></i>
                                                    <p>No hay distribuidores registrados</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($distribuidores as $dist): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($dist['numero_control']); ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($dist['nombre_distribuidor']); ?></div>
                                                </td>
                                                <td>
                                                    <div><i class="fas fa-phone-alt me-1 small"></i><?php echo htmlspecialchars($dist['telefono']); ?></div>
                                                    <div><i class="fas fa-envelope me-1 small"></i><?php echo htmlspecialchars($dist['email']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($dist['rfc']); ?></td>
                                                <td>
                                                    <div><small class="text-muted"><?php echo htmlspecialchars($dist['banco']); ?></small></div>
                                                    <small><?php echo htmlspecialchars($dist['numero_cuenta']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo claseEstado($dist['estado_verificacion']); ?>">
                                                        <?php echo textoEstado($dist['estado_verificacion']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <?php if (!empty($dist['constancia_fiscal'])): ?>
                                                            <button type="button" class="btn btn-outline-primary ver-archivo"
                                                                data-archivo="/Distribuidor/uploads/distribuidores/constancias/<?php echo htmlspecialchars($dist['constancia_fiscal']); ?>"
                                                                data-tipo="<?php echo strpos($dist['constancia_fiscal'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>"
                                                                data-nombre="<?php echo basename($dist['constancia_fiscal']); ?>"
                                                                data-titulo="Constancia Fiscal - <?php echo htmlspecialchars($dist['nombre_distribuidor']); ?>"
                                                                title="Ver Constancia Fiscal">
                                                                <i class="fas fa-file-pdf"></i>
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if (!empty($dist['credencial_identificacion'])): ?>
                                                            <button type="button" class="btn btn-outline-primary ver-archivo"
                                                                data-archivo="/Distribuidor/uploads/distribuidores/credenciales/<?php echo htmlspecialchars($dist['credencial_identificacion']); ?>"
                                                                data-tipo="<?php echo strpos($dist['credencial_identificacion'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>"
                                                                data-nombre="<?php echo basename($dist['credencial_identificacion']); ?>"
                                                                data-titulo="Credencial/Identificación - <?php echo htmlspecialchars($dist['nombre_distribuidor']); ?>"
                                                                title="Ver Identificación">
                                                                <i class="fas fa-id-card"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($dist['activo']): ?>
                                                        <span class="badge-activo">
                                                            <i class="fas fa-circle me-1" style="font-size: 8px;"></i>Activo
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge-inactivo">
                                                            <i class="fas fa-circle me-1" style="font-size: 8px;"></i>Inactivo
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-info accion-btn"
                                                            onclick="verDetalle(<?php echo $dist['id']; ?>)"
                                                            title="Ver detalles">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="distribuidor_editar.php?id=<?php echo $dist['id']; ?>"
                                                            class="btn btn-warning accion-btn" title="Editar">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($dist['activo']): ?>
                                                            <button type="button" class="btn btn-danger accion-btn"
                                                                onclick="confirmarAccion(<?php echo $dist['id']; ?>, 'desactivar')"
                                                                title="Desactivar">
                                                                <i class="fas fa-ban"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-success accion-btn"
                                                                onclick="confirmarAccion(<?php echo $dist['id']; ?>, 'activar')"
                                                                title="Activar">
                                                                <i class="fas fa-check-circle"></i>
                                                            </button>
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
                            <div class="card-footer bg-white">
                                <nav aria-label="Page navigation">
                                    <ul class="pagination justify-content-center mb-0">
                                        <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>&estado=<?php echo urlencode($filtro_estado); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>

                                        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                <a class="page-link" href="?pagina=<?php echo $i; ?>&estado=<?php echo urlencode($filtro_estado); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>&estado=<?php echo urlencode($filtro_estado); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Información de registros -->
                <div class="row mt-3">
                    <div class="col-md-12">
                        <small class="text-muted">
                            Mostrando <?php echo count($distribuidores); ?> de <?php echo $total_registros; ?> distribuidores
                        </small>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para ver archivos -->
    <div class="modal fade" id="modalArchivo" tabindex="-1" aria-labelledby="modalArchivoTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalArchivoTitulo">Visor de Archivos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="archivoCargando" class="d-flex align-items-center justify-content-center">
                        <div class="text-center">
                            <div class="spinner-border text-primary mb-3" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <p>Cargando archivo...</p>
                        </div>
                    </div>
                    <div id="visorImagen" class="d-none">
                        <img id="imagenVisor" src="" alt="Vista previa" class="img-fluid">
                    </div>
                    <div id="visorPDF" class="d-none">
                        <iframe id="pdfVisor" src="" frameborder="0"></iframe>
                    </div>
                    <div id="visorError" class="d-none">
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                            <h5>Error al cargar el archivo</h5>
                            <p class="text-muted">No se pudo cargar el archivo. Intente nuevamente.</p>
                            <a id="descargarArchivo" href="#" class="btn btn-primary" download>
                                <i class="fas fa-download me-2"></i>Descargar archivo
                            </a>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <small class="text-muted" id="infoArchivo"></small>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para confirmar acciones -->
    <div class="modal fade" id="modalConfirmar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Acción</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="mensajeConfirmacion">¿Está seguro de realizar esta acción?</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="formConfirmar">
                        <input type="hidden" name="id" id="confirmarId">
                        <input type="hidden" name="accion" id="confirmarAccion">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnConfirmar">Confirmar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para ver detalles -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalles del Distribuidor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleDistribuidor">
                    <!-- Contenido cargado vía AJAX -->
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
        // Variables para el swipe
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

        // Funcionalidad del sidebar
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

            // Función para exportar a Excel
            window.exportarExcel = function() {
                window.location.href = 'exportar_distribuidores.php?' + new URLSearchParams({
                    estado: document.getElementById('estado')?.value || '',
                    busqueda: document.getElementById('busqueda')?.value || '',
                    activo: document.getElementById('activo')?.value || ''
                }).toString();
            };

            // Función para ver detalles
            window.verDetalle = function(id) {
                const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));
                const detalleDiv = document.getElementById('detalleDistribuidor');

                detalleDiv.innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2">Cargando información...</p>
                    </div>
                `;

                modal.show();

                $.ajax({
                    url: 'ajax_detalle_distribuidor.php',
                    method: 'GET',
                    data: {
                        id: id
                    },
                    success: function(response) {
                        detalleDiv.innerHTML = response;
                    },
                    error: function() {
                        detalleDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <h6>Error al cargar los detalles</h6>
                                <p class="mb-0">No se pudieron cargar los detalles del distribuidor. Intente nuevamente.</p>
                            </div>
                        `;
                    }
                });
            };

            // Función para confirmar acciones
            window.confirmarAccion = function(id, accion) {
                document.getElementById('confirmarId').value = id;
                document.getElementById('confirmarAccion').value = accion;

                const mensaje = accion === 'desactivar' ?
                    '¿Está seguro de desactivar este distribuidor?' :
                    '¿Está seguro de activar este distribuidor?';
                document.getElementById('mensajeConfirmacion').textContent = mensaje;

                const btnConfirmar = document.getElementById('btnConfirmar');
                btnConfirmar.className = accion === 'desactivar' ? 'btn btn-danger' : 'btn btn-success';
                btnConfirmar.textContent = accion === 'desactivar' ? 'Desactivar' : 'Activar';

                const modal = new bootstrap.Modal(document.getElementById('modalConfirmar'));
                modal.show();
            };
        });

        // Función para abrir archivos en el modal
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

            let urlCompleta = rutaArchivo;
            if (!rutaArchivo.startsWith('http')) {
                urlCompleta = rutaArchivo.startsWith('/') ? rutaArchivo : '/' + rutaArchivo;
            }

            modalTitulo.textContent = titulo;
            descargarBtn.href = urlCompleta;
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
                        imagenVisor.src = urlCompleta;
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
                    img.src = urlCompleta;

                } else if (tipoArchivo === 'pdf') {
                    pdfVisor.src = urlCompleta + '#view=fitH';
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

        // Delegación de eventos para botones de archivos
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

        // Limpiar modal de archivos al cerrar
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

        // Limpiar modal de detalles al cerrar
        $('#modalDetalle').on('hidden.bs.modal', function() {
            $(this).find('#detalleDistribuidor').html('');
        });
    </script>
</body>

</html>