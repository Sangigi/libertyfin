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

// Obtener información del distribuidor
$sql = "SELECT * FROM distribuidores WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $distribuidor_id);
$stmt->execute();
$result = $stmt->get_result();
$distribuidor = $result->fetch_assoc();
$stmt->close();

// Obtener giros comerciales para el select (con ID y nombre)
$giros_sql = "SELECT id, nombre FROM giro_comercial ORDER BY nombre";
$giros_result = $conn->query($giros_sql);
$giros = [];
while ($row = $giros_result->fetch_assoc()) {
    $giros[] = $row;
}

// Cerrar conexión temporal
$conn->close();

// ============================================
// SOLUCIÓN: FORZAR QUE EL CAMPO NO_DISTRIBUIDOR ESTÉ EN $_POST
// ============================================
// Si el formulario se envió pero no viene no_distribuidor en POST,
// lo agregamos manualmente desde la sesión
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['no_distribuidor']) || empty($_POST['no_distribuidor'])) {
        $_POST['no_distribuidor'] = $distribuidor['numero_control'] ?? '';
    }
}

// INCLUIR EL ARCHIVO DE PROCESAMIENTO
include '../registroEmpresa.php';

// Función para formatear fecha
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    $timestamp = strtotime($fecha);
    if ($timestamp === false) {
        return 'Fecha inválida';
    }
    return date('d/m/Y H:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Registrar Empresa - Libertyfin</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
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

        /* Welcome card premium */
        .welcome-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 24px;
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .welcome-card::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        /* Formulario mejorado */
        .form-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: var(--transition-smooth);
        }

        .form-section:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .form-section h5 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .form-section h5 i {
            margin-right: 0.5rem;
        }

        /* Campos de formulario */
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }

        .required:after {
            content: " *";
            color: #e74c3c;
            font-weight: bold;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 1.5px solid #e9ecef;
            padding: 0.75rem 1rem;
            transition: var(--transition-smooth);
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39,174,96,0.1);
            outline: none;
        }

        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #e74c3c;
            background-image: none;
        }

        .form-control.is-valid, .form-select.is-valid {
            border-color: var(--primary-color);
            background-image: none;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Información de archivos */
        .file-info {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 0.5rem;
            display: block;
        }

        .file-info.text-danger {
            color: #e74c3c !important;
        }

        /* Campo informativo */
        .info-field {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-left: 4px solid var(--primary-color);
            padding: 1rem 1.25rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            transition: var(--transition-smooth);
        }

        .info-field i {
            color: var(--primary-color);
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .info-field strong {
            color: #2c3e50;
        }

        /* Alertas */
        .alert {
            border-radius: 16px;
            border: none;
            animation: fadeInUp 0.3s ease-out;
        }

        /* Botones */
        .btn {
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition-smooth);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
        }

        .btn-secondary {
            background: #6c757d;
            border: none;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* Checkbox personalizado */
        .form-check-input {
            width: 1.2rem;
            height: 1.2rem;
            margin-top: 0.15rem;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .form-check-label {
            cursor: pointer;
            margin-left: 0.5rem;
        }

        /* Select2 personalizado */
        .select2-container--bootstrap-5 .select2-selection {
            border: 1.5px solid #e9ecef;
            border-radius: 12px;
            min-height: 50px;
            padding: 0.5rem;
        }

        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            padding-left: 0.5rem;
        }

        .select2-container--bootstrap-5 .select2-dropdown {
            border-radius: 12px;
            border-color: #e9ecef;
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

            .form-section {
                padding: 1rem;
            }
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

        .card, .form-section, .welcome-card {
            animation: fadeInUp 0.5s ease-out forwards;
        }

        /* Scrollbar personalizado */
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

        /* Spinner */
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.2em;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle" aria-label="Abrir menú">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../images/LibertyfinBlanco.webp" alt="LibertyFin" class="me-2 img-logo-navbar">
                <span>Registrar Empresa</span>
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
                            <a class="nav-link" href="perfil-distribuidor.php">
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
                            <a class="nav-link active" href="nueva-empresa.php">
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
                <!-- Welcome Card Premium -->
                <div class="card welcome-card mb-4">
                    <div class="card-body p-4 p-md-5">
                        <div class="d-flex align-items-center">
                            <div class="me-4">
                                <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                    <i class="fas fa-plus-circle fa-3x"></i>
                                </div>
                            </div>
                            <div>
                                <h2 class="card-title mb-2 fw-bold">Registrar Nueva Empresa</h2>
                                <p class="card-text mb-0 opacity-75">Complete todos los campos requeridos para registrar una nueva empresa en el sistema</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mensaje de alerta -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show mb-4" role="alert">
                        <i class="fas <?php echo $tipo_mensaje == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Formulario Principal -->
                <div class="card">
                    <div class="card-body p-4 p-md-5">
                        <form method="POST" enctype="multipart/form-data" id="registroEmpresaForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                            
                            <!-- Campo oculto con el número de control del distribuidor -->
                            <input type="hidden" name="no_distribuidor" value="<?php echo htmlspecialchars($distribuidor['numero_control'] ?? ''); ?>">
                            
                            <!-- Campo informativo del número de control -->
                            <div class="info-field">
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <strong>No. Control / No. Distribuidor:</strong> 
                                    <?php echo htmlspecialchars($distribuidor['numero_control'] ?? 'No disponible'); ?>
                                    <small class="d-block mt-1 text-muted">Este número se asignará automáticamente a la empresa como referencia del distribuidor</small>
                                </div>
                            </div>

                            <!-- Información de la Empresa -->
                            <div class="form-section">
                                <h5><i class="fas fa-building"></i>Información de la Empresa</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required">Nombre de la Empresa *</label>
                                        <input type="text" name="nombre_empresa" class="form-control" 
                                               value="<?php echo htmlspecialchars($_POST['nombre_empresa'] ?? ''); ?>" 
                                               required placeholder="Ingrese el nombre comercial">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required">Giro Comercial *</label>
                                        <select name="giro_comercial" class="form-select" required>
                                            <option value="">Seleccione un giro comercial</option>
                                            <?php foreach ($giros as $giro): ?>
                                                <option value="<?php echo $giro['id']; ?>" 
                                                    <?php echo (isset($_POST['giro_comercial']) && $_POST['giro_comercial'] == $giro['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($giro['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">RFC <small class="text-muted">(Opcional)</small></label>
                                        <input type="text" name="rfc" class="form-control" 
                                               value="<?php echo htmlspecialchars($_POST['rfc'] ?? ''); ?>"
                                               placeholder="Ej: ABC123456XYZ" maxlength="13">
                                        <small class="file-info">Si no cuenta con RFC, puede dejarlo en blanco</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label required">Teléfono *</label>
                                        <input type="tel" name="telefono" class="form-control" 
                                               value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>"
                                               required placeholder="Ej: (55) 1234-5678">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label required">Email Corporativo *</label>
                                        <input type="email" name="email" class="form-control" 
                                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                               required placeholder="correo@empresa.com">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label required">Dirección Fiscal *</label>
                                        <textarea name="direccion" class="form-control" rows="2" required
                                                  placeholder="Calle, número, colonia, ciudad, estado, código postal"><?php echo htmlspecialchars($_POST['direccion'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required">Nombre de Contacto *</label>
                                        <input type="text" name="nombre_contacto" class="form-control" 
                                               value="<?php echo htmlspecialchars($_POST['nombre_contacto'] ?? ''); ?>" 
                                               required placeholder="Nombre completo del contacto">
                                    </div>
                                </div>
                            </div>

                            <!-- Información del Administrador -->
                            <div class="form-section">
                                <h5><i class="fas fa-user-cog"></i>Información del Administrador</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required">Email del Administrador *</label>
                                        <input type="email" name="email_admin" class="form-control" 
                                               value="<?php echo htmlspecialchars($_POST['email_admin'] ?? ''); ?>" 
                                               required placeholder="admin@empresa.com">
                                        <small class="file-info">En este email recibirá las credenciales de acceso</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Contraseña del Administrador <small class="text-muted">(Opcional)</small></label>
                                        <input type="password" name="password_admin" class="form-control" 
                                               placeholder="Dejar en blanco para generar automáticamente">
                                        <small class="file-info">Si no se especifica, se generará una contraseña segura automáticamente</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Documentos -->
                            <div class="form-section">
                                <h5><i class="fas fa-file-alt"></i>Documentos</h5>
                                <div class="alert alert-info mb-3 border-0" style="background: #e8f5e9; border-radius: 12px;">
                                    <i class="fas fa-info-circle me-2 text-success"></i>
                                    <strong>Credencial de Identificación (INE/IFE)</strong> es obligatoria para completar el registro.
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Constancia de Situación Fiscal <small class="text-muted">(Opcional)</small></label>
                                        <input type="file" name="constancia_fiscal" class="form-control" accept=".pdf">
                                        <small class="file-info">Puede subir este documento más tarde si no lo tiene disponible ahora</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required">Credencial de Identificación (INE/IFE) *</label>
                                        <input type="file" name="credencial_identificacion" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                                        <small class="file-info text-danger">Este documento es obligatorio para completar el registro</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Declaración -->
                            <div class="mb-4 p-3" style="background: #f8f9fa; border-radius: 16px;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="declaracion_veracidad" id="declaracion" value="1" 
                                           <?php echo (isset($_POST['declaracion_veracidad']) && $_POST['declaracion_veracidad'] == '1') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="declaracion">
                                        Declaro bajo protesta de decir verdad que la información proporcionada es verídica y que los documentos anexados (si los hay) son legítimos.
                                    </label>
                                    <small class="text-danger d-block mt-2">* Requerida: Debe aceptar la declaración de veracidad.</small>
                                </div>
                            </div>

                            <!-- Botones de acción -->
                            <div class="d-flex justify-content-end gap-3">
                                <a href="mis-empresas.php" class="btn btn-secondary px-4">
                                    <i class="fas fa-times me-2"></i>Cancelar
                                </a>
                                <button type="submit" class="btn btn-success px-4" id="submitBtn">
                                    <span id="submitText">
                                        <i class="fas fa-save me-2"></i>Registrar Empresa
                                    </span>
                                    <span class="spinner-border spinner-border-sm d-none" id="submitSpinner"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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

        function isInsideForm(element) {
            while (element) {
                if (element.tagName === 'FORM') {
                    return true;
                }
                if (element.classList && element.classList.contains('form-section')) {
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

            if (touchX <= SWIPE_EDGE_ZONE && !isInsideForm(e.target)) {
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
        // VALIDACIÓN DEL FORMULARIO
        // =============================================

        const form = document.getElementById('registroEmpresaForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                let allValid = true;
                const requiredInputs = this.querySelectorAll('[required]');

                // Limpiar clases de validación
                requiredInputs.forEach(input => {
                    input.classList.remove('is-invalid');
                });

                // Validar cada campo requerido
                requiredInputs.forEach(input => {
                    if (input.type === 'file') {
                        if (input.files.length === 0) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    } else if (input.type === 'checkbox') {
                        if (!input.checked) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    } else if (input.tagName === 'SELECT') {
                        if (!input.value || input.value === '') {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    } else {
                        if (!input.value.trim()) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    }
                });

                if (!allValid) {
                    e.preventDefault();
                    const firstInvalid = document.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                } else {
                    // Mostrar spinner y deshabilitar botón
                    const submitBtn = document.getElementById('submitBtn');
                    const submitText = document.getElementById('submitText');
                    const submitSpinner = document.getElementById('submitSpinner');

                    submitText.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando...';
                    submitSpinner.classList.remove('d-none');
                    submitBtn.disabled = true;
                }
            });

            // Validación de archivos
            document.querySelectorAll('input[type="file"]').forEach(input => {
                input.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        const maxSize = 5 * 1024 * 1024; // 5MB

                        if (file.size > maxSize) {
                            alert(`El archivo "${file.name}" excede el tamaño máximo de 5MB`);
                            this.value = '';
                            this.classList.remove('is-valid');
                            this.classList.add('is-invalid');
                        } else {
                            this.classList.add('is-valid');
                            this.classList.remove('is-invalid');
                        }
                    } else {
                        this.classList.remove('is-valid');
                    }
                });
            });

            // Validación para RFC (mayúsculas)
            const rfcInput = document.querySelector('input[name="rfc"]');
            if (rfcInput) {
                rfcInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
        }

        // Inicializar Select2 con tema Bootstrap 5
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Seleccione una opción',
            dropdownParent: $('.card-body')
        });
    </script>
</body>
</html>