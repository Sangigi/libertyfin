<?php
// =============================================
// DASHBOARD.PHP - Panel de Administración
// =============================================

// Cargar configuración de sesión personalizada
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

// Variables para el navbar y sidebar
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Administrador';
$usuario_rol = $_SESSION['usuario_rol'] ?? 'admin';

// Cargar configuración de base de datos
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../env_loader.php';

// Variables para mensajes y filtros
$mensaje = '';
$tipo_mensaje = '';
$empresas = [];
$filtro_plan = isset($_GET['plan']) ? $_GET['plan'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 10;

// Obtener estadísticas
$estadisticas = [
    'total_empresas' => 0,
    'aprobadas' => 0,
    'desactivadas' => 0,
    'plan_prueba' => 0,
    'plan_basico' => 0,
    'plan_starter' => 0,
    'plan_emprendedor' => 0,
    'plan_premium' => 0
];

// Crear conexión usando PDO
try {
    $pdo = getDBConnection();
    
    // Obtener estadísticas generales
    $sql_stats = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobadas,
        SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as desactivadas,
        SUM(CASE WHEN plan = 'prueba' THEN 1 ELSE 0 END) as plan_prueba,
        SUM(CASE WHEN plan = 'basico' THEN 1 ELSE 0 END) as plan_basico,
        SUM(CASE WHEN plan = 'starter' THEN 1 ELSE 0 END) as plan_starter,
        SUM(CASE WHEN plan = 'emprendedor' THEN 1 ELSE 0 END) as plan_emprendedor,
        SUM(CASE WHEN plan = 'premium' THEN 1 ELSE 0 END) as plan_premium
        FROM empresas";

    $stmt = $pdo->query($sql_stats);
    $stats = $stmt->fetch();
    
    if ($stats) {
        $estadisticas['total_empresas'] = $stats['total'] ?? 0;
        $estadisticas['aprobadas'] = $stats['aprobadas'] ?? 0;
        $estadisticas['desactivadas'] = $stats['desactivadas'] ?? 0;
        $estadisticas['plan_prueba'] = $stats['plan_prueba'] ?? 0;
        $estadisticas['plan_basico'] = $stats['plan_basico'] ?? 0;
        $estadisticas['plan_starter'] = $stats['plan_starter'] ?? 0;
        $estadisticas['plan_emprendedor'] = $stats['plan_emprendedor'] ?? 0;
        $estadisticas['plan_premium'] = $stats['plan_premium'] ?? 0;
    }

    // Construir consulta base con filtros
    $sql_where = "WHERE 1=1";
    $params = [];

    if (!empty($filtro_plan) && $filtro_plan !== 'todos') {
        $sql_where .= " AND e.plan = ?";
        $params[] = $filtro_plan;
    }

    if (!empty($filtro_busqueda)) {
        $sql_where .= " AND (e.nombre_empresa LIKE ? OR e.email LIKE ? OR e.rfc LIKE ? OR e.telefono LIKE ? OR e.plan LIKE ?)";
        $busqueda_param = "%" . $filtro_busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
    }

    // Contar total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM empresas e $sql_where";
    $stmt = $pdo->prepare($sql_count);
    $stmt->execute($params);
    $total_registros = $stmt->fetchColumn();

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
                e.activo,
                e.plan
            FROM empresas e
            LEFT JOIN giro_comercial g ON e.giro_comercial = g.id
            $sql_where 
            ORDER BY e.fecha_creacion DESC 
            LIMIT ? OFFSET ?";

    $params[] = $registros_por_pagina;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $empresas = $stmt->fetchAll();

} catch (PDOException $e) {
    $mensaje = "Error al cargar las empresas: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Función para formatear fecha
function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

// Función para obtener el nombre del plan
function obtenerNombrePlan($plan)
{
    $planes = [
        'prueba' => 'Prueba',
        'basico' => 'Básico',
        'starter' => 'Profesional',
        'emprendedor' => 'Empresarial',
        'premium' => 'Empresarial Plus'
    ];
    return $planes[$plan] ?? $plan;
}

// Función para obtener la clase del plan
function clasePlan($plan)
{
    switch ($plan) {
        case 'prueba':
            return 'secondary';
        case 'basico':
            return 'info';
        case 'starter':
            return 'primary';
        case 'emprendedor':
            return 'warning';
        case 'premium':
            return 'success';
        default:
            return 'secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Panel de Administración</title>
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS de componentes compartidos -->
    <link rel="stylesheet" href="assets/css/navbar.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    
    <!-- CSS específico del dashboard -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    
    <!-- jQuery (necesario para AJAX) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
</head>

<body>

    <!-- ========================================== -->
    <!-- NAVBAR COMPONENTE -->
    <!-- ========================================== -->
    <?php include 'assets/components/navbar.php'; ?>

    <!-- ========================================== -->
    <!-- SIDEBAR COMPONENTE -->
    <!-- ========================================== -->
    <?php include 'assets/components/sidebar.php'; ?>

    <!-- ========================================== -->
    <!-- CONTENIDO PRINCIPAL -->
    <!-- ========================================== -->
    <main>
        <div class="container-fluid">
            <!-- Welcome Card -->
            <div class="card welcome-card mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
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
                                <div class="d-flex align-items-center flex-wrap">
                                    <p class="card-text mb-0 me-3 opacity-75">
                                        <i class="fas fa-user me-1"></i>
                                        Bienvenido, <?php echo htmlspecialchars($usuario_nombre); ?>
                                    </p>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-user-tag me-1"></i><?php echo ucfirst($usuario_rol); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Sección derecha con información adicional -->
                        <div class="text-end mt-2 mt-sm-0">
                            <div class="d-flex align-items-center">
                                <div class="me-3 text-start">
                                    <small class="text-white-50 d-block">Empresas totales</small>
                                    <span class="h5 mb-0 text-white" id="totalEmpresasHeader"><?php echo $estadisticas['total_empresas']; ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-chart-line fa-2x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mensajes -->
            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?php echo $tipo_mensaje === 'danger' ? 'exclamation-circle' : 'check-circle'; ?> me-2"></i>
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Estadísticas -->
            <div class="row mb-4">
                <!-- Tarjeta: Total Empresas -->
                <div class="col-md-3 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Total Empresas</div>
                                    <div class="metric-value text-primary" id="statTotal"><?php echo $estadisticas['total_empresas']; ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-building fa-2x text-primary opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tarjeta: Empresas Activas (Aprobadas) -->
                <div class="col-md-3 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Empresas Activas</div>
                                    <div class="metric-value text-success" id="statAprobadas"><?php echo $estadisticas['aprobadas']; ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tarjeta: Empresas Desactivadas -->
                <div class="col-md-3 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Empresas Desactivadas</div>
                                    <div class="metric-value text-danger" id="statDesactivadas"><?php echo $estadisticas['desactivadas']; ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-ban fa-2x text-danger opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Segunda fila: Distribución por Plan (solo cantidad) -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-crown me-2"></i>Distribución por Plan
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="card bg-light h-100">
                                        <div class="card-body text-center">
                                            <div class="metric-label">Prueba</div>
                                            <div class="metric-value text-secondary" id="statPrueba"><?php echo $estadisticas['plan_prueba']; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="card bg-light h-100">
                                        <div class="card-body text-center">
                                            <div class="metric-label">Básico</div>
                                            <div class="metric-value text-info" id="statBasico"><?php echo $estadisticas['plan_basico']; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="card bg-light h-100">
                                        <div class="card-body text-center">
                                            <div class="metric-label">Profesional</div>
                                            <div class="metric-value text-primary" id="statStarter"><?php echo $estadisticas['plan_starter']; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="card bg-light h-100">
                                        <div class="card-body text-center">
                                            <div class="metric-label">Empresarial</div>
                                            <div class="metric-value text-warning" id="statEmprendedor"><?php echo $estadisticas['plan_emprendedor']; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="card bg-light h-100">
                                        <div class="card-body text-center">
                                            <div class="metric-label">Empresarial Plus</div>
                                            <div class="metric-value text-success" id="statPremium"><?php echo $estadisticas['plan_premium']; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="card h-100 bg-gradient-primary text-white">
                                        <div class="card-body text-center">
                                            <div class="metric-label text-white-50">Total</div>
                                            <div class="metric-value text-white" id="statTotalPlanes">
    <?php 
    // Calcular la suma REAL de los planes individuales
    $total_planes = $estadisticas['plan_prueba'] + 
                    $estadisticas['plan_basico'] + 
                    $estadisticas['plan_starter'] + 
                    $estadisticas['plan_emprendedor'] + 
                    $estadisticas['plan_premium'];
    echo $total_planes; 
    ?>
</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros y Búsqueda -->
            <div class="filtros-card mb-4">
                <h5 class="mb-3">
                    <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                </h5>
                <form id="filtrosForm" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Plan:</label>
                        <select name="plan" id="filtroPlan" class="form-select">
                            <option value="todos" <?php echo $filtro_plan === 'todos' ? 'selected' : ''; ?>>Todos los planes</option>
                            <option value="prueba" <?php echo $filtro_plan === 'prueba' ? 'selected' : ''; ?>>Prueba</option>
                            <option value="basico" <?php echo $filtro_plan === 'basico' ? 'selected' : ''; ?>>Básico</option>
                            <option value="starter" <?php echo $filtro_plan === 'starter' ? 'selected' : ''; ?>>Profesional</option>
                            <option value="emprendedor" <?php echo $filtro_plan === 'emprendedor' ? 'selected' : ''; ?>>Empresarial</option>
                            <option value="premium" <?php echo $filtro_plan === 'premium' ? 'selected' : ''; ?>>Empresarial Plus</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Buscar:</label>
                        <input type="text" name="busqueda" id="filtroBusqueda" class="form-control"
                            placeholder="Nombre, email, RFC, Plan..."
                            value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="d-grid gap-2 d-md-flex w-100">
                            <button type="button" id="btnFiltrar" class="btn btn-primary flex-grow-1">
                                <i class="fas fa-search me-1"></i>Filtrar
                            </button>
                            <button type="button" id="btnLimpiar" class="btn btn-secondary flex-grow-1">
                                <i class="fas fa-times me-1"></i>Limpiar
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tabla de Empresas -->
            <div class="card">
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                    <h5 class="mb-2 mb-md-0">
                        <i class="fas fa-list-ul me-2"></i>Empresas Registradas
                    </h5>
                    <span class="badge bg-primary" id="totalRegistros"><?php echo $total_registros; ?> registros</span>
                </div>
                <div class="card-body p-0">
                    <!-- CONTENEDOR PARA LA TABLA - Se cargará via AJAX -->
                    <div id="tablaContainer">
                        <!-- El contenido se cargará aquí -->
                    </div>
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
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <a href="empresas.php" class="btn btn-primary w-100 text-start p-3">
                                        <i class="fas fa-building fa-2x mb-2 d-block"></i>
                                        <h6 class="mb-0">Ver Todas las Empresas</h6>
                                        <small class="text-white-50">Gestión completa</small>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="usuarios.php" class="btn btn-info w-100 text-start p-3">
                                        <i class="fas fa-user-cog fa-2x mb-2 d-block"></i>
                                        <h6 class="mb-0">Usuarios Admin</h6>
                                        <small class="text-white-50">Administrar usuarios</small>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="pagos.php" class="btn btn-success w-100 text-start p-3">
                                        <i class="fas fa-money-bill-wave fa-2x mb-2 d-block"></i>
                                        <h6 class="mb-0">Pagos</h6>
                                        <small class="text-white-50">Gestionar pagos</small>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="distribuidores.php" class="btn btn-warning w-100 text-start p-3">
                                        <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                        <h6 class="mb-0">Distribuidores</h6>
                                        <small class="text-white-50">Ver distribuidores</small>
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
                                <p class="mb-1" id="infoTotalEmpresas"><?php echo $estadisticas['total_empresas']; ?> empresas</p>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Estado Actual:</small>
                                <p class="mb-1"><span class="badge bg-success">Operativo</span></p>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Última Actualización:</small>
                                <p class="mb-1"><?php echo date('d/m/Y H:i'); ?></p>
                            </div>
                            <hr>
                            <div class="text-center">
                                <small class="text-muted">Versión 1.0</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ========================================== -->
    <!-- MODALES -->
    <!-- ========================================== -->

    <!-- Modal para Detalle de Empresa -->
    <div class="modal fade" id="modalDetalle" tabindex="-1" aria-labelledby="modalDetalleLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalDetalleLabel">
                        <i class="fas fa-building me-2"></i>Detalle de Empresa
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleEmpresa" style="min-height: 400px;">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-3 text-muted">Cargando información...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Editar Empresa -->
    <div class="modal fade" id="modalEditar" tabindex="-1" aria-labelledby="modalEditarLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="modalEditarLabel">
                        <i class="fas fa-edit me-2"></i>Editar Empresa
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contenidoEditar" style="min-height: 400px;">
                    <div class="text-center py-5">
                        <div class="spinner-border text-warning" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-3 text-muted">Cargando formulario de edición...</p>
                    </div>
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

    <!-- ========================================== -->
    <!-- SCRIPTS -->
    <!-- ========================================== -->

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- JS de navbar y sidebar (separados) -->
    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/sidebar.js"></script>
    
    <!-- JS específico del dashboard -->
    <script src="assets/js/dashboard.js"></script>
</body>
</html>