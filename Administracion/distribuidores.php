<?php
// =============================================
// DISTRIBUIDORES.PHP - Gestión de Distribuidores
// =============================================

// Cargar configuración de sesión personalizada
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

// Variables para el navbar y sidebar
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Administrador';
$usuario_rol = $_SESSION['usuario_rol'] ?? 'admin';

// Cargar configuración de base de datos
require_once __DIR__ . '../../config/database.php';
require_once __DIR__ . '../../env_loader.php';

// Configuración
$registros_por_pagina = 5;
$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones POST (activar/desactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    if (isset($_POST['accion']) && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $accion = $_POST['accion'];
        
        if (in_array($accion, ['activar', 'desactivar'])) {
            try {
                $pdo = getDBConnection();
                $activo = $accion === 'activar' ? 1 : 0;
                
                $stmt = $pdo->prepare("UPDATE distribuidores SET activo = ? WHERE id = ?");
                $stmt->execute([$activo, $id]);
                
                $mensaje = "Distribuidor " . ($accion === 'activar' ? 'activado' : 'desactivado') . " correctamente";
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                $mensaje = "Error al procesar la acción: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
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
        $pdo = getDBConnection();
        
        $sql_stats = "SELECT 
            COUNT(*) as total,
            SUM(estado_verificacion = 'aprobado') as aprobados,
            SUM(estado_verificacion = 'pendiente') as pendientes,
            SUM(estado_verificacion = 'en_revision') as en_revision,
            SUM(estado_verificacion = 'rechazado') as rechazados
            FROM distribuidores";
        
        $stmt = $pdo->query($sql_stats);
        $stats = $stmt->fetch();
        
        if ($stats) {
            $estadisticas = [
                'total_distribuidores' => (int)($stats['total'] ?? 0),
                'aprobados' => (int)($stats['aprobados'] ?? 0),
                'pendientes' => (int)($stats['pendientes'] ?? 0),
                'en_revision' => (int)($stats['en_revision'] ?? 0),
                'rechazados' => (int)($stats['rechazados'] ?? 0)
            ];
            file_put_contents($cache_file, serialize($estadisticas));
        }
    } catch (PDOException $e) {
        $estadisticas = [
            'total_distribuidores' => 0,
            'aprobados' => 0,
            'pendientes' => 0,
            'en_revision' => 0,
            'rechazados' => 0
        ];
    }
}

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
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS de componentes compartidos -->
    <link rel="stylesheet" href="assets/css/navbar.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    
    <!-- CSS específico de distribuidores -->
    <link rel="stylesheet" href="assets/css/distribuidores.css">
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
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?php echo $tipo_mensaje === 'danger' ? 'exclamation-circle' : 'check-circle'; ?> me-2"></i>
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Welcome Card -->
            <div class="card welcome-card mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <img src="../images/LibertyfinBlanco.png"
                                    alt="Logo Empresa"
                                    style="height: 40px; width: auto;">
                            </div>
                            <div>
                                <h4 class="card-title mb-1">Gestión de Distribuidores</h4>
                                <p class="card-text mb-0 opacity-75">
                                    <i class="fas fa-user me-1"></i>
                                    Bienvenido, <?php echo htmlspecialchars($usuario_nombre); ?>
                                    <span class="badge bg-light text-dark ms-2"><?php echo ucfirst($usuario_rol); ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="text-end mt-2 mt-sm-0">
                            <small class="text-white-50 d-block">Distribuidores totales</small>
                            <span class="h5 mb-0 text-white" id="totalDistribuidores"><?php echo $estadisticas['total_distribuidores']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Estadísticas -->
            <div class="row mb-4" id="estadisticasContainer">
                <div class="col-md-2 col-6 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1">Total</h6>
                                    <h3 class="mb-0 text-primary" id="statTotal"><?php echo $estadisticas['total_distribuidores']; ?></h3>
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
                                    <h3 class="mb-0 text-success" id="statAprobados"><?php echo $estadisticas['aprobados']; ?></h3>
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
                                    <h3 class="mb-0 text-warning" id="statPendientes"><?php echo $estadisticas['pendientes']; ?></h3>
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
                                    <h3 class="mb-0 text-info" id="statEnRevision"><?php echo $estadisticas['en_revision']; ?></h3>
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
                                    <h3 class="mb-0 text-danger" id="statRechazados"><?php echo $estadisticas['rechazados']; ?></h3>
                                </div>
                                <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                                    <i class="fas fa-times-circle text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-3">
                    <div class="card stat-card h-100" style="border-left-color: #6f42c1;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1">Activos</h6>
                                    <h3 class="mb-0" id="statActivos"><?php echo $estadisticas['aprobados']; ?></h3>
                                </div>
                                <div class="bg-purple bg-opacity-10 p-3 rounded-circle" style="background-color: rgba(111, 66, 193, 0.1);">
                                    <i class="fas fa-user-check" style="color: #6f42c1;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="filtros-card">
                <h5 class="mb-3">
                    <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                </h5>
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
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                    <h5 class="mb-2 mb-md-0">
                        <i class="fas fa-users me-2 text-primary"></i>Listado de Distribuidores
                    </h5>
                    <div class="d-flex gap-2">
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
        </div>
    </main>

    <!-- ========================================== -->
    <!-- MODALES -->
    <!-- ========================================== -->

    <!-- Modal Visor de Archivos -->
    <div class="modal fade" id="modalArchivo" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalArchivoTitulo">Visor de Archivos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="archivoCargando" class="d-flex align-items-center justify-content-center h-100">
                        <div class="text-center">
                            <div class="spinner-border text-primary"></div>
                            <p>Cargando...</p>
                        </div>
                    </div>
                    <div id="visorImagen" class="d-none h-100">
                        <img id="imagenVisor" class="img-fluid" style="width:100%;height:100%;object-fit:contain;">
                    </div>
                    <div id="visorPDF" class="d-none h-100">
                        <iframe id="pdfVisor" style="width:100%;height:100%;border:none;"></iframe>
                    </div>
                    <div id="visorError" class="d-none h-100">
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-triangle fa-4x text-warning"></i>
                            <h5>Error al cargar</h5>
                            <a id="descargarArchivo" class="btn btn-primary"><i class="fas fa-download"></i>Descargar</a>
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

    <!-- Modal Confirmar -->
    <div class="modal fade" id="modalConfirmar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Acción</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="mensajeConfirmacion"></p>
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

    <!-- Modal Detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalles del Distribuidor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleDistribuidor">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                        <p>Cargando...</p>
                    </div>
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
    
    <!-- JS específico de distribuidores -->
    <script src="assets/js/distribuidores.js"></script>
</body>
</html>