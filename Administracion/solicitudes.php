<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

// solicitudes.php (TABLA FORMULARIO CON PAGINACIÓN DE 10)
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
$solicitudes = [];

// Filtros
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$filtro_tipo_negocio = isset($_GET['tipo_negocio']) ? $_GET['tipo_negocio'] : '';
$filtro_fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$filtro_fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

// Paginación
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 10;

// Inicializar variables de estadísticas
$total_registros = 0;
$total_paginas = 0;
$tipos_negocio = [];

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

try {
    // Construir consulta base con filtros
    $sql_where = "WHERE 1=1";
    $params = [];
    $types = "";

    // Filtro por búsqueda
    if (!empty($filtro_busqueda)) {
        $sql_where .= " AND (nombre LIKE ? OR email LIKE ? OR rfc LIKE ? OR telefono LIKE ? OR tipo_negocio LIKE ? OR comentario LIKE ?)";
        $busqueda_param = "%" . $filtro_busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $types .= "ssssss";
    }

    // Filtro por tipo de negocio
    if (!empty($filtro_tipo_negocio) && $filtro_tipo_negocio !== 'todos') {
        $sql_where .= " AND tipo_negocio = ?";
        $params[] = $filtro_tipo_negocio;
        $types .= "s";
    }

    // Filtro por fecha
    if (!empty($filtro_fecha_desde)) {
        $sql_where .= " AND DATE(fecha_creacion) >= ?";
        $params[] = $filtro_fecha_desde;
        $types .= "s";
    }

    if (!empty($filtro_fecha_hasta)) {
        $sql_where .= " AND DATE(fecha_creacion) <= ?";
        $params[] = $filtro_fecha_hasta;
        $types .= "s";
    }

    // Contar total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM formulario $sql_where";
    $stmt_count = $conn->prepare($sql_count);

    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }

    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $row_count = $result_count->fetch_assoc();
    $total_registros = $row_count ? $row_count['total'] : 0;
    $stmt_count->close();

    // Calcular paginación
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina_actual - 1) * $registros_por_pagina;

    // Obtener tipos de negocio únicos para el filtro
    $tipos_negocio = [];
    $sql_tipos = "SELECT DISTINCT tipo_negocio FROM formulario WHERE tipo_negocio IS NOT NULL AND tipo_negocio != '' ORDER BY tipo_negocio";
    $result_tipos = $conn->query($sql_tipos);
    if ($result_tipos && $result_tipos->num_rows > 0) {
        while ($row = $result_tipos->fetch_assoc()) {
            $tipos_negocio[] = $row['tipo_negocio'];
        }
    }

    // Consulta principal con paginación (solo si hay registros)
    if ($total_registros > 0) {
        $sql = "SELECT 
                    id,
                    nombre,
                    rfc,
                    telefono,
                    email,
                    tipo_negocio,
                    comentario,
                    fecha_creacion
                FROM formulario
                $sql_where 
                ORDER BY fecha_creacion DESC 
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

        // Obtener todas las solicitudes
        while ($row = $result->fetch_assoc()) {
            $solicitudes[] = $row;
        }

        $stmt->close();
    }
} catch (Exception $e) {
    $mensaje = "Error al cargar las solicitudes: " . $e->getMessage();
    $tipo_mensaje = "danger";
    $total_registros = 0;
    $total_paginas = 0;
}

$conn->close();

// Función para formatear fecha
function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00' || $fecha == '0000-00-00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

// Función para truncar texto largo
function truncarTexto($texto, $longitud = 50)
{
    if (empty($texto)) {
        return '';
    }
    if (strlen($texto) <= $longitud) {
        return $texto;
    }
    return substr($texto, 0, $longitud) . '...';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes de Contacto - Panel de Administración</title>
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

        /* Estilos específicos para solicitudes */
        .comentario-corto {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .badge-tipo {
            background-color: #6f42c1;
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
            
            /* Ajustes para tabla en móvil */
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .table th, .table td {
                padding: 0.5rem;
            }
            
            .comentario-corto {
                max-width: 150px;
            }
        }

        /* Estilos para tablets */
        @media (max-width: 991.98px) {
            .table-responsive {
                overflow-x: auto;
            }
        }

        /* Mejoras para estadísticas en móvil */
        @media (max-width: 575.98px) {
            .col-md-3 {
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
                            <a class="nav-link active" href="solicitudes.php">
                                <i class="fas fa-inbox"></i>
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
                <!-- Mensajes -->
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
                                    <h4 class="card-title mb-1">Solicitudes de Contacto</h4>
                                    <div class="d-flex align-items-center">
                                        <p class="card-text mb-0 me-3 opacity-75">
                                            <i class="fas fa-inbox me-1"></i>
                                            Total de solicitudes: <strong><?php echo $total_registros; ?></strong>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Sección derecha con información adicional -->
                            <div class="text-end">
                                <div class="d-flex align-items-center">
                                    <div class="me-3 text-start">
                                        <small class="text-white-50 d-block">Mostrando</small>
                                        <span class="h5 mb-0 text-white"><?php echo count($solicitudes); ?> de <?php echo $total_registros; ?></span>
                                    </div>
                                    <div>
                                        <i class="fas fa-inbox fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros y Búsqueda -->
                <div class="filtros-card mb-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Tipo de Negocio:</label>
                            <select name="tipo_negocio" class="form-select">
                                <option value="todos" <?php echo $filtro_tipo_negocio === 'todos' ? 'selected' : ''; ?>>Todos los tipos</option>
                                <?php foreach ($tipos_negocio as $tipo): ?>
                                    <option value="<?php echo htmlspecialchars($tipo); ?>" 
                                        <?php echo $filtro_tipo_negocio === $tipo ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tipo); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fecha Desde:</label>
                            <input type="date" name="fecha_desde" class="form-control"
                                value="<?php echo htmlspecialchars($filtro_fecha_desde); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fecha Hasta:</label>
                            <input type="date" name="fecha_hasta" class="form-control"
                                value="<?php echo htmlspecialchars($filtro_fecha_hasta); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Buscar:</label>
                            <input type="text" name="busqueda" class="form-control"
                                placeholder="Nombre, email, teléfono..."
                                value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                        </div>
                        <div class="col-md-12">
                            <div class="d-flex gap-2 justify-content-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search me-1"></i>Filtrar
                                </button>
                                <a href="solicitudes.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-1"></i>Limpiar
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Tabla de Solicitudes -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list-ul me-2"></i>Solicitudes de Contacto
                        </h5>
                        <span class="badge bg-primary">
                            Mostrando <?php echo count($solicitudes); ?> de <?php echo $total_registros; ?> registros
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Teléfono</th>
                                        <th>Tipo de Negocio</th>
                                        <th>Comentario</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($solicitudes)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-inbox fa-3x d-block mb-3"></i>
                                                    No se encontraron solicitudes
                                                    <?php if (!empty($filtro_busqueda) || !empty($filtro_tipo_negocio)): ?>
                                                        <br>
                                                        <a href="solicitudes.php" class="btn btn-outline-primary mt-3">
                                                            <i class="fas fa-times me-1"></i>Limpiar filtros
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($solicitudes as $solicitud): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($solicitud['id']); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($solicitud['nombre']); ?></strong>
                                                    <?php if (!empty($solicitud['rfc'])): ?>
                                                        <br>
                                                        <small class="text-muted">RFC: <?php echo htmlspecialchars($solicitud['rfc']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="mailto:<?php echo htmlspecialchars($solicitud['email']); ?>">
                                                        <?php echo htmlspecialchars($solicitud['email']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo htmlspecialchars($solicitud['telefono'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="badge badge-tipo badge-estado">
                                                        <?php echo htmlspecialchars($solicitud['tipo_negocio']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="comentario-corto" title="<?php echo htmlspecialchars($solicitud['comentario']); ?>">
                                                        <?php echo htmlspecialchars(truncarTexto($solicitud['comentario'], 60)); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small><?php echo formatearFecha($solicitud['fecha_creacion']); ?></small>
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
                                        <!-- Primera página -->
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=1&busqueda=<?php echo urlencode($filtro_busqueda); ?>&tipo_negocio=<?php echo urlencode($filtro_tipo_negocio); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Anterior -->
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&tipo_negocio=<?php echo urlencode($filtro_tipo_negocio); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>

                                        <!-- Páginas cercanas -->
                                        <?php 
                                        $inicio = max(1, $pagina_actual - 2);
                                        $fin = min($total_paginas, $pagina_actual + 2);
                                        
                                        for ($i = $inicio; $i <= $fin; $i++): ?>
                                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                <a class="page-link" href="?pagina=<?php echo $i; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&tipo_negocio=<?php echo urlencode($filtro_tipo_negocio); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <!-- Siguiente -->
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&tipo_negocio=<?php echo urlencode($filtro_tipo_negocio); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Última página -->
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $total_paginas; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&tipo_negocio=<?php echo urlencode($filtro_tipo_negocio); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                    
                                    <!-- Información de paginación -->
                                    <div class="text-center mt-2">
                                        <small class="text-muted">
                                            Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?> 
                                            | <?php echo $registros_por_pagina; ?> registros por página
                                        </small>
                                    </div>
                                </nav>
                            </div>
                        <?php else: ?>
                            <!-- Mostrar información si solo hay una página -->
                            <div class="card-footer text-center">
                                <small class="text-muted">
                                    Mostrando <?php echo $total_registros; ?> registros
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Información del total de registros -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Resumen de Solicitudes
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 col-sm-6 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-primary"><?php echo $total_registros; ?></h3>
                                            <small class="text-muted">Total Solicitudes</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-6 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-success"><?php echo count($solicitudes); ?></h3>
                                            <small class="text-muted">Mostradas</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-6 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-info"><?php echo count($tipos_negocio); ?></h3>
                                            <small class="text-muted">Tipos de Negocio</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-6 text-center mb-3">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-1 text-warning"><?php echo $total_paginas; ?></h3>
                                            <small class="text-muted">Páginas</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Acciones Rápidas -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-bolt me-2"></i>Acciones Rápidas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <a href="dashboard.php" class="btn btn-primary w-100 text-start p-3">
                                            <i class="fas fa-tachometer-alt fa-2x mb-2"></i>
                                            <h6>Dashboard Principal</h6>
                                            <small class="text-white-50">Volver al inicio</small>
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="empresas.php" class="btn btn-success w-100 text-start p-3">
                                            <i class="fas fa-building fa-2x mb-2"></i>
                                            <h6>Gestión de Empresas</h6>
                                            <small class="text-white-50">Ver empresas registradas</small>
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="usuarios.php" class="btn btn-info w-100 text-start p-3">
                                            <i class="fas fa-user-cog fa-2x mb-2"></i>
                                            <h6>Usuarios Admin</h6>
                                            <small class="text-white-50">Administrar usuarios</small>
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
                    if (window.innerWidth < 768) {
                        closeSidebar();
                    }
                });
            });

            // Cerrar sidebar al redimensionar a pantalla grande
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebar();
                }
            });

            // Ajustar auto-selección de fechas
            const fechaDesdeInput = document.querySelector('input[name="fecha_desde"]');
            const fechaHastaInput = document.querySelector('input[name="fecha_hasta"]');

            if (fechaDesdeInput && !fechaDesdeInput.value) {
                const hoy = new Date();
                const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                fechaDesdeInput.value = primerDiaMes.toISOString().split('T')[0];
            }
            
            if (fechaHastaInput && !fechaHastaInput.value) {
                const hoy = new Date();
                fechaHastaInput.value = hoy.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>