<?php
// pagos.php

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

// Variables para mensajes y filtros
$mensaje = '';
$tipo_mensaje = '';

// Inicializar estadísticas con valores por defecto
$stats_liga = [
    'total_pagos' => 0,
    'total_monto' => 0,
    'completados' => 0,
    'pendientes' => 0,
    'fallidos' => 0,
    'monto_completados' => 0
];

$stats_spei = [
    'total_transacciones' => 0,
    'total_monto' => 0,
    'total_respuesta_positiva' => 0,
    'total_respuesta_negativa' => 0,
    'monto_exitoso' => 0
];

$estadisticas = [
    'total_pagos' => 0,
    'total_monto' => 0,
    'completados' => 0,
    'pendientes' => 0,
    'fallidos' => 0,
    'monto_completados' => 0
];

try {
    $pdo = getDBConnection();
    
    // Verificar si la tabla pagos_liga existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'pagos_liga'");
    $liga_table_exists = $stmt->rowCount() > 0;
    
    // Verificar si la tabla pagos_spei_recibidos existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'pagos_spei_recibidos'");
    $spei_table_exists = $stmt->rowCount() > 0;
    
    // Estadísticas de pagos con liga
    if ($liga_table_exists) {
        try {
            // Obtener los valores distintos de response
            $stmt = $pdo->query("SELECT DISTINCT response FROM pagos_liga WHERE response IS NOT NULL");
            $responses = $stmt->fetchAll();
            $response_values = array_column($responses, 'response');
            
            if (!empty($response_values)) {
                $completed_values = [];
                $failed_values = [];
                $other_values = [];
                
                foreach ($response_values as $resp) {
                    $resp_lower = strtolower(trim($resp));
                    if (in_array($resp_lower, ['approved', 'a', 'aprobado', 'completado', 'success', '1'])) {
                        $completed_values[] = $resp;
                    } elseif (in_array($resp_lower, ['denied', 'd', 'declined', 'rejected', 'fallido', 'error', '0', 'failed'])) {
                        $failed_values[] = $resp;
                    } else {
                        $other_values[] = $resp;
                    }
                }
                
                // Construir la consulta de manera más segura
                $sql_stats_liga = "SELECT 
                    COUNT(*) as total_pagos,
                    COALESCE(SUM(amount), 0) as total_monto,";
                
                // Completados
                if (!empty($completed_values)) {
                    $placeholders = implode(',', array_fill(0, count($completed_values), '?'));
                    $sql_stats_liga .= "
                    SUM(CASE WHEN response IN ($placeholders) THEN 1 ELSE 0 END) as completados,
                    COALESCE(SUM(CASE WHEN response IN ($placeholders) THEN amount ELSE 0 END), 0) as monto_completados,";
                } else {
                    $sql_stats_liga .= " 0 as completados, 0 as monto_completados,";
                }
                
                // Fallidos
                if (!empty($failed_values)) {
                    $placeholders_failed = implode(',', array_fill(0, count($failed_values), '?'));
                    $sql_stats_liga .= "
                    SUM(CASE WHEN response IN ($placeholders_failed) THEN 1 ELSE 0 END) as fallidos,";
                } else {
                    $sql_stats_liga .= " 0 as fallidos,";
                }
                
                // Pendientes - todos los demás
                $pendientes_condition = "";
                $all_placeholders = [];
                
                if (!empty($completed_values)) {
                    $all_placeholders = array_merge($all_placeholders, $completed_values);
                }
                if (!empty($failed_values)) {
                    $all_placeholders = array_merge($all_placeholders, $failed_values);
                }
                
                if (!empty($all_placeholders)) {
                    $all_placeholders_str = implode(',', array_fill(0, count($all_placeholders), '?'));
                    $pendientes_condition = "response NOT IN ($all_placeholders_str)";
                } else {
                    $pendientes_condition = "1=1";
                }
                
                $sql_stats_liga .= "
                    SUM(CASE WHEN ($pendientes_condition) OR response IS NULL THEN 1 ELSE 0 END) as pendientes
                    FROM pagos_liga";
                
                // Preparar parámetros
                $params = [];
                if (!empty($completed_values)) {
                    $params = array_merge($params, $completed_values);
                }
                if (!empty($failed_values)) {
                    $params = array_merge($params, $failed_values);
                }
                if (!empty($all_placeholders)) {
                    $params = array_merge($params, $all_placeholders);
                }
                
                $stmt = $pdo->prepare($sql_stats_liga);
                $stmt->execute($params);
                $stats_liga = $stmt->fetch();
                
                if (!$stats_liga) {
                    $stats_liga = [
                        'total_pagos' => 0,
                        'total_monto' => 0,
                        'completados' => 0,
                        'pendientes' => 0,
                        'fallidos' => 0,
                        'monto_completados' => 0
                    ];
                }
            } else {
                // No hay valores de response
                $sql_stats_liga = "SELECT 
                    COUNT(*) as total_pagos,
                    COALESCE(SUM(amount), 0) as total_monto,
                    0 as completados,
                    0 as pendientes,
                    0 as fallidos,
                    0 as monto_completados
                    FROM pagos_liga";
                
                $stmt = $pdo->query($sql_stats_liga);
                $stats_liga = $stmt->fetch();
                
                if (!$stats_liga) {
                    $stats_liga = [
                        'total_pagos' => 0,
                        'total_monto' => 0,
                        'completados' => 0,
                        'pendientes' => 0,
                        'fallidos' => 0,
                        'monto_completados' => 0
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log("Error en estadísticas de liga: " . $e->getMessage());
            // Fallback a consulta simple
            $sql_stats_liga = "SELECT 
                COUNT(*) as total_pagos,
                COALESCE(SUM(amount), 0) as total_monto,
                0 as completados,
                0 as pendientes,
                0 as fallidos,
                0 as monto_completados
                FROM pagos_liga";
            
            $stmt = $pdo->query($sql_stats_liga);
            $stats_liga = $stmt->fetch();
            
            if (!$stats_liga) {
                $stats_liga = [
                    'total_pagos' => 0,
                    'total_monto' => 0,
                    'completados' => 0,
                    'pendientes' => 0,
                    'fallidos' => 0,
                    'monto_completados' => 0
                ];
            }
        }
    }
    
    // Estadísticas de transferencias SPEI recibidas
    if ($spei_table_exists) {
        try {
            // Usar tabla pagos_spei_recibidos
            $sql_stats_spei = "SELECT 
                COUNT(*) as total_transacciones,
                COALESCE(SUM(monto_recibido), 0) as total_monto,
                SUM(CASE WHEN estado IN ('confirmado', 'pendiente') THEN 1 ELSE 0 END) as total_respuesta_positiva,
                SUM(CASE WHEN estado IN ('rechazado', 'cancelado') THEN 1 ELSE 0 END) as total_respuesta_negativa,
                COALESCE(SUM(CASE WHEN estado IN ('confirmado', 'pendiente') THEN monto_recibido ELSE 0 END), 0) as monto_exitoso
                FROM pagos_spei_recibidos";
            
            $stmt = $pdo->query($sql_stats_spei);
            $stats_spei = $stmt->fetch();
            
            if (!$stats_spei) {
                $stats_spei = [
                    'total_transacciones' => 0,
                    'total_monto' => 0,
                    'total_respuesta_positiva' => 0,
                    'total_respuesta_negativa' => 0,
                    'monto_exitoso' => 0
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en estadísticas de SPEI: " . $e->getMessage());
            $stats_spei = [
                'total_transacciones' => 0,
                'total_monto' => 0,
                'total_respuesta_positiva' => 0,
                'total_respuesta_negativa' => 0,
                'monto_exitoso' => 0
            ];
        }
    }
    
    // Estadísticas combinadas para el resumen
    $estadisticas = [
        'total_pagos' => ($stats_liga['total_pagos'] ?? 0) + ($stats_spei['total_transacciones'] ?? 0),
        'total_monto' => ($stats_liga['total_monto'] ?? 0) + ($stats_spei['total_monto'] ?? 0),
        'completados' => ($stats_liga['completados'] ?? 0) + ($stats_spei['total_respuesta_positiva'] ?? 0),
        'pendientes' => ($stats_liga['pendientes'] ?? 0),
        'fallidos' => ($stats_liga['fallidos'] ?? 0) + ($stats_spei['total_respuesta_negativa'] ?? 0),
        'monto_completados' => ($stats_liga['monto_completados'] ?? 0) + ($stats_spei['monto_exitoso'] ?? 0)
    ];
    
} catch (PDOException $e) {
    $mensaje = "Error al cargar las estadísticas: " . $e->getMessage();
    $tipo_mensaje = "danger";
    error_log("Error en pagos.php: " . $e->getMessage());
    
    // Asegurar que $estadisticas tenga valores por defecto en caso de error
    $estadisticas = [
        'total_pagos' => 0,
        'total_monto' => 0,
        'completados' => 0,
        'pendientes' => 0,
        'fallidos' => 0,
        'monto_completados' => 0
    ];
}

// Funciones auxiliares
function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

function formatearMoneda($monto, $moneda = 'MXN')
{
    if ($monto === null) return 'N/A';
    return number_format($monto, 2) . ' ' . $moneda;
}

function claseEstado($estado)
{
    $estado_lower = strtolower($estado);
    switch ($estado_lower) {
        case 'completed':
        case 'approved':
        case 'a':
        case 'aprobado':
        case 'success':
        case 'confirmado':
            return 'success';
        case 'pending':
        case 'p':
        case 'pendiente':
            return 'warning';
        case 'created':
            return 'info';
        case 'failed':
        case 'denied':
        case 'd':
        case 'declined':
        case 'rejected':
        case 'fallido':
        case 'cancelled':
        case 'expired':
        case 'c':
        case 'error':
        case 'rechazado':
        case 'cancelado':
            return 'danger';
        default:
            return 'secondary';
    }
}

function textoEstado($estado)
{
    $estado_lower = strtolower($estado);
    $estados = [
        'completed' => 'Completado',
        'approved' => 'Completado',
        'a' => 'Completado',
        'aprobado' => 'Completado',
        'success' => 'Completado',
        'confirmado' => 'Completado',
        'pending' => 'Pendiente',
        'p' => 'Pendiente',
        'pendiente' => 'Pendiente',
        'created' => 'Creado',
        'failed' => 'Fallido',
        'denied' => 'Fallido',
        'd' => 'Fallido',
        'declined' => 'Fallido',
        'rejected' => 'Fallido',
        'fallido' => 'Fallido',
        'cancelled' => 'Cancelado',
        'c' => 'Cancelado',
        'expired' => 'Expirado',
        'error' => 'Error',
        'rechazado' => 'Rechazado',
        'cancelado' => 'Cancelado'
    ];
    return $estados[$estado_lower] ?? $estado;
}

function truncarTexto($texto, $longitud = 30)
{
    if (strlen($texto) <= $longitud) return $texto;
    return substr($texto, 0, $longitud) . '...';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Pagos - Panel de Administración</title>
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS de componentes compartidos -->
    <link rel="stylesheet" href="assets/css/navbar.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    
    <!-- CSS específico de pagos -->
    <link rel="stylesheet" href="assets/css/pagos.css">
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
            <!-- Mensajes de alerta -->
            <?php if (!empty($mensaje)): ?>
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
                                <h4 class="card-title mb-1">Panel de Pagos</h4>
                                <div class="d-flex align-items-center flex-wrap">
                                    <p class="card-text mb-0 me-3 opacity-75">
                                        <i class="fas fa-user me-1"></i>
                                        Bienvenido, <?php echo htmlspecialchars($usuario_nombre); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="text-end mt-2 mt-sm-0">
                            <div class="d-flex align-items-center">
                                <div class="me-3 text-start">
                                    <small class="text-white-50 d-block">Total pagos</small>
                                    <span class="h5 mb-0 text-white"><?php echo $estadisticas['total_pagos']; ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-chart-line fa-2x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estadísticas Combinadas -->
            <div class="row mb-4" id="estadisticasContainer">
                <div class="col-md-2 col-6 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Total Pagos</div>
                                    <div class="metric-value text-primary" id="totalPagos"><?php echo $estadisticas['total_pagos']; ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-credit-card fa-2x text-primary opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-3">
                    <div class="card bg-success text-white h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label text-white-50">Total Ingresos</div>
                                    <div class="metric-value text-white" id="totalIngresos"><?php echo formatearMoneda($estadisticas['total_monto']); ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-dollar-sign fa-2x text-white opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Completados</div>
                                    <div class="metric-value text-success" id="completadosCount"><?php echo $estadisticas['completados']; ?></div>
                                    <small class="text-muted" id="completadosMonto"><?php echo formatearMoneda($estadisticas['monto_completados']); ?></small>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Pendientes</div>
                                    <div class="metric-value text-warning" id="pendientesCount"><?php echo $estadisticas['pendientes']; ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-clock fa-2x text-warning opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Fallidos</div>
                                    <div class="metric-value text-danger" id="fallidosCount"><?php echo $estadisticas['fallidos']; ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-exclamation-circle fa-2x text-danger opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="metric-label">Ticket Promedio</div>
                                    <div class="metric-value text-info" id="ticketPromedio">
                                        <?php
                                        $promedio = $estadisticas['completados'] > 0
                                            ? $estadisticas['monto_completados'] / $estadisticas['completados']
                                            : 0;
                                        echo formatearMoneda($promedio);
                                        ?>
                                    </div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-chart-line fa-2x text-info opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros Generales -->
            <div class="filtros-card mb-4">
                <h5 class="mb-3">
                    <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                </h5>
                <form id="filtrosForm" class="row g-3 align-items-end">
                    <div class="col-md-3 col-6">
                        <label class="form-label">Estado:</label>
                        <select name="estado" id="filtroEstado" class="form-select">
                            <option value="">Todos los estados</option>
                            <option value="COMPLETED">Completados</option>
                            <option value="PENDING">Pendientes</option>
                            <option value="FAILED">Fallidos</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label">Fecha inicio:</label>
                        <input type="date" name="fecha_inicio" id="filtroFechaInicio" class="form-control">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label">Fecha fin:</label>
                        <input type="date" name="fecha_fin" id="filtroFechaFin" class="form-control">
                    </div>
                    <div class="col-md-3 col-6">
                        <button type="button" id="btnLimpiarFiltros" class="btn btn-secondary w-100">
                            <i class="fas fa-times me-1"></i>Limpiar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Selector de registros por página -->
            <div class="row mb-3">
                <div class="col-md-6 col-12">
                    <div class="d-flex align-items-center gap-2 justify-content-md-start justify-content-between">
                        <label class="text-muted small fw-bold">Registros por página:</label>
                        <select id="registrosPorPagina" class="form-select form-select-sm w-auto">
                            <option value="5" selected>5</option>
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Tabs para los dos tipos de pago -->
            <ul class="nav nav-tabs" id="pagosTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="liga-tab" data-bs-toggle="tab" data-bs-target="#liga" type="button" role="tab">
                        <i class="fas fa-link"></i> Liga
                        <span class="badge bg-secondary ms-2" id="ligaTotalBadge"><?php echo $stats_liga['total_pagos'] ?? 0; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="spei-tab" data-bs-toggle="tab" data-bs-target="#spei" type="button" role="tab">
                        <i class="fas fa-exchange-alt"></i> Transferencias SPEI
                        <span class="badge bg-secondary ms-2" id="speiTotalBadge"><?php echo $stats_spei['total_transacciones'] ?? 0; ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="pagosTabsContent">
                <!-- Tab Liga de Pago -->
                <div class="tab-pane fade show active" id="liga" role="tabpanel" aria-labelledby="liga-tab">
                    <div class="card">
                        <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                            <h5 class="mb-2 mb-md-0">
                                <i class="fas fa-link me-2 text-info"></i>Pagos con Liga
                            </h5>
                            <span class="badge bg-primary" id="ligaRegistrosCount">0 registros</span>
                        </div>
                        <div class="card-body">
                            <!-- Búsqueda específica para Liga -->
                            <div class="search-box">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Buscar en Liga:</label>
                                        <div class="input-group">
                                            <input type="text" id="busquedaLiga" class="form-control" 
                                                placeholder="Referencia, email, nombre, folio, autorización...">
                                            <button type="button" id="btnBuscarLiga" class="btn btn-primary">
                                                <i class="fas fa-search"></i>
                                            </button>
                                            <button type="button" id="btnLimpiarBusquedaLiga" class="btn btn-secondary" style="display: none;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="table-container">
                                <div id="ligaLoading" class="text-center py-4" style="display: none;">
                                    <div class="spinner-border text-info" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                    <p class="text-muted mt-2">Cargando pagos con Liga...</p>
                                </div>
                                
                                <!-- Vista Desktop (Tabla) -->
                                <div id="ligaTablaContainer" class="desktop-view">
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="ligaTabla">
                                            <thead>
                                                <tr>
                                                    <th>Folio</th>
                                                    <th>Fecha</th>
                                                    <th>Nombre</th>
                                                    <th>Email</th>
                                                    <th>Monto</th>
                                                    <th>Estado</th>
                                                    <th>Referencia</th>
                                                    <th>Autorización</th>
                                                    <th>Tarjeta</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody id="ligaTablaBody">
                                                <tr>
                                                    <td colspan="10" class="text-center py-4">
                                                        <div class="text-muted">
                                                            <i class="fas fa-link fa-3x d-block mb-3"></i>
                                                            Selecciona filtros para cargar los datos
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- Vista Móvil (Tarjetas) -->
                                <div id="ligaCardsContainer" class="mobile-view">
                                    <div id="ligaCardsBody" class="cards-grid">
                                        <div class="text-center py-4">
                                            <div class="text-muted">
                                                <i class="fas fa-link fa-3x d-block mb-3"></i>
                                                Selecciona filtros para cargar los datos
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Paginación Liga -->
                            <div id="ligaPaginacion" class="mt-3" style="display: none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Tab Transferencias SPEI -->
                <div class="tab-pane fade" id="spei" role="tabpanel" aria-labelledby="spei-tab">
                    <div class="card">
                        <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                            <h5 class="mb-2 mb-md-0">
                                <i class="fas fa-exchange-alt me-2 text-warning"></i>Transferencias SPEI
                            </h5>
                            <span class="badge bg-primary" id="speiRegistrosCount">0 registros</span>
                        </div>
                        <div class="card-body">
                            <!-- Búsqueda específica para SPEI -->
                            <div class="search-box">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Buscar en Transferencias:</label>
                                        <div class="input-group">
                                            <input type="text" id="busquedaSpei" class="form-control" 
                                                placeholder="CLABE, transacción, autorización, empresa...">
                                            <button type="button" id="btnBuscarSpei" class="btn btn-primary">
                                                <i class="fas fa-search"></i>
                                            </button>
                                            <button type="button" id="btnLimpiarBusquedaSpei" class="btn btn-secondary" style="display: none;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="table-container">
                                <div id="speiLoading" class="text-center py-4" style="display: none;">
                                    <div class="spinner-border text-warning" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                    <p class="text-muted mt-2">Cargando transferencias SPEI...</p>
                                </div>
                                
                                <!-- Vista Desktop (Tabla) -->
                                <div id="speiTablaContainer" class="desktop-view">
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="speiTabla">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Fecha</th>
                                                    <th>CLABE</th>
                                                    <th>Monto</th>
                                                    <th>Transacción</th>
                                                    <th>Estado</th>
                                                    <th>Autorización</th>
                                                    <th>Empresa/Mensaje</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody id="speiTablaBody">
                                                <tr>
                                                    <td colspan="9" class="text-center py-4">
                                                        <div class="text-muted">
                                                            <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                                                            Selecciona filtros para cargar los datos
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- Vista Móvil (Tarjetas) -->
                                <div id="speiCardsContainer" class="mobile-view">
                                    <div id="speiCardsBody" class="cards-grid">
                                        <div class="text-center py-4">
                                            <div class="text-muted">
                                                <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                                                Selecciona filtros para cargar los datos
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Paginación SPEI -->
                            <div id="speiPaginacion" class="mt-3" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ========================================== -->
    <!-- MODALES -->
    <!-- ========================================== -->

    <!-- Modal Liga -->
    <div class="modal fade" id="modalDetalleLiga" tabindex="-1" aria-labelledby="modalDetalleLigaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="modalDetalleLigaLabel">
                        <i class="fas fa-link me-2"></i>Detalles del Pago con Liga
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="detalleLigaCargando" class="text-center py-4">
                        <div class="spinner-border text-info mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="text-muted">Cargando información del pago...</p>
                    </div>
                    <div id="detalleLigaContenido" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal SPEI -->
    <div class="modal fade" id="modalDetalleSpei" tabindex="-1" aria-labelledby="modalDetalleSpeiLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="modalDetalleSpeiLabel">
                        <i class="fas fa-exchange-alt me-2"></i>Detalles de la Transferencia SPEI
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="detalleSpeiCargando" class="text-center py-4">
                        <div class="spinner-border text-warning mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="text-muted">Cargando información de la transferencia...</p>
                    </div>
                    <div id="detalleSpeiContenido" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
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
    
    <!-- JS específico de pagos -->
    <script src="assets/js/pagos.js"></script>
</body>
</html>