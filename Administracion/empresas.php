<?php
// =============================================
// EMPRESAS.PHP - Gestión de Empresas
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
require_once __DIR__ . '../../config/database.php';
require_once __DIR__ . '../../env_loader.php';

// =============================================
// VARIABLES Y FILTROS
// =============================================
$mensaje = '';
$tipo_mensaje = '';
$empresas = [];

$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_estado_verificacion = isset($_GET['estado_verificacion']) ? $_GET['estado_verificacion'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$filtro_fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$filtro_fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

// Paginación
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 20;

// =============================================
// ESTADÍSTICAS
// =============================================
$estadisticas = [
    'total_empresas' => 0,
    'activas' => 0,
    'inactivas' => 0,
    'aprobadas' => 0,
    'pendientes' => 0,
    'en_revision' => 0,
    'rechazadas' => 0
];

// =============================================
// PROCESAR ACTIVAR/DESACTIVAR
// =============================================
if (isset($_GET['accion']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $empresa_id = intval($_GET['id']);
    $accion = $_GET['accion'];
    
    if ($accion === 'activar' || $accion === 'desactivar') {
        try {
            $pdo = getDBConnection();
            $nuevo_estado = ($accion === 'activar') ? 1 : 0;
            
            $stmt_update = $pdo->prepare("UPDATE empresas SET activo = ? WHERE id = ?");
            $stmt_update->execute([$nuevo_estado, $empresa_id]);
            
            $mensaje = "Empresa " . ($accion === 'activar' ? 'activada' : 'desactivada') . " exitosamente";
            $tipo_mensaje = "success";
            
        } catch (PDOException $e) {
            $mensaje = "Error al " . ($accion === 'activar' ? 'activar' : 'desactivar') . " la empresa: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    }
}

try {
    $pdo = getDBConnection();
    
    // =============================================
    // OBTENER ESTADÍSTICAS
    // =============================================
    $sql_stats = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activas,
        SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivas,
        SUM(CASE WHEN estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobadas,
        SUM(CASE WHEN estado_verificacion = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
        SUM(CASE WHEN estado_verificacion = 'en_revision' THEN 1 ELSE 0 END) as en_revision,
        SUM(CASE WHEN estado_verificacion = 'rechazado' THEN 1 ELSE 0 END) as rechazadas
        FROM empresas";

    $stmt = $pdo->query($sql_stats);
    $stats = $stmt->fetch();
    
    if ($stats) {
        $estadisticas['total_empresas'] = $stats['total'] ?? 0;
        $estadisticas['activas'] = $stats['activas'] ?? 0;
        $estadisticas['inactivas'] = $stats['inactivas'] ?? 0;
        $estadisticas['aprobadas'] = $stats['aprobadas'] ?? 0;
        $estadisticas['pendientes'] = $stats['pendientes'] ?? 0;
        $estadisticas['en_revision'] = $stats['en_revision'] ?? 0;
        $estadisticas['rechazadas'] = $stats['rechazadas'] ?? 0;
    }

    // =============================================
    // CONSULTA CON FILTROS Y PAGINACIÓN
    // =============================================
    $sql_where = "WHERE 1=1";
    $params = [];

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
    }

    // Filtro por fecha
    if (!empty($filtro_fecha_desde)) {
        $sql_where .= " AND DATE(e.fecha_creacion) >= ?";
        $params[] = $filtro_fecha_desde;
    }

    if (!empty($filtro_fecha_hasta)) {
        $sql_where .= " AND DATE(e.fecha_creacion) <= ?";
        $params[] = $filtro_fecha_hasta;
    }

    // Contar total
    $sql_count = "SELECT COUNT(*) as total FROM empresas e $sql_where";
    $stmt = $pdo->prepare($sql_count);
    $stmt->execute($params);
    $total_registros = $stmt->fetchColumn();

    // Paginación
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina_actual - 1) * $registros_por_pagina;

    // Consulta principal
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

    $params[] = $registros_por_pagina;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $empresas = $stmt->fetchAll();

} catch (PDOException $e) {
    $mensaje = "Error al cargar las empresas: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// =============================================
// FUNCIONES AUXILIARES
// =============================================
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

function claseEstadoVerificacion($estado) {
    switch ($estado) {
        case 'pendiente': return 'warning';
        case 'en_revision': return 'info';
        case 'aprobado': return 'success';
        case 'rechazado': return 'danger';
        default: return 'secondary';
    }
}

function textoEstadoVerificacion($estado) {
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
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS de componentes compartidos -->
    <link rel="stylesheet" href="assets/css/navbar.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    
    <!-- CSS específico de empresas -->
    <link rel="stylesheet" href="assets/css/empresas.css">
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
            <!-- Mensajes -->
            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Título y acciones -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
                <div class="mb-3 mb-md-0">
                    <h1 class="h4 mb-1">
                        <i class="fas fa-building me-2"></i>Gestión de Empresas
                    </h1>
                    <p class="text-muted mb-0">
                        <span class="d-inline-block me-3">
                            <i class="fas fa-layer-group me-1"></i>Total: <?php echo $estadisticas['total_empresas']; ?>
                        </span>
                        <span class="d-inline-block me-3">
                            <i class="fas fa-check-circle text-success me-1"></i>Activas: <?php echo $estadisticas['activas']; ?>
                        </span>
                        <span class="d-inline-block">
                            <i class="fas fa-times-circle text-danger me-1"></i>Inactivas: <?php echo $estadisticas['inactivas']; ?>
                        </span>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="nueva_empresa.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i><span class="d-none d-sm-inline">Nueva Empresa</span>
                    </a>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i><span class="d-none d-sm-inline">Dashboard</span>
                    </a>
                </div>
            </div>

            <!-- Filtros Avanzados -->
            <div class="filtros-card">
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
                        <div class="d-flex flex-wrap justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>Filtrar
                            </button>
                            <a href="empresas.php" class="btn btn-outline-secondary">
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
                                                <div class="d-md-none mt-1">
                                                    <small><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($empresa['nombre_contacto'] ?? 'No especificado'); ?></small>
                                                    <br>
                                                    <small><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($empresa['email'] ?? 'No especificado'); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Contacto" class="d-none d-md-table-cell">
                                                <?php echo htmlspecialchars($empresa['nombre_contacto']); ?>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?></small>
                                            </td>
                                            <td data-label="Email" class="d-none d-lg-table-cell">
                                                <a href="mailto:<?php echo htmlspecialchars($empresa['email'] ?? 'No especificado'); ?>">
                                                    <?php echo htmlspecialchars($empresa['email'] ?? 'No especificado'); ?>
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
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button class="btn btn-outline-info"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalDetalle"
                                                        data-id="<?php echo $empresa['id']; ?>"
                                                        title="Ver detalles">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="gestionar_empresa.php?id=<?php echo $empresa['id']; ?>"
                                                        class="btn btn-outline-warning"
                                                        title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($empresa['activo'] == 1): ?>
                                                        <a href="?accion=desactivar&id=<?php echo $empresa['id']; ?><?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['accion' => '', 'id' => ''])) : ''; ?>"
                                                            class="btn btn-outline-danger"
                                                            onclick="return confirm('¿Está seguro de desactivar esta empresa?')"
                                                            title="Desactivar">
                                                            <i class="fas fa-toggle-off"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="?accion=activar&id=<?php echo $empresa['id']; ?><?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['accion' => '', 'id' => ''])) : ''; ?>"
                                                            class="btn btn-outline-success"
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
                                <ul class="pagination justify-content-center mb-0 flex-wrap">
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link"
                                            href="?pagina=<?php echo $pagina_actual - 1; ?>&estado=<?php echo $filtro_estado; ?>&estado_verificacion=<?php echo $filtro_estado_verificacion; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>&fecha_desde=<?php echo $filtro_fecha_desde; ?>&fecha_hasta=<?php echo $filtro_fecha_hasta; ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>

                                    <?php 
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
            <div class="row mt-4 g-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-pie me-2"></i>Resumen de Empresas
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-4 col-md-2 text-center">
                                    <div class="p-3 border rounded bg-light">
                                        <h3 class="mb-1 text-primary"><?php echo $estadisticas['total_empresas']; ?></h3>
                                        <small class="text-muted">Total</small>
                                    </div>
                                </div>
                                <div class="col-4 col-md-2 text-center">
                                    <div class="p-3 border rounded bg-light">
                                        <h3 class="mb-1 text-success"><?php echo $estadisticas['activas']; ?></h3>
                                        <small class="text-muted">Activas</small>
                                    </div>
                                </div>
                                <div class="col-4 col-md-2 text-center">
                                    <div class="p-3 border rounded bg-light">
                                        <h3 class="mb-1 text-danger"><?php echo $estadisticas['inactivas']; ?></h3>
                                        <small class="text-muted">Inactivas</small>
                                    </div>
                                </div>
                                <div class="col-4 col-md-2 text-center">
                                    <div class="p-3 border rounded bg-light">
                                        <h3 class="mb-1 text-success"><?php echo $estadisticas['aprobadas']; ?></h3>
                                        <small class="text-muted">Aprobadas</small>
                                    </div>
                                </div>
                                <div class="col-4 col-md-2 text-center">
                                    <div class="p-3 border rounded bg-light">
                                        <h3 class="mb-1 text-warning"><?php echo $estadisticas['pendientes']; ?></h3>
                                        <small class="text-muted">Pendientes</small>
                                    </div>
                                </div>
                                <div class="col-4 col-md-2 text-center">
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
        </div>
    </main>

    <!-- ========================================== -->
    <!-- MODALES -->
    <!-- ========================================== -->

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

    <!-- ========================================== -->
    <!-- SCRIPTS -->
    <!-- ========================================== -->

    <!-- jQuery (necesario para AJAX) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- JS de navbar y sidebar (separados) -->
    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/sidebar.js"></script>
    
    <!-- JS específico de empresas -->
    <script src="assets/js/empresas.js"></script>
</body>
</html>