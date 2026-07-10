<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

// Iniciar sesión al principio
session_start();
date_default_timezone_set('America/Mexico_City');
// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';
$password_hash ='';

$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

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
            // Crear conexión
            $conn = new mysqli($servername, $username, $password, $dbname);
            
            // Verificar conexión
            if ($conn->connect_error) {
                throw new Exception("Error de conexión: " . $conn->connect_error);
            }
            
            // Buscar usuario por email
            $stmt = $conn->prepare("SELECT id, nombre, apellidos, email, password, rol_usuario, activo FROM usuarios WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $stmt->bind_result($id, $nombre, $apellidos, $email_db, $password_hash, $rol_usuario, $activo);
                $stmt->fetch();
                
                // Verificar si el usuario está activo
                if (!$activo) {
                    $errores[] = "Tu cuenta está desactivada. Contacta al administrador.";
                } else {
                    // Verificar contraseña
                    if (password_verify($password_form, $password_hash)) {
                        // Iniciar sesión
                        $_SESSION['usuario_id'] = $id;
                        $_SESSION['usuario_nombre'] = $nombre . ' ' . $apellidos;
                        $_SESSION['usuario_email'] = $email;
                        $_SESSION['usuario_rol'] = $rol_usuario;
                        $_SESSION['logged_in'] = true;
                        
                        // Si el usuario quiere recordar la sesión
                        if ($recordar) {
                            // Crear cookie que expire en 30 días
                            setcookie('usuario_email', $email, time() + (30 * 24 * 60 * 60), "/");
                        }
                        
                        // Registrar último acceso
                        $stmt_update = $conn->prepare("UPDATE usuarios SET ultimo_acceso = NOW(), intentos_login = 0 WHERE id = ?");
                        $stmt_update->bind_param("i", $id);
                        $stmt_update->execute();
                        $stmt_update->close();
                        
                        header("Location: Inicio");
                    } else {
                        // Contraseña incorrecta, incrementar intentos
                        $stmt_update = $conn->prepare("UPDATE usuarios SET intentos_login = intentos_login + 1 WHERE id = ?");
                        $stmt_update->bind_param("i", $id);
                        $stmt_update->execute();
                        $stmt_update->close();
                        
                        // Verificar si se debe bloquear la cuenta (más de 5 intentos)
                        $stmt_attempts = $conn->prepare("SELECT intentos_login FROM usuarios WHERE id = ?");
                        $stmt_attempts->bind_param("i", $id);
                        $stmt_attempts->execute();
                        $stmt_attempts->bind_result($intentos);
                        $stmt_attempts->fetch();
                        $stmt_attempts->close();
                        
                        if ($intentos >= 5) {
                            $stmt_block = $conn->prepare("UPDATE usuarios SET activo = 0, bloqueado = 1 WHERE id = ?");
                            $stmt_block->bind_param("i", $id);
                            $stmt_block->execute();
                            $stmt_block->close();
                            $errores[] = "Tu cuenta ha sido bloqueada por múltiples intentos fallidos. Contacta al administrador.";
                        } else {
                            $intentos_restantes = 5 - $intentos;
                            $errores[] = "Contraseña incorrecta. Te quedan $intentos_restantes intentos antes de que tu cuenta sea bloqueada.";
                        }
                    }
                }
            } else {
                $errores[] = "El email no está registrado.";
            }
            
            $stmt->close();
            $conn->close();
            
        } catch (Exception $e) {
            $errores[] = "Error en el sistema: " . $e->getMessage();
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
    <style>
        :root {
            --primary-color: #27ae60;
            --primary-dark: #2ecc71;
            --secondary-color: #2c3e50;
            --light-bg: #f8f9fa;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --success-color: #27ae60;
            --warning-color: #e74c3c;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            margin: 0;
        }
        
        .container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .content-wrapper {
            width: 100%;
            max-width: 480px;
            padding: 1rem;
        }
        
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            background-color: white;
            width: 100%;
            overflow: hidden;
        }
        
        .card-header {
            background: var(--primary-color);
            color: white;
            border-bottom: none;
            padding: 1.8rem;
            text-align: center;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        /* Estilos generales del formulario */
        .form-control, .form-select {
            border: 1.5px solid #dee2e6;
            border-radius: 10px;
            padding: 0.85rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.15);
        }
        
        .required:after {
            content: " *";
            color: var(--warning-color);
            font-weight: bold;
        }
        
        .logo-container {
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .logo-horizontal {
            max-width: 100%;
            height: auto;
            max-height: 70px;
        }
        
        .section-title {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.5rem;
        }
        
        /* Campos de contraseña */
        .password-input-group {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 5;
        }
        
        /* Validación */
        .is-invalid {
            border-color: var(--warning-color) !important;
        }
        
        .is-valid {
            border-color: var(--success-color) !important;
        }
        
        /* Alertas informativas */
        .alert-info {
            background-color: #e7f5ff;
            border-color: #a5d8ff;
            color: #1864ab;
        }
        
        .alert-warning {
            background-color: #fff9db;
            border-color: #ffd43b;
            color: #e67700;
        }
        
        /* Botones */
        .btn-login {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            padding: 0.85rem;
            font-weight: 500;
            border-radius: 10px;
            transition: all 0.3s ease;
            width: 100%;
            font-size: 1rem;
        }
        
        .btn-login:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }
        
        .btn-forgot {
            background-color: transparent;
            border: none;
            color: var(--primary-color);
            text-decoration: none;
            padding: 0.5rem 0;
            display: inline-block;
        }
        
        .btn-forgot:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        /* Checkbox personalizado */
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Enlaces */
        .login-links {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #dee2e6;
        }
        
        .login-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .login-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        /* Spinner */
        .spinner-border {
            width: 1rem;
            height: 1rem;
            margin-right: 0.5rem;
        }
        
        /* Iconos */
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 5;
        }
        
        .input-with-icon {
            padding-left: 40px !important;
        }
        
        /* Recordar contraseña */
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1rem 0;
        }
        
        /* Responsive */
        @media (max-width: 576px) {
            .content-wrapper {
                padding: 0.5rem;
                max-width: 100%;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .card-header {
                padding: 1.5rem;
            }
            
            .logo-horizontal {
                max-height: 50px;
            }
            
            .section-title {
                font-size: 1.3rem;
            }
            
            .remember-forgot {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            body {
                padding: 0.5rem;
            }
        }
        
        /* Animaciones */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card {
            animation: fadeIn 0.5s ease;
        }
        
        /* Mensaje de bienvenida */
        .welcome-message {
            text-align: center;
            color: #6c757d;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        
        /* Información de versión */
        .version-info {
            text-align: center;
            margin-top: 1.5rem;
            color: white;
            opacity: 0.8;
            font-size: 0.9rem;
        }
        
        .version-info p {
            margin: 0.3rem 0;
        }
    </style>
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
                    
                    <!-- Enlaces adicionales -->
                    <div class="login-links">
                        <p class="mb-2">¿No tienes una cuenta?</p>
                        <a href="registro_usuario.php" class="login-link">
                            <i class="bi bi-person-plus me-1"></i>Solicitar acceso al sistema
                        </a>
                        <a href="contacto.php" class="login-link">
                            <i class="bi bi-headset me-1"></i>Soporte técnico
                        </a>
                        <a href="politicas.php" class="login-link">
                            <i class="bi bi-file-text me-1"></i>Políticas de privacidad
                        </a>
                    </div>
                </div>
            </div>

            <!-- Versión del sistema -->
            <div class="version-info">
                <p class="mb-1">
                    <i class="bi bi-c-circle"></i> 2024 Sistema de Gestión de Caja
                </p>
                <p class="small">
                    Versión 2.1.0
                </p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicialización cuando el DOM está listo
        document.addEventListener('DOMContentLoaded', function() {
            // Elementos del DOM
            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const submitSpinner = document.getElementById('submitSpinner');
            const passwordInput = document.getElementById('password');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const emailInput = document.getElementById('email');
            
            // Mostrar/ocultar contraseña
            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
            
            // Validar formulario
            function validateForm() {
                let isValid = true;
                
                // Limpiar validaciones anteriores
                const inputs = form.querySelectorAll('input');
                inputs.forEach(input => {
                    input.classList.remove('is-invalid', 'is-valid');
                });
                
                // Validar email
                if (!emailInput.value.trim()) {
                    emailInput.classList.add('is-invalid');
                    isValid = false;
                } else if (!validateEmail(emailInput.value.trim())) {
                    emailInput.classList.add('is-invalid');
                    isValid = false;
                } else {
                    emailInput.classList.add('is-valid');
                }
                
                // Validar contraseña
                if (!passwordInput.value.trim()) {
                    passwordInput.classList.add('is-invalid');
                    isValid = false;
                } else {
                    passwordInput.classList.add('is-valid');
                }
                
                return isValid;
            }
            
            // Función para validar email
            function validateEmail(email) {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return re.test(email);
            }
            
            // Validar email en tiempo real
            emailInput.addEventListener('input', function() {
                if (this.value.trim()) {
                    if (validateEmail(this.value.trim())) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                    }
                } else {
                    this.classList.remove('is-invalid', 'is-valid');
                }
            });
            
            // Validar contraseña en tiempo real
            passwordInput.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                }
            });
            
            // Permitir enviar con Enter
            form.querySelectorAll('input').forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        form.dispatchEvent(new Event('submit'));
                    }
                });
            });
            
            // Manejar envío del formulario
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (validateForm()) {
                    // Mostrar spinner
                    submitText.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Iniciando sesión...';
                    submitSpinner.classList.remove('d-none');
                    submitBtn.disabled = true;
                    
                    // Enviar formulario
                    form.submit();
                } else {
                    // Mostrar el primer error
                    const firstInvalid = document.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({ 
                            behavior: 'smooth', 
                            block: 'center' 
                        });
                        firstInvalid.focus();
                    }
                }
            });
            
            // Auto-focus en el email al cargar la página
            if (!emailInput.value) {
                emailInput.focus();
            } else {
                passwordInput.focus();
            }
            
            // Mejorar experiencia en móviles
            if (window.innerWidth <= 768) {
                // Ajustar foco automáticamente
                form.querySelectorAll('input').forEach(element => {
                    element.addEventListener('focus', function() {
                        setTimeout(() => {
                            this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 300);
                    });
                });
            }
        });
    </script>
</body>
</html>