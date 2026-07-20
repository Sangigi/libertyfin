<?php
// =============================================
// LOGIN.PHP - Página de inicio de sesión
// =============================================

// Cargar configuración de sesión personalizada
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

// Iniciar sesión al principio
session_start();
date_default_timezone_set('America/Mexico_City');

// Cargar configuración de base de datos
require_once __DIR__ . '../../config/database.php';
require_once __DIR__ . '../../env_loader.php';

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario cuando se envía
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email'])) {
    // Sanitizar y validar datos
    $email = isset($_POST['email']) ? filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL) : '';
    $password_form = isset($_POST['password']) ? $_POST['password'] : '';
    $recordar = isset($_POST['recordar']) ? 1 : 0;
    
    // Validaciones básicas
    $errores = [];
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El email no es válido.";
    }
    
    if (empty($password_form)) {
        $errores[] = "La contraseña es obligatoria.";
    }
    
    // Si no hay errores, proceder con la autenticación
    if (empty($errores)) {
        try {
            // Crear conexión PDO
            $pdo = getDBConnection();
            
            // Buscar usuario por email
            $stmt = $pdo->prepare("SELECT id, nombre, apellidos, email, password, rol_usuario, activo, bloqueado, intentos_login FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();
            
            if ($usuario) {
                // Verificar si el usuario está activo
                if (!$usuario['activo']) {
                    $errores[] = "Tu cuenta está desactivada. Contacta al administrador.";
                } 
                // Verificar si el usuario está bloqueado
                elseif ($usuario['bloqueado'] == 1) {
                    $errores[] = "Tu cuenta ha sido bloqueada por múltiples intentos fallidos. Contacta al administrador.";
                }
                // Verificar contraseña
                elseif (password_verify($password_form, $usuario['password'])) {
                    // Iniciar sesión
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['usuario_nombre'] = $usuario['nombre'] . ' ' . $usuario['apellidos'];
                    $_SESSION['usuario_email'] = $usuario['email'];
                    $_SESSION['usuario_rol'] = $usuario['rol_usuario'];
                    $_SESSION['logged_in'] = true;
                    
                    // Si el usuario quiere recordar la sesión
                    if ($recordar) {
                        // Crear cookie que expire en 30 días
                        setcookie('usuario_email', $email, time() + (30 * 24 * 60 * 60), "/");
                    }
                    
                    // Registrar último acceso y resetear intentos
                    $stmt_update = $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW(), intentos_login = 0 WHERE id = ?");
                    $stmt_update->execute([$usuario['id']]);
                    
                    // Redirigir al dashboard
                    header("Location: Inicio");
                    exit();
                } else {
                    // Contraseña incorrecta, incrementar intentos
                    $nuevos_intentos = ($usuario['intentos_login'] ?? 0) + 1;
                    $stmt_update = $pdo->prepare("UPDATE usuarios SET intentos_login = ? WHERE id = ?");
                    $stmt_update->execute([$nuevos_intentos, $usuario['id']]);
                    
                    // Verificar si se debe bloquear la cuenta (más de 5 intentos)
                    if ($nuevos_intentos >= 5) {
                        $stmt_block = $pdo->prepare("UPDATE usuarios SET activo = 0, bloqueado = 1 WHERE id = ?");
                        $stmt_block->execute([$usuario['id']]);
                        $errores[] = "Tu cuenta ha sido bloqueada por múltiples intentos fallidos. Contacta al administrador.";
                    } else {
                        $intentos_restantes = 5 - $nuevos_intentos;
                        $errores[] = "Contraseña incorrecta. Te quedan $intentos_restantes intentos antes de que tu cuenta sea bloqueada.";
                    }
                }
            } else {
                $errores[] = "El email no está registrado.";
            }
            
        } catch (PDOException $e) {
            $errores[] = "Error en el sistema: " . $e->getMessage();
            error_log("Error de login: " . $e->getMessage());
        }
    }
    
    // Si hay errores, mostrar mensajes
    if (!empty($errores)) {
        $mensaje = implode("<br>", $errores);
        $tipo_mensaje = "danger";
    }
}

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: Inicio");
    exit();
}

// Si hay cookie de recordar, autocompletar email
$email_guardado = isset($_COOKIE['usuario_email']) ? htmlspecialchars($_COOKIE['usuario_email'], ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Iniciar Sesión - Libertyfin Administración</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- CSS específico del login -->
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="container">
        <div class="content-wrapper">
            <!-- Logo -->
            <div class="logo-container text-center">
                <img src="../images/LibertyfinBlanco.png" alt="Logo LibertyFin" 
                     class="logo-horizontal img-fluid">
            </div>

            <!-- Formulario de Login -->
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0 fw-bold">Iniciar Sesión</h3>
                    <p class="mb-0 mt-2 opacity-75">Administración Empresas</p>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($mensaje)): ?>
                        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show mb-4" role="alert">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <?php echo $mensaje; ?>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="welcome-message">
                        <i class="bi bi-shield-check fs-4 text-primary mb-2 d-block"></i>
                        <p>Bienvenido al sistema. Ingresa tus credenciales para acceder.</p>
                    </div>

                    <form id="loginForm" class="needs-validation" novalidate method="POST" action="">
                        
                        <!-- Email -->
                        <div class="mb-4">
                            <label for="email" class="form-label required">Correo Electrónico</label>
                            <div class="position-relative">
                                <i class="bi bi-envelope input-icon"></i>
                                <input type="email" class="form-control input-with-icon" id="email" name="email" 
                                       value="<?php echo $email_guardado; ?>"
                                       required placeholder="usuario@empresa.com">
                            </div>
                            <div class="invalid-feedback">
                                Por favor, ingrese un correo electrónico válido.
                            </div>
                        </div>
                        
                        <!-- Contraseña -->
                        <div class="mb-3">
                            <label for="password" class="form-label required">Contraseña</label>
                            <div class="password-input-group">
                                <i class="bi bi-key input-icon"></i>
                                <input type="password" class="form-control input-with-icon" id="password" name="password" 
                                       required placeholder="Ingrese su contraseña">
                                <button type="button" class="password-toggle" id="togglePassword">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">
                                Por favor, ingrese su contraseña.
                            </div>
                        </div>
                        
                        <!-- Recordar y Olvidé contraseña -->
                        <div class="remember-forgot mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="recordar" name="recordar" 
                                       <?php echo $email_guardado ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="recordar">
                                    Recordar mis datos
                                </label>
                            </div>
                            <div>
                                <a href="recuperar_password.php" class="btn-forgot">
                                    <i class="bi bi-key me-1"></i>¿Olvidó su contraseña?
                                </a>
                            </div>
                        </div>
                        
                        <!-- Botón de Iniciar Sesión -->
                        <div class="mb-4">
                            <button type="submit" class="btn btn-login" id="submitBtn">
                                <span id="submitText">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar Sesión
                                </span>
                                <span class="spinner-border spinner-border-sm d-none" id="submitSpinner"></span>
                            </button>
                        </div>
                        
                        <!-- Información de seguridad -->
                        <div class="alert alert-info mb-4">
                            <div class="d-flex">
                                <div class="me-2">
                                    <i class="bi bi-shield-exclamation"></i>
                                </div>
                                <div>
                                    <small>Por seguridad, cierre su sesión al finalizar y no comparta sus credenciales.</small>
                                </div>
                            </div>
                        </div>
                    </form>
                
                </div>
            </div>

            <!-- Versión del sistema -->
            <div class="version-info">
                <p class="mb-1">
                    <i class="bi bi-c-circle"></i> 2026 Libertyfin
                </p>
                <p class="small">
                    Versión 2.1.0
                </p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- JS específico del login -->
    <script src="assets/js/login.js"></script>
</body>
</html>