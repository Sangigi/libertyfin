<?php
session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['distribuidor_id'])) {
    header("Location: login-distribuidor.php");
    exit;
}

// Verificar que se proporcionó un ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: mis-empresas.php?error=id_no_proporcionado");
    exit;
}

$empresa_id = intval($_GET['id']);

$db_config = [
    'host' => 'libertyfin.com.mx',
    'user' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'database' => 'juanc141_ventas'
];

$conn = new mysqli(
    $db_config['host'],
    $db_config['user'],
    $db_config['password'],
    $db_config['database']
);

$distribuidor_id = $_SESSION['distribuidor_id'];

// Obtener información del distribuidor
$sql = "SELECT * FROM distribuidores WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $distribuidor_id);
$stmt->execute();
$result = $stmt->get_result();
$distribuidor = $result->fetch_assoc();
$stmt->close();

$numero_control_distribuidor = $distribuidor['numero_control'];

// Obtener detalles de la empresa (verificando que pertenezca al distribuidor)
$empresa_sql = "SELECT e.*, g.nombre as nombre_giro 
                FROM empresas e
                LEFT JOIN giro_comercial g ON e.giro_comercial = g.id
                WHERE e.id = ? AND e.no_distribuidor = ?";

$stmt_empresa = $conn->prepare($empresa_sql);
$stmt_empresa->bind_param("is", $empresa_id, $numero_control_distribuidor);
$stmt_empresa->execute();
$empresa_result = $stmt_empresa->get_result();
$empresa = $empresa_result->fetch_assoc();
$stmt_empresa->close();

// Si la empresa no existe o no pertenece al distribuidor
if (!$empresa) {
    header("Location: mis-empresas.php?error=empresa_no_encontrada");
    exit;
}

$conn->close();

// Funciones auxiliares
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

function formatearFechaSimple($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y', strtotime($fecha));
}

function claseEstado($estado) {
    switch ($estado) {
        case 'pendiente': return 'warning';
        case 'en_revision': return 'info';
        case 'aprobado': return 'success';
        case 'rechazado': return 'danger';
        default: return 'secondary';
    }
}

function textoEstado($estado) {
    $estados = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Revisión',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado'
    ];
    return $estados[$estado] ?? ucfirst($estado);
}

function getPlanBadge($plan) {
    $planes = [
        'prueba' => ['bg-secondary', 'Plan Prueba'],
        'basico' => ['bg-info', 'Plan Básico'],
        'starter' => ['bg-primary', 'Plan Starter'],
        'emprendedor' => ['bg-warning', 'Plan Emprendedor'],
        'premium' => ['bg-success', 'Plan Premium']
    ];
    return $planes[$plan] ?? ['bg-secondary', 'No especificado'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalles de Empresa - Libertyfin</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root { --primary-color: #27ae60; --secondary-color: #2ecc71; }
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
        }
        .navbar { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); }
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
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }
        .sidebar .nav-link i { width: 20px; margin-right: 10px; }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
        }
        .card:hover { transform: translateY(-2px); }
        .welcome-card { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
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
            background: rgba(0,0,0,0.5);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-backdrop.show { display: block; opacity: 1; }
        @media (max-width: 767.98px) {
            .sidebar-toggle { display: block; }
            .sidebar {
                position: fixed;
                top: 56px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
                box-shadow: 2px 0 10px rgba(0,0,0,0.3);
            }
            .sidebar.show { transform: translateX(0); }
            main { margin-left: 0 !important; padding: 1rem !important; }
        }
        .img-logo-navbar { height: 30px; width: auto; max-height: 100%; }
        
        /* Estilos para la página de detalles */
        .info-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        .info-section h5 {
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
        }
        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            font-size: 1.1rem;
            margin-bottom: 15px;
            padding-left: 10px;
        }
        .documento-item {
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .documento-item:hover {
            background-color: #f8f9fa;
            border-color: var(--primary-color);
        }
        .badge-estado {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .status-badge {
            font-size: 1rem;
            padding: 10px 20px;
        }
        
        /* Estilos para el visor de archivos */
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
                <img src="../images/LibertyfinBlanco.webp" alt="LibertyFin" class="me-2 img-logo-navbar">
                <span>Detalles de Empresa</span>
            </a>
            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['distribuidor_nombre'] ?? 'Distribuidor'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="perfil-distribuidor.php"><i class="fas fa-user me-2"></i>Mi Perfil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="cerrar-sesion.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="panel-distribuidor.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="mis-empresas.php"><i class="fas fa-building"></i>Mis Empresas</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="nueva-empresa.php"><i class="fas fa-plus-circle"></i>Registrar Empresa</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="documentos.php"><i class="fas fa-file-alt"></i>Documentos</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="comisiones.php"><i class="fas fa-chart-line"></i>Mis Comisiones</a>
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
                                <div class="me-3"><i class="fas fa-building fa-3x"></i></div>
                                <div>
                                    <h4 class="card-title mb-1"><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></h4>
                                    <p class="card-text mb-0 opacity-75">
                                        <i class="fas fa-id-card me-1"></i>
                                        RFC: <?php echo htmlspecialchars($empresa['rfc'] ?? 'No especificado'); ?>
                                    </p>
                                </div>
                            </div>
                            <div>
                                <span class="badge bg-white text-dark p-3">
                                    <i class="fas fa-calendar me-2"></i>
                                    Registro: <?php echo formatearFechaSimple($empresa['fecha_creacion']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estado de Verificación -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h5 class="mb-0">Estado de Verificación</h5>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php echo claseEstado($empresa['estado_verificacion']); ?> badge-estado status-badge">
                                            <i class="fas fa-<?php 
                                                echo $empresa['estado_verificacion'] == 'aprobado' ? 'check-circle' : 
                                                    ($empresa['estado_verificacion'] == 'rechazado' ? 'times-circle' : 
                                                    ($empresa['estado_verificacion'] == 'en_revision' ? 'search' : 'hourglass-half')); 
                                            ?> me-2"></i>
                                            <?php echo textoEstado($empresa['estado_verificacion']); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if (!empty($empresa['observaciones_verificacion'])): ?>
                                    <div class="alert alert-info mt-3 mb-0">
                                        <i class="fas fa-comment me-2"></i>
                                        <strong>Observaciones:</strong> 
                                        <?php echo nl2br(htmlspecialchars($empresa['observaciones_verificacion'])); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($empresa['fecha_verificacion'])): ?>
                                    <div class="mt-2 text-muted">
                                        <small>
                                            <i class="fas fa-clock me-1"></i>
                                            Verificado el: <?php echo formatearFecha($empresa['fecha_verificacion']); ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Información de la Empresa -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-building me-2 text-success"></i>
                                    Información de la Empresa
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="info-section">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-label">Nombre Comercial</div>
                                            <div class="info-value"><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">Giro Comercial</div>
                                            <div class="info-value"><?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">RFC</div>
                                            <div class="info-value"><?php echo htmlspecialchars($empresa['rfc'] ?? 'No especificado'); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">Teléfono</div>
                                            <div class="info-value"><?php echo htmlspecialchars($empresa['telefono'] ?? 'No especificado'); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Email</div>
                                            <div class="info-value">
                                                <a href="mailto:<?php echo htmlspecialchars($empresa['email']); ?>">
                                                    <?php echo htmlspecialchars($empresa['email']); ?>
                                                </a>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Dirección</div>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($empresa['direccion'] ?? 'No especificada')); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">Nombre de Contacto</div>
                                            <div class="info-value"><?php echo htmlspecialchars($empresa['nombre_contacto']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                   

                <!-- Plan y Timbres -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-crown me-2 text-success"></i>
                                    Plan y Timbres
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php 
                                $plan_info = getPlanBadge($empresa['plan'] ?? 'prueba');
                                ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-label">Plan Contratado</div>
                                        <div class="info-value">
                                            <span class="badge <?php echo $plan_info[0]; ?> p-2">
                                                <?php echo $plan_info[1]; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-label">Timbres</div>
                                        <div class="info-value">
                                            <div class="d-flex align-items-center">
                                                <span class="badge bg-info p-2 me-2">
                                                    <?php echo $empresa['timbres_disponibles'] ?? 0; ?> disponibles
                                                </span>
                                                <span class="text-muted">
                                                    / <?php echo $empresa['timbres_totales'] ?? 0; ?> totales
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($empresa['fecha_activacion_timbres'])): ?>
                                        <div class="col-12 mt-3">
                                            <div class="info-label">Fecha Activación</div>
                                            <div class="info-value"><?php echo formatearFecha($empresa['fecha_activacion_timbres']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Documentos -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-file-alt me-2 text-success"></i>
                                    Documentos
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="documento-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-file-pdf text-danger me-2 fa-lg"></i>
                                            <strong>Constancia de Situación Fiscal</strong>
                                            <br>
                                            <small class="text-muted">
                                                Subido: <?php echo formatearFecha($empresa['fecha_subida_constancia'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <?php if (!empty($empresa['constancia_fiscal'])): ?>
                                            <button class="btn btn-sm btn-outline-primary ver-archivo"
                                                data-archivo="../Distribuidor/uploads/distribuidores/constancias/<?php echo $empresa['constancia_fiscal']; ?>"
                                                data-tipo="pdf"
                                                data-nombre="constancia_fiscal_<?php echo $empresa['id']; ?>.pdf"
                                                data-titulo="Constancia de Situación Fiscal - <?php echo htmlspecialchars($empresa['nombre_empresa']); ?>">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-warning">No subido</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="documento-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-id-card text-primary me-2 fa-lg"></i>
                                            <strong>Credencial de Identificación</strong>
                                            <br>
                                            <small class="text-muted">
                                                Subido: <?php echo formatearFecha($empresa['fecha_subida_credencial'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <?php if (!empty($empresa['credencial_identificacion'])): ?>
                                            <?php
                                            $ext = pathinfo($empresa['credencial_identificacion'], PATHINFO_EXTENSION);
                                            $tipo = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif']) ? 'imagen' : 'pdf';
                                            ?>
                                            <button class="btn btn-sm btn-outline-primary ver-archivo"
                                                data-archivo="../Distribuidor/uploads/distribuidores/credenciales/<?php echo $empresa['credencial_identificacion']; ?>"
                                                data-tipo="<?php echo $tipo; ?>"
                                                data-nombre="credencial_<?php echo $empresa['id']; ?>.<?php echo $ext; ?>"
                                                data-titulo="Credencial de Identificación - <?php echo htmlspecialchars($empresa['nombre_empresa']); ?>">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-warning">No subido</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($empresa['declaracion_veracidad']): ?>
                                    <div class="alert alert-success mt-3 mb-0">
                                        <i class="fas fa-check-circle me-2"></i>
                                        Declaración de veracidad aceptada
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Información Adicional -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2 text-success"></i>
                                    Información Adicional
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="info-label">ID de Empresa</div>
                                        <div class="info-value"><?php echo $empresa['id']; ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="info-label">Fecha de Creación</div>
                                        <div class="info-value"><?php echo formatearFecha($empresa['fecha_creacion']); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="info-label">Fecha de Vencimiento</div>
                                        <div class="info-value"><?php echo formatearFechaSimple($empresa['fecha_vencimiento'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="info-label">Estado</div>
                                        <div class="info-value">
                                            <?php if ($empresa['activo']): ?>
                                                <span class="badge bg-success">Activo</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactivo</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($empresa['facturapi_organization_id'])): ?>
                                        <div class="col-12 mt-3">
                                            <div class="info-label">ID Organización FacturaPI</div>
                                            <div class="info-value">
                                                <code><?php echo htmlspecialchars($empresa['facturapi_organization_id']); ?></code>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between">
                            <a href="mis-empresas.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Volver al Listado
                            </a>
                            <div>
                                <?php if ($empresa['estado_verificacion'] != 'aprobado'): ?>
                                    <button class="btn btn-warning me-2" disabled>
                                        <i class="fas fa-edit me-2"></i>Editar (Próximamente)
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para ver archivos -->
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
                            <div class="spinner-border text-success mb-3"></div>
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
                    <a href="#" id="descargarArchivo" class="btn btn-success" download>
                        <i class="fas fa-download me-1"></i>Descargar
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function openSidebar() {
            sidebar.classList.add('show');
            sidebarBackdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('show');
            sidebarBackdrop.classList.remove('show');
            document.body.style.overflow = '';
        }

        sidebarToggle?.addEventListener('click', () => {
            if (sidebar.classList.contains('show')) closeSidebar();
            else openSidebar();
        });

        sidebarBackdrop?.addEventListener('click', closeSidebar);

        // Touch swipe for sidebar
        let touchStartX = 0;
        document.addEventListener('touchstart', (e) => {
            if (window.innerWidth >= 768) return;
            touchStartX = e.touches[0].clientX;
        });

        document.addEventListener('touchend', (e) => {
            if (window.innerWidth >= 768) return;
            const touchEndX = e.changedTouches[0].clientX;
            const deltaX = touchEndX - touchStartX;

            if (deltaX > 50 && touchStartX < 30 && !sidebar.classList.contains('show')) {
                openSidebar();
            } else if (deltaX < -50 && sidebar.classList.contains('show')) {
                closeSidebar();
            }
        });

        // Close sidebar on link click in mobile
        document.querySelectorAll('#sidebar .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) closeSidebar();
            });
        });

        // Close sidebar on window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) closeSidebar();
        });

        // =============================================
        // VISOR DE ARCHIVOS
        // =============================================
        window.abrirArchivoModal = function(rutaArchivo, tipoArchivo, nombreArchivo, titulo) {
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

            modalTitulo.textContent = titulo;
            descargarBtn.href = rutaArchivo;
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
                        imagenVisor.src = rutaArchivo;
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
                    img.src = rutaArchivo;

                } else if (tipoArchivo === 'pdf') {
                    pdfVisor.src = rutaArchivo + '#view=fitH';
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
    </script>
</body>
</html>