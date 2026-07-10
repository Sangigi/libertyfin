<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Verificar que sucursal_id esté definido
if (!isset($_SESSION['sucursal_id']) || !isset($_SESSION['usuario_id'])) {
    die("Error: Datos de sesión incompletos. Por favor, inicie sesión nuevamente.");
}

// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$servername_main = "libertyfin.com.mx";
$username_main = "juanc141_alexis";
$password_main = "Alexis1997";
$dbname_main = "juanc141_ventas";

$conn_main = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

// Obtener el plan de la empresa
$empresa_plan = "prueba"; // Valor por defecto
$empresa_id = $_SESSION['empresa_id'] ?? 0;

if ($conn_main) {
    $sql_plan = "SELECT plan FROM empresas WHERE id = ?";
    $stmt_plan = $conn_main->prepare($sql_plan);
    $stmt_plan->bind_param("i", $empresa_id);
    $stmt_plan->execute();
    $result_plan = $stmt_plan->get_result();
    if ($result_plan->num_rows > 0) {
        $plan_data = $result_plan->fetch_assoc();
        $empresa_plan = $plan_data['plan'];
    }
    $stmt_plan->close();
    $conn_main->close();
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Conectar a la base de datos de la empresa
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Verificar si ya existe una caja abierta para este usuario/sucursal
    $sql_caja_abierta = "SELECT id FROM caja WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierta'";
    $stmt = $conn->prepare($sql_caja_abierta);
    $stmt->bind_param("ii", $_SESSION['usuario_id'], $_SESSION['sucursal_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // CORREGIDO: Redirigir a caja.php si ya hay caja abierta
        $_SESSION['success_message'] = "Caja ya está abierta";
        header("Location: caja.php");
        exit();
    }

    // Obtener información de la empresa para el header
    $sql_config = "SELECT nombre_empresa,logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch_assoc();

    // OBTENER NOMBRE DE LA SUCURSAL
    $nombre_sucursal = "Sucursal ID: " . $_SESSION['sucursal_id']; // Valor por defecto
    $sql_sucursal = "SELECT nombre FROM sucursales WHERE id = ?";
    $stmt_sucursal = $conn->prepare($sql_sucursal);
    $stmt_sucursal->bind_param("i", $_SESSION['sucursal_id']);
    $stmt_sucursal->execute();
    $result_sucursal = $stmt_sucursal->get_result();
    if ($result_sucursal->num_rows > 0) {
        $sucursal_data = $result_sucursal->fetch_assoc();
        $nombre_sucursal = $sucursal_data['nombre'];
    }
    $stmt_sucursal->close();

    // OBTENER LOGO DE LA EMPRESA - COMO EN CAJA.PHP
    $logo_empresa = null;
    $logo_src_base64 = null;

    if (!empty($empresa_info['logo'])) {
        $empresa_logo = $empresa_info['logo'];
        $logo_path = '';
        $rutas_posibles = [
            $empresa_logo,
            '../' . $empresa_logo,
            '../../' . $empresa_logo,
            'admin/' . $empresa_logo,
            '../admin/' . $empresa_logo,
            'logos/' . $empresa_logo,
            'img/' . $empresa_logo,
            'images/' . $empresa_logo,
            'assets/' . $empresa_logo,
            'uploads/' . $empresa_logo,
            '../logos/' . $empresa_logo,
            '../img/' . $empresa_logo,
            '../images/' . $empresa_logo,
            '../assets/' . $empresa_logo,
            '../uploads/' . $empresa_logo
        ];

        foreach ($rutas_posibles as $ruta) {
            if (file_exists($ruta) && is_file($ruta)) {
                $logo_path = $ruta;
                break;
            }
        }

        // Si encontramos el logo, convertirlo a base64
        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;

            // Obtener la extensión del archivo
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));

            // Verificar que sea una imagen válida
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                // Leer el archivo y convertirlo a base64
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Procesar apertura de caja
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto_apertura = floatval($_POST['monto_apertura']);
    $observaciones = trim($_POST['observaciones']);

    if ($monto_apertura >= 0) {
        $sql = "INSERT INTO caja (sucursal_id, usuario_id, monto_apertura, observaciones, estado) 
                VALUES (?, ?, ?, ?, 'abierta')";
        $stmt = $conn->prepare($sql);

        // Debug: Verificar valores antes de ejecutar
        $sucursal_id = $_SESSION['sucursal_id'];
        $usuario_id = $_SESSION['usuario_id'];

        if (empty($sucursal_id) || empty($usuario_id)) {
            $error = "Error: Datos de usuario o sucursal no válidos.";
        } else {
            $stmt->bind_param("iids", $sucursal_id, $usuario_id, $monto_apertura, $observaciones);

            if ($stmt->execute()) {
                // DEBUG: Verificar que se creó el registro
                $nuevo_id = $conn->insert_id;
                error_log("Caja abierta - ID: $nuevo_id, Usuario: $usuario_id, Sucursal: $sucursal_id, Monto: $monto_apertura");

                $_SESSION['success_message'] = "Caja abierta correctamente con $" . number_format($monto_apertura, 2);
                header("Location: caja.php");
                exit();
            } else {
                $error = "Error al abrir la caja: " . $conn->error;
                // DEBUG: Log del error
                error_log("Error apertura caja: " . $conn->error);
            }
        }
    } else {
        $error = "El monto de apertura debe ser mayor o igual a 0";
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Apertura de Caja - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        * {
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        /* Navbar estilos mejorados para móvil */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 0.5rem 1rem;
        }

        .navbar-brand {
            font-size: 1rem;
        }

        .navbar-brand img {
            height: 35px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .navbar-toggler {
            padding: 0.25rem 0.5rem;
            font-size: 1rem;
            border: none;
            outline: none;
        }

        .navbar-toggler:focus {
            box-shadow: none;
        }

        /* Estilos para el menú móvil */
        @media (max-width: 991.98px) {
            .navbar-brand {
                font-size: 0.9rem;
            }
            
            .navbar-brand img {
                max-height: 30px !important;
            }
            
            .user-info-mobile {
                border-top: 1px solid rgba(255,255,255,0.2);
                margin-top: 0.5rem;
                padding-top: 0.5rem;
            }
            
            .user-info-mobile .badge {
                font-size: 0.85rem;
                padding: 0.35rem 0.65rem;
                background-color: #f8f9fa !important;
                color: #212529 !important;
            }
            
            .user-info-mobile hr {
                border-color: rgba(255,255,255,0.2);
                margin: 0.5rem 0;
            }
            
            .user-info-mobile .btn {
                font-size: 0.9rem;
                padding: 0.5rem;
                margin-top: 0.25rem;
            }
            
            .user-info-mobile div {
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .navbar-brand span {
                font-size: 0.85rem;
            }
            
            .navbar-brand img {
                max-height: 25px !important;
            }
            
            .navbar {
                padding: 0.5rem;
            }
        }

        /* Ajustes para tabletas */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .user-info-mobile .btn {
                width: auto !important;
                align-self: flex-start;
            }
        }

        .apertura-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .apertura-container {
                margin: 20px auto;
                padding: 15px;
            }
        }

        .money-input {
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        @media (max-width: 768px) {
            .money-input {
                font-size: 1.2rem;
                padding: 12px;
            }
        }

        .money-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            border-radius: 15px 15px 0 0 !important;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        @media (max-width: 768px) {
            .btn-primary {
                padding: 10px;
                font-size: 0.95rem;
            }
            
            .btn-primary:hover {
                transform: none;
            }
        }

        .info-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-left: 4px solid var(--primary-color);
        }

        .input-group-text {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: bold;
        }

        /* Mejoras para inputs táctiles */
        input, textarea, button, a {
            touch-action: manipulation;
        }

        input[type="number"] {
            -moz-appearance: textfield;
        }

        input[type="number"]::-webkit-inner-spin-button, 
        input[type="number"]::-webkit-outer-spin-button {
            opacity: 0.5;
        }
    </style>
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
</head>

<body>
    <!-- Navbar optimizado para móvil -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Logo y nombre de la empresa -->
            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span class="d-none d-sm-inline">
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span class="d-none d-sm-inline">
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                    <span class="d-none d-sm-inline">
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Botón hamburguesa para móvil -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMobileMenu" 
                    aria-controls="navbarMobileMenu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Menú colapsable para móvil -->
            <div class="collapse navbar-collapse" id="navbarMobileMenu">
                <div class="navbar-nav ms-auto align-items-center">
                    <!-- Información del usuario (visible en desktop) -->
                    <div class="user-info-desktop d-none d-lg-flex align-items-center">
                        <span class="navbar-text me-3">
                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                        </span>
                        <span class="badge bg-light text-dark me-3">
                            <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($nombre_sucursal); ?>
                        </span>
                        <a href="dashboard.php" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Dashboard
                        </a>
                    </div>

                    <!-- Información del usuario (visible en móvil) -->
                    <div class="user-info-mobile d-lg-none w-100">
                        <div class="d-flex flex-column align-items-start gap-2 py-2">
                            <div class="w-100">
                                <i class="fas fa-user me-2"></i>
                                <strong><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></strong>
                            </div>
                            <div class="w-100">
                                <i class="fas fa-store me-2"></i>
                                <span class="badge bg-light text-dark">
                                    <?php echo htmlspecialchars($nombre_sucursal); ?>
                                </span>
                            </div>
                            <hr class="w-100 my-2">
                            <a href="dashboard.php" class="btn btn-light btn-sm w-100">
                                <i class="fas fa-arrow-left me-2"></i>Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="apertura-container">
            <div class="card shadow">
                <div class="card-header text-white">
                    <h4 class="mb-0"><i class="fas fa-lock-open me-2"></i>Apertura de Caja</h4>
                </div>
                <div class="card-body p-4">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message'];
                                                                    unset($_SESSION['success_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Mostrar error si no hay sucursal_id -->
                    <?php if (!isset($_SESSION['sucursal_id']) || !isset($_SESSION['usuario_id'])): ?>
                        <div class="alert alert-danger">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Error de Configuración</h5>
                            <p class="mb-0">No se pudo determinar la sucursal o usuario. Por favor, cierre sesión y vuelva a iniciar.</p>
                            <div class="mt-2">
                                <a href="logout.php" class="btn btn-warning btn-sm">
                                    <i class="fas fa-sign-out-alt me-1"></i>Cerrar Sesión
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="formApertura">
                            <div class="mb-4">
                                <label for="monto_apertura" class="form-label fw-bold">Monto Inicial de Efectivo *</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text">$</span>
                                    <input type="number"
                                        class="form-control money-input"
                                        id="monto_apertura"
                                        name="monto_apertura"
                                        step="0.01"
                                        min="0"
                                        required
                                        value="0.00"
                                        placeholder="0.00"
                                        inputmode="decimal">
                                </div>
                                <div class="form-text text-muted mt-2">
                                    <i class="fas fa-info-circle me-1"></i>Ingrese el monto con el que inicia la caja para dar cambio
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="observaciones" class="form-label fw-bold">Observaciones (Opcional)</label>
                                <textarea class="form-control"
                                    id="observaciones"
                                    name="observaciones"
                                    rows="3"
                                    placeholder="Ej: Turno matutino, fondo para cambio, etc..."
                                    style="resize: none;"></textarea>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg py-3">
                                    <i class="fas fa-lock-open me-2"></i>Abrir Caja e Iniciar Ventas
                                </button>
                                <a href="dashboard.php" class="btn btn-outline-secondary">Cancelar y Volver al Dashboard</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tarjeta de Información -->
            <div class="card mt-4 info-card">
                <div class="card-body">
                    <h6 class="fw-bold text-primary mb-3">
                        <i class="fas fa-info-circle me-2"></i>Información Importante
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled small">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>La caja debe abrirse</strong> al inicio de cada turno
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>El monto inicial</strong> será el efectivo para cambio
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>Recuerde cerrar la caja</strong> al finalizar el turno
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled small">
                                <li class="mb-2">
                                    <i class="fas fa-store text-primary me-2"></i>
                                    <strong>Sucursal:</strong> <?php echo htmlspecialchars($nombre_sucursal); ?>
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-user text-primary me-2"></i>
                                    <strong>Usuario:</strong> <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tarjeta de Estado del Sistema -->
            <div class="card mt-3">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <small class="text-muted">
                            <i class="fas fa-sync-alt me-1"></i>Estado del sistema:
                            <span class="badge bg-success">Conectado</span>
                        </small>
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i><?php echo date('d/m/Y H:i:s'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const montoInput = document.getElementById('monto_apertura');
            const form = document.getElementById('formApertura');

            // Enfocar y seleccionar el input de monto
            if (montoInput) {
                // En móvil, no forzar el foco automático para evitar que el teclado se abra sin querer
                if (window.innerWidth > 768) {
                    montoInput.focus();
                }
                montoInput.select();

                // Formatear automáticamente al perder el foco
                montoInput.addEventListener('blur', function() {
                    if (this.value !== '') {
                        this.value = parseFloat(this.value).toFixed(2);
                    }
                });
            }

            // Validación del formulario
            if (form) {
                form.addEventListener('submit', function(e) {
                    const monto = parseFloat(montoInput.value);

                    if (monto < 0) {
                        e.preventDefault();
                        alert('El monto de apertura no puede ser negativo');
                        montoInput.focus();
                        return false;
                    }

                    if (isNaN(monto)) {
                        e.preventDefault();
                        alert('Por favor ingrese un monto válido');
                        montoInput.focus();
                        return false;
                    }

                    // Mostrar confirmación
                    if (!confirm(`¿Está seguro de abrir la caja con $${monto.toFixed(2)}?`)) {
                        e.preventDefault();
                        return false;
                    }

                    // Mostrar loading
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Abriendo caja...';
                        submitBtn.disabled = true;
                    }
                });
            }

            // Prevenir envío con Enter en el textarea
            const observaciones = document.getElementById('observaciones');
            if (observaciones) {
                observaciones.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                    }
                });
            }

            // Cerrar menú móvil automáticamente al hacer clic en un enlace
            const mobileMenuLinks = document.querySelectorAll('#navbarMobileMenu a');
            const navbarToggler = document.querySelector('.navbar-toggler');
            const mobileMenu = document.getElementById('navbarMobileMenu');
            
            if (mobileMenuLinks.length > 0 && navbarToggler) {
                mobileMenuLinks.forEach(link => {
                    link.addEventListener('click', () => {
                        if (window.innerWidth < 992 && mobileMenu.classList.contains('show')) {
                            navbarToggler.click();
                        }
                    });
                });
            }
        });
    </script>
</body>

</html>