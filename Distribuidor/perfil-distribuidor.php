<?php
session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['distribuidor_id'])) {
    header("Location: login-distribuidor.php");
    exit;
}

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

// Procesar actualización de perfil
$mensaje = '';
$mensaje_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Actualizar datos personales
        if ($_POST['action'] === 'actualizar_perfil') {
            $nombre = trim($_POST['nombre_distribuidor']);
            $telefono = trim($_POST['telefono']);
            $email = trim($_POST['email']);
            $rfc = trim($_POST['rfc']);
            $banco = trim($_POST['banco']);
            $numero_cuenta = trim($_POST['numero_cuenta']);
            
            // Validaciones básicas
            $errores = [];
            if (empty($nombre)) $errores[] = "El nombre es requerido";
            if (empty($telefono)) $errores[] = "El teléfono es requerido";
            if (empty($email)) $errores[] = "El email es requerido";
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = "Email no válido";
            
            if (empty($errores)) {
                $sql = "UPDATE distribuidores SET 
                        nombre_distribuidor = ?,
                        telefono = ?,
                        email = ?,
                        rfc = ?,
                        banco = ?,
                        numero_cuenta = ?
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssi", $nombre, $telefono, $email, $rfc, $banco, $numero_cuenta, $distribuidor_id);
                
                if ($stmt->execute()) {
                    $mensaje = "Perfil actualizado correctamente";
                    $mensaje_tipo = "success";
                    // Actualizar sesión
                    $_SESSION['distribuidor_nombre'] = $nombre;
                } else {
                    $mensaje = "Error al actualizar: " . $conn->error;
                    $mensaje_tipo = "danger";
                }
                $stmt->close();
            } else {
                $mensaje = implode(", ", $errores);
                $mensaje_tipo = "danger";
            }
        }
        
        // Cambiar contraseña
        if ($_POST['action'] === 'cambiar_password') {
            $password_actual = $_POST['password_actual'];
            $password_nueva = $_POST['password_nueva'];
            $password_confirmar = $_POST['password_confirmar'];
            
            // Verificar contraseña actual
            $sql = "SELECT password FROM distribuidores WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $distribuidor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $usuario = $result->fetch_assoc();
            $stmt->close();
            
            $errores = [];
            if (empty($password_actual)) $errores[] = "La contraseña actual es requerida";
            if (empty($password_nueva)) $errores[] = "La nueva contraseña es requerida";
            if ($password_nueva !== $password_confirmar) $errores[] = "Las contraseñas nuevas no coinciden";
            if (strlen($password_nueva) < 6) $errores[] = "La nueva contraseña debe tener al menos 6 caracteres";
            
            // Verificar si la contraseña actual es correcta
            if (empty($errores) && !password_verify($password_actual, $usuario['password'])) {
                $errores[] = "La contraseña actual es incorrecta";
            }
            
            if (empty($errores)) {
                $password_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                $sql = "UPDATE distribuidores SET password = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $password_hash, $distribuidor_id);
                
                if ($stmt->execute()) {
                    $mensaje = "Contraseña actualizada correctamente";
                    $mensaje_tipo = "success";
                } else {
                    $mensaje = "Error al actualizar contraseña: " . $conn->error;
                    $mensaje_tipo = "danger";
                }
                $stmt->close();
            } else {
                $mensaje = implode(", ", $errores);
                $mensaje_tipo = "danger";
            }
        }
        
        // Actualizar después de cambios
        $sql = "SELECT * FROM distribuidores WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $distribuidor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $distribuidor = $result->fetch_assoc();
        $stmt->close();
    } else {
        // Obtener información del distribuidor
        $sql = "SELECT * FROM distribuidores WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $distribuidor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $distribuidor = $result->fetch_assoc();
        $stmt->close();
    }
} else {
    // Obtener información del distribuidor
    $sql = "SELECT * FROM distribuidores WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $distribuidor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $distribuidor = $result->fetch_assoc();
    $stmt->close();
}

// Verificar si se encontró el distribuidor
if (!$distribuidor) {
    session_destroy();
    header("Location: login-distribuidor.php?error=distribuidor_no_encontrado");
    exit;
}

// Función para formatear fecha
function formatearFechaRegistro($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00' || $fecha == '0000-00-00') {
        return 'No registrada';
    }
    $timestamp = strtotime($fecha);
    if ($timestamp === false) {
        return 'Fecha inválida';
    }
    return date('d/m/Y', $timestamp);
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

$constancia_subida = !empty($distribuidor['constancia_fiscal']);
$credencial_subida = !empty($distribuidor['credencial_identificacion']);
$estado_clase = claseEstado($distribuidor['estado_verificacion'] ?? 'pendiente');
$estado_texto = textoEstado($distribuidor['estado_verificacion'] ?? 'pendiente');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Perfil del Distribuidor - Libertyfin</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #27ae60;
            --primary-dark: #219a52;
            --secondary-color: #2ecc71;
            --accent-color: #3498db;
            --dark-bg: #1a2634;
            --gray-bg: #f8fafc;
            --card-shadow: 0 10px 40px rgba(0,0,0,0.08);
            --card-shadow-hover: 0 20px 50px rgba(0,0,0,0.12);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* Navbar mejorado */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 4px 20px rgba(39,174,96,0.2);
            padding: 0.8rem 1rem;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: -0.3px;
        }

        .img-logo-navbar {
            height: 36px;
            width: auto;
            filter: brightness(0) invert(1);
        }

        /* Sidebar moderno */
        .sidebar {
            background: linear-gradient(180deg, #1e2a36 0%, #1a2530 100%);
            color: white;
            min-height: calc(100vh - 70px);
            transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            will-change: transform;
            box-shadow: 2px 0 20px rgba(0,0,0,0.1);
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 4px 12px;
            border-radius: 12px;
            transition: var(--transition-smooth);
            font-weight: 500;
            position: relative;
        }

        .sidebar .nav-link:hover {
            background: rgba(46, 204, 113, 0.15);
            color: white;
            transform: translateX(5px);
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(39,174,96,0.3);
        }

        .sidebar .nav-link i {
            width: 24px;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        /* Tarjetas modernas */
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        /* Botón hamburguesa */
        .sidebar-toggle {
            display: none;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem 0.8rem;
            margin-right: 1rem;
            border-radius: 12px;
            transition: var(--transition-smooth);
        }

        .sidebar-toggle:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Responsive */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 70px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 70px);
                z-index: 1050;
                overflow-y: auto;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
            }
        }

        /* Badges */
        .badge-estado {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Documentos */
        .documento-item {
            padding: 16px;
            border: 1px solid #e9ecef;
            border-radius: 16px;
            margin-bottom: 12px;
            transition: var(--transition-smooth);
            background: white;
        }

        .documento-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 5px 20px rgba(39,174,96,0.1);
            transform: translateX(5px);
        }

        /* Información de perfil */
        .info-card-item {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: var(--transition-smooth);
        }

        .info-card-item:hover {
            background: white;
            box-shadow: var(--card-shadow);
            transform: translateX(5px);
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            word-break: break-word;
        }

        .section-title {
            border-left: 4px solid var(--primary-color);
            padding-left: 1rem;
            margin: 1.5rem 0 1rem 0;
            font-weight: 700;
            font-size: 1.25rem;
        }

        /* Modal mejorado */
        .modal-content {
            border-radius: 24px;
            border: none;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 1.25rem;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            padding: 0.75rem 1rem;
            transition: var(--transition-smooth);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39,174,96,0.1);
        }

        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: #495057;
        }

        /* Botones */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition-smooth);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
        }

        .btn-outline-primary {
            border-radius: 12px;
            border-color: var(--primary-color);
            color: var(--primary-color);
            transition: var(--transition-smooth);
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }

        /* Animaciones */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card, .info-card-item {
            animation: fadeInUp 0.4s ease-out forwards;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }

        /* Alertas */
        .alert {
            border-radius: 16px;
            border: none;
            animation: fadeInUp 0.3s ease-out;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle" aria-label="Abrir menú">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../images/LibertyfinBlanco.webp" alt="LibertyFin" class="me-2 img-logo-navbar">
                <span>Mi Perfil</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-2"></i>
                        <span><?php echo htmlspecialchars($_SESSION['distribuidor_nombre'] ?? $distribuidor['nombre_distribuidor']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="perfil-distribuidor.php"><i class="fas fa-user me-2"></i>Mi Perfil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="cerrar-sesion.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
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
                <div class="position-sticky pt-4">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="panel-distribuidor.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="perfil-distribuidor.php">
                                <i class="fas fa-user-cog"></i>
                                Perfil
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="mis-empresas.php">
                                <i class="fas fa-building"></i>
                                Empresas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="nueva-empresa.php">
                                <i class="fas fa-plus-circle"></i>
                                Registrar Empresa
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="comisiones.php">
                                <i class="fas fa-chart-line"></i>
                                Comisiones
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                
                <!-- Mensaje de alerta -->
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show mb-4" role="alert">
                        <i class="fas <?php echo $mensaje_tipo == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Estado de Verificación -->
                <div class="card mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="display-6 mb-0 text-<?php echo $estado_clase; ?>">
                                    <i class="fas <?php echo $estado_clase == 'success' ? 'fa-check-circle' : ($estado_clase == 'warning' ? 'fa-clock' : ($estado_clase == 'info' ? 'fa-search' : 'fa-times-circle')); ?>"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-1">Estado de verificación: <span class="badge bg-<?php echo $estado_clase; ?> badge-estado"><?php echo $estado_texto; ?></span></h5>
                                <?php if (!empty($distribuidor['observaciones_verificacion'])): ?>
                                    <p class="mb-0 text-muted small"><?php echo htmlspecialchars($distribuidor['observaciones_verificacion']); ?></p>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#modalEditarPerfil">
                                <i class="fas fa-edit me-2"></i>Editar Perfil
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Información Personal -->
                <h3 class="section-title">Información Personal</h3>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="info-card-item">
                            <div class="info-label">
                                <i class="fas fa-id-card me-1 text-success"></i> Número de Control
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($distribuidor['numero_control']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card-item">
                            <div class="info-label">
                                <i class="fas fa-user me-1 text-success"></i> Nombre Completo
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($distribuidor['nombre_distribuidor']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card-item">
                            <div class="info-label">
                                <i class="fas fa-file-alt me-1 text-success"></i> RFC
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($distribuidor['rfc']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card-item">
                            <div class="info-label">
                                <i class="fas fa-phone me-1 text-success"></i> Teléfono
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($distribuidor['telefono']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="info-card-item">
                            <div class="info-label">
                                <i class="fas fa-envelope me-1 text-success"></i> Email
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($distribuidor['email']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Información Bancaria -->
                <h3 class="section-title">Información Bancaria</h3>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="info-card-item">
                            <div class="info-label">
                                <i class="fas fa-university me-1 text-success"></i> Banco
                            </div>
                            <div class="info-value"><?php echo !empty($distribuidor['banco']) ? htmlspecialchars($distribuidor['banco']) : '<span class="text-muted">No registrado</span>'; ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card-item">
                            <div class="info-label">
                                <i class="fas fa-credit-card me-1 text-success"></i> Número de Cuenta
                            </div>
                            <div class="info-value"><?php echo !empty($distribuidor['numero_cuenta']) ? htmlspecialchars($distribuidor['numero_cuenta']) : '<span class="text-muted">No registrado</span>'; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Documentos -->
                <h3 class="section-title">Documentos</h3>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white border-0 pt-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-file-pdf me-2 text-danger"></i>
                                    Constancia Fiscal
                                </h5>
                            </div>
                            <div class="card-body pt-0">
                                <?php if ($constancia_subida): ?>
                                    <div class="documento-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-check-circle text-success me-2"></i>
                                            <span><?php echo basename($distribuidor['constancia_fiscal']); ?></span>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Subida: <?php echo formatearFechaRegistro($distribuidor['fecha_subida_constancia']); ?>
                                            </small>
                                        </div>
                                        <div>
                                            <button class="btn btn-sm btn-outline-success ver-archivo accion-btn" 
                                                    data-archivo="../Distribuidor/uploads/distribuidores/constancias/<?php echo $distribuidor['constancia_fiscal']; ?>" 
                                                    data-tipo="pdf"
                                                    data-nombre="<?php echo basename($distribuidor['constancia_fiscal']); ?>"
                                                    data-titulo="Constancia Fiscal">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">
                                        <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                        No se ha subido la constancia fiscal
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white border-0 pt-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-id-card me-2 text-primary"></i>
                                    Credencial de Identificación
                                </h5>
                            </div>
                            <div class="card-body pt-0">
                                <?php if ($credencial_subida): ?>
                                    <div class="documento-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-check-circle text-success me-2"></i>
                                            <span><?php echo basename($distribuidor['credencial_identificacion']); ?></span>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Subida: <?php echo formatearFechaRegistro($distribuidor['fecha_subida_credencial']); ?>
                                            </small>
                                        </div>
                                        <div>
                                            <?php
                                            $ext = pathinfo($distribuidor['credencial_identificacion'], PATHINFO_EXTENSION);
                                            $tipo = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif']) ? 'imagen' : 'pdf';
                                            ?>
                                            <button class="btn btn-sm btn-outline-success ver-archivo accion-btn" 
                                                    data-archivo="../Distribuidor/uploads/distribuidores/credenciales/<?php echo $distribuidor['credencial_identificacion']; ?>" 
                                                    data-tipo="<?php echo $tipo; ?>"
                                                    data-nombre="<?php echo basename($distribuidor['credencial_identificacion']); ?>"
                                                    data-titulo="Credencial de Identificación">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">
                                        <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                        No se ha subido la credencial de identificación
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fecha de Registro -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body text-center py-3">
                                <small class="text-muted">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    Cuenta creada el <?php echo formatearFechaRegistro($distribuidor['fecha_registro']); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- MODAL EDITAR PERFIL -->
    <div class="modal fade" id="modalEditarPerfil" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-user-edit me-2"></i>Editar Perfil
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="formEditarPerfil">
                    <input type="hidden" name="action" value="actualizar_perfil">
                    <div class="modal-body">
                        <!-- Pestañas -->
                        <ul class="nav nav-tabs mb-4" id="perfilTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="datos-tab" data-bs-toggle="tab" data-bs-target="#datos" type="button" role="tab">
                                    <i class="fas fa-user me-2"></i>Datos Personales
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="bancarios-tab" data-bs-toggle="tab" data-bs-target="#bancarios" type="button" role="tab">
                                    <i class="fas fa-university me-2"></i>Datos Bancarios
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                                    <i class="fas fa-key me-2"></i>Cambiar Contraseña
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <!-- Datos Personales -->
                            <div class="tab-pane fade show active" id="datos" role="tabpanel">
                                <div class="mb-3">
                                    <label class="form-label">Nombre Completo *</label>
                                    <input type="text" class="form-control" name="nombre_distribuidor" 
                                           value="<?php echo htmlspecialchars($distribuidor['nombre_distribuidor']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">RFC</label>
                                    <input type="text" class="form-control" name="rfc" 
                                           value="<?php echo htmlspecialchars($distribuidor['rfc']); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Teléfono *</label>
                                    <input type="tel" class="form-control" name="telefono" 
                                           value="<?php echo htmlspecialchars($distribuidor['telefono']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email *</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($distribuidor['email']); ?>" required>
                                </div>
                            </div>
                            
                            <!-- Datos Bancarios -->
                            <div class="tab-pane fade" id="bancarios" role="tabpanel">
                                <div class="mb-3">
                                    <label class="form-label">Banco</label>
                                    <input type="text" class="form-control" name="banco" 
                                           value="<?php echo htmlspecialchars($distribuidor['banco'] ?? ''); ?>" 
                                           placeholder="Ej: BBVA, Santander, etc.">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Número de Cuenta</label>
                                    <input type="text" class="form-control" name="numero_cuenta" 
                                           value="<?php echo htmlspecialchars($distribuidor['numero_cuenta'] ?? ''); ?>" 
                                           placeholder="Número de cuenta o CLABE">
                                </div>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Estos datos serán utilizados para el pago de comisiones.
                                </div>
                            </div>
                            
                            <!-- Cambiar Contraseña -->
                            <div class="tab-pane fade" id="password" role="tabpanel">
                                <div class="mb-3">
                                    <label class="form-label">Contraseña Actual *</label>
                                    <input type="password" class="form-control" name="password_actual" id="password_actual">
                                    <small class="text-muted">Ingresa tu contraseña actual para confirmar cambios</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Nueva Contraseña *</label>
                                    <input type="password" class="form-control" name="password_nueva" id="password_nueva">
                                    <small class="text-muted">Mínimo 6 caracteres</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirmar Nueva Contraseña *</label>
                                    <input type="password" class="form-control" name="password_confirmar" id="password_confirmar">
                                </div>
                                <div id="passwordError" class="text-danger small mt-2" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4" id="btnGuardar">
                            <i class="fas fa-save me-2"></i>Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
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
                            <div class="spinner-border text-success mb-3" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
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
                    <a href="#" id="descargarArchivo" class="btn btn-success rounded-pill px-4" download>
                        <i class="fas fa-download me-1"></i>Descargar
                    </a>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // =============================================
        // FUNCIONALIDAD DE SWIPE AUTOMÁTICO PARA SIDEBAR
        // =============================================

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

        function isInsideTable(element) {
            while (element) {
                if (element.classList && element.classList.contains('table-responsive')) {
                    return true;
                }
                if (element.classList && element.classList.contains('table')) {
                    return true;
                }
                if (element.tagName === 'TD' || element.tagName === 'TH' || element.tagName === 'TR' ||
                    element.tagName === 'TBODY' || element.tagName === 'THEAD') {
                    return true;
                }
                element = element.parentElement;
            }
            return false;
        }

        document.addEventListener('touchstart', function(e) {
            if (window.innerWidth >= 768) return;

            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;

            if (touchX <= SWIPE_EDGE_ZONE && !isInsideTable(e.target)) {
                isSidebarTouch = true;
                touchStartX = touchX;
                touchStartY = touchY;
                touchEndX = touchStartX;
                touchEndY = touchStartY;
            }
        }, {
            passive: true
        });

        document.addEventListener('touchmove', function(e) {
            if (window.innerWidth >= 768) return;

            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;

            if (isSidebarTouch) {
                touchEndX = touchX;
                touchEndY = touchY;

                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                    e.preventDefault();
                }
            }
        }, {
            passive: false
        });

        document.addEventListener('touchend', function(e) {
            if (window.innerWidth >= 768) return;

            if (isSidebarTouch) {
                isSidebarTouch = false;

                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
                    return;
                }

                const sidebar = document.getElementById('sidebar');
                const isSidebarOpen = sidebar && sidebar.classList.contains('show');

                if (deltaX > SWIPE_THRESHOLD) {
                    if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                        openSidebarAuto();
                    }
                } else if (deltaX < -SWIPE_THRESHOLD) {
                    if (isSidebarOpen) {
                        closeSidebarAuto();
                    }
                }
            }
        }, {
            passive: true
        });

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
        });

        // =============================================
        // VALIDACIÓN DE CONTRASEÑA EN MODAL
        // =============================================
        
        const formEditar = document.getElementById('formEditarPerfil');
        if (formEditar) {
            formEditar.addEventListener('submit', function(e) {
                const passwordTab = document.getElementById('password-tab');
                const passwordFields = document.getElementById('password');
                const passwordActual = document.getElementById('password_actual');
                const passwordNueva = document.getElementById('password_nueva');
                const passwordConfirmar = document.getElementById('password_confirmar');
                const passwordError = document.getElementById('passwordError');
                
                // Verificar si se está intentando cambiar contraseña
                const isPasswordTabActive = passwordTab && passwordTab.classList.contains('active');
                
                if (isPasswordTabActive) {
                    // Si la pestaña de contraseña está activa, validar campos
                    if (!passwordActual.value || !passwordNueva.value || !passwordConfirmar.value) {
                        e.preventDefault();
                        passwordError.style.display = 'block';
                        passwordError.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> Todos los campos de contraseña son requeridos';
                        return false;
                    }
                    
                    if (passwordNueva.value !== passwordConfirmar.value) {
                        e.preventDefault();
                        passwordError.style.display = 'block';
                        passwordError.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> Las contraseñas nuevas no coinciden';
                        return false;
                    }
                    
                    if (passwordNueva.value.length < 6) {
                        e.preventDefault();
                        passwordError.style.display = 'block';
                        passwordError.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> La nueva contraseña debe tener al menos 6 caracteres';
                        return false;
                    }
                }
                
                passwordError.style.display = 'none';
                return true;
            });
        }

        // =============================================
        // FUNCIONALIDAD PARA EL VISOR DE ARCHIVOS
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