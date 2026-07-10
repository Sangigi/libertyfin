<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

// Iniciar sesión al principio
session_start();

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Procesar formulario cuando se envía
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['nombre'])) {
    // Sanitizar y validar datos
    $nombre = isset($_POST['nombre']) ? htmlspecialchars(trim($_POST['nombre']), ENT_QUOTES, 'UTF-8') : '';
    $apellidos = isset($_POST['apellidos']) ? htmlspecialchars(trim($_POST['apellidos']), ENT_QUOTES, 'UTF-8') : '';
    $email = isset($_POST['email']) ? filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL) : '';
    $password_form = isset($_POST['password']) ? $_POST['password'] : ''; // Contraseña del formulario
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $rol_usuario = isset($_POST['rol_usuario']) ? $_POST['rol_usuario'] : '';
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Validaciones básicas
    $errores = [];
    
    if (empty($nombre)) {
        $errores[] = "El nombre es obligatorio.";
    }
    
    if (empty($apellidos)) {
        $errores[] = "Los apellidos son obligatorios.";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El email no es válido.";
    }
    
    if (strlen($password_form) < 8) {
        $errores[] = "La contraseña debe tener al menos 8 caracteres.";
    }
    
    // Verificar requisitos de contraseña
    if (!preg_match('/[A-Z]/', $password_form)) {
        $errores[] = "La contraseña debe contener al menos una letra mayúscula.";
    }
    
    if (!preg_match('/[a-z]/', $password_form)) {
        $errores[] = "La contraseña debe contener al menos una letra minúscula.";
    }
    
    if (!preg_match('/[0-9]/', $password_form)) {
        $errores[] = "La contraseña debe contener al menos un número.";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password_form)) {
        $errores[] = "La contraseña debe contener al menos un carácter especial (@$!%*?&).";
    }
    
    if ($password_form !== $confirm_password) {
        $errores[] = "Las contraseñas no coinciden.";
    }
    
    if (empty($rol_usuario)) {
        $errores[] = "Debe seleccionar un rol para el usuario.";
    }
    
    // Si no hay errores, proceder con la inserción
    if (empty($errores)) {
        try {
            // Crear conexión
            $conn = new mysqli($servername, $username, $password, $dbname);
            
            // Verificar conexión
            if ($conn->connect_error) {
                throw new Exception("Error de conexión: " . $conn->connect_error);
            }
            
            // Verificar si el email ya existe
            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $errores[] = "El email ya está registrado.";
            } else {
                // Encriptar contraseña del formulario
                $password_hash = password_hash($password_form, PASSWORD_DEFAULT);
                
                // Insertar nuevo usuario
                $stmt = $conn->prepare("INSERT INTO usuarios (nombre, apellidos, email, password, rol_usuario, activo) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssi", $nombre, $apellidos, $email, $password_hash, $rol_usuario, $activo);
                
                if ($stmt->execute()) {
                    $mensaje = "✅ Usuario creado exitosamente!";
                    $tipo_mensaje = "success";
                    
                    // Limpiar datos del formulario después de éxito
                    $nombre = $apellidos = $email = '';
                    $rol_usuario = 'empleado';
                    
                } else {
                    throw new Exception("Error al crear el usuario: " . $stmt->error);
                }
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
} else {
    // Inicializar variables si no se envió el formulario
    $nombre = $apellidos = $email = '';
    $rol_usuario = 'empleado';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Registro de Nuevo Usuario - Libertyfin</title>
     <link rel="icon" href="images/favicon.ico" type="image/x-icon">
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
            padding-top: 1rem;
            padding-bottom: 2rem;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            background-color: white;
        }
        
        .card-header {
            background: var(--primary-color);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
            border-radius: 12px 12px 0 0 !important;
        }
        
        /* Paso Indicator */
        .step-container {
            margin-bottom: 2rem;
        }
        
        .step-progress {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 0 auto;
            max-width: 600px;
        }
        
        .step-progress:before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 3px;
            background-color: #dee2e6;
            transform: translateY(-50%);
            z-index: 1;
        }
        
        .step-progress-line {
            position: absolute;
            top: 50%;
            left: 0;
            height: 3px;
            background-color: var(--primary-color);
            transform: translateY(-50%);
            transition: width 0.3s ease;
            z-index: 2;
        }
        
        .step-item {
            position: relative;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100px;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: white;
            border: 3px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #6c757d;
            transition: all 0.3s ease;
            margin-bottom: 0.5rem;
        }
        
        .step-item.active .step-circle {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }
        
        .step-item.completed .step-circle {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .step-item.completed .step-circle:after {
            content: '✓';
        }
        
        .step-label {
            font-size: 0.85rem;
            text-align: center;
            color: #6c757d;
            font-weight: 500;
        }
        
        .step-item.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .step-item.completed .step-label {
            color: var(--primary-dark);
        }
        
        /* Contenido del formulario */
        .form-step {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .form-step.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Botones de navegación */
        .btn-navigation {
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border-radius: 8px;
        }
        
        .btn-next {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .btn-next:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }
        
        .btn-prev {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
        }
        
        .btn-prev:hover {
            background-color: #5a6268;
            border-color: #545b62;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }
        
        .btn-cancel {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
        }
        
        .btn-cancel:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
            color: #495057;
            transform: translateY(-2px);
        }
        
        /* Responsividad para los pasos */
        @media (max-width: 768px) {
            .step-container {
                padding: 0 1rem;
                margin-bottom: 1.5rem;
            }
            
            .step-item {
                width: 70px;
            }
            
            .step-circle {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
            
            .step-label {
                font-size: 0.75rem;
            }
            
            .step-progress:before {
                height: 2px;
            }
            
            .step-progress-line {
                height: 2px;
            }
            
            .btn-navigation {
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 576px) {
            .step-item {
                width: 60px;
            }
            
            .step-circle {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
                margin-bottom: 0.25rem;
            }
            
            .step-label {
                font-size: 0.7rem;
            }
            
            .btn-navigation {
                padding: 0.5rem 1rem;
                font-size: 0.85rem;
            }
        }
        
        /* Estilos generales del formulario */
        .form-control, .form-select {
            border: 1.5px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem 1rem;
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
        
        .optional {
            color: #6c757d;
            font-size: 0.85rem;
            font-weight: normal;
        }
        
        .logo-container {
            padding: 1rem 0;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin-bottom: 1.5rem;
            backdrop-filter: blur(10px);
        }
        
        .logo-horizontal {
            max-width: 100%;
            height: auto;
            max-height: 70px;
        }
        
        @media (max-width: 576px) {
            .logo-horizontal {
                max-height: 50px;
            }
        }
        
        .section-title {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary-color);
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
        
        .password-strength {
            height: 5px;
            margin-top: 0.5rem;
            border-radius: 2px;
            background-color: #e9ecef;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        
        .strength-weak {
            background-color: var(--warning-color);
        }
        
        .strength-medium {
            background-color: #f39c12;
        }
        
        .strength-strong {
            background-color: var(--success-color);
        }
        
        .password-requirements {
            list-style: none;
            padding-left: 0;
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }
        
        .password-requirements li {
            margin-bottom: 0.25rem;
            color: #6c757d;
        }
        
        .password-requirements li.requirement-met {
            color: var(--success-color);
        }
        
        .password-requirements li.requirement-met:before {
            content: "✓ ";
            font-weight: bold;
        }
        
        .password-requirements li.requirement-unmet:before {
            content: "✗ ";
            font-weight: bold;
            color: var(--warning-color);
        }
        
        /* Resumen de datos */
        .summary-item {
            background-color: var(--light-bg);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .summary-label {
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 0.25rem;
        }
        
        .summary-value {
            color: #495057;
        }
        
        .summary-optional {
            color: #6c757d;
            font-style: italic;
        }
        
        /* Spinner */
        .spinner-border {
            width: 1rem;
            height: 1rem;
            margin-right: 0.5rem;
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
        
        /* Switch para activo/inactivo */
        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
            margin-right: 0.5rem;
        }
        
        .form-switch .form-check-input:checked {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        /* Badge para roles */
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .role-admin {
            background-color: #e3f2fd;
            color: #1565c0;
        }
        
        .role-empleado {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .role-supervisor {
            background-color: #fff3e0;
            color: #ef6c00;
        }
        
        .role-contador {
            background-color: #f3e5f5;
            color: #7b1fa2;
        }
        
        /* Contraseña segura */
        .password-secure {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        /* Botón Atrás fijo */
        .btn-atras-fijo {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        
        @media (max-width: 768px) {
            .btn-atras-fijo {
                position: relative;
                top: auto;
                left: auto;
                margin-bottom: 1rem;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10 col-xl-8">
                <!-- Logo -->
                <div class="logo-container text-center mb-4">
                    <img src="../images/LibertyfinBlanco.png" alt="Logo LibertyFin" 
                         class="logo-horizontal img-fluid">
                </div>

                <!-- Indicador de Pasos - 2 pasos -->
                <div class="step-container">
                    <div class="step-progress">
                        <div class="step-progress-line" id="progressLine"></div>
                        <div class="step-item active" data-step="1">
                            <div class="step-circle">1</div>
                            <div class="step-label">Información Personal</div>
                        </div>
                        <div class="step-item" data-step="2">
                            <div class="step-circle">2</div>
                            <div class="step-label">Credenciales y Rol</div>
                        </div>
                    </div>
                </div>

                <!-- Formulario -->
                <div class="card">
                    <div class="card-header text-center">
                        <h3 class="mb-0 fw-bold" id="formTitle">Registro de Nuevo Usuario - Paso 1 de 2</h3>
                        <p class="mb-0 mt-2 opacity-75" id="formSubtitle">Información Personal del Usuario</p>
                    </div>
                    
                    <div class="card-body p-lg-4">
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

                        <form id="registroForm" class="needs-validation" novalidate method="POST" action="">
                            
                            <!-- Paso 1: Información Personal -->
                            <div class="form-step active" id="step1">
                                <h4 class="section-title">Información Personal</h4>
                                
                                <div class="alert alert-info mb-4">
                                    <i class="bi bi-info-circle"></i> Los campos marcados con <span class="required"></span> son obligatorios.
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <div class="mb-3">
                                            <label for="nombre" class="form-label required">Nombre</label>
                                            <input type="text" class="form-control" id="nombre" name="nombre" 
                                                   value="<?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?>" 
                                                   required placeholder="Ingrese el nombre del usuario">
                                            <div class="invalid-feedback">
                                                Por favor, ingrese el nombre del usuario.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 col-md-6">
                                        <div class="mb-3">
                                            <label for="apellidos" class="form-label required">Apellidos</label>
                                            <input type="text" class="form-control" id="apellidos" name="apellidos" 
                                                   value="<?php echo htmlspecialchars($apellidos, ENT_QUOTES, 'UTF-8'); ?>" 
                                                   required placeholder="Ingrese los apellidos">
                                            <div class="invalid-feedback">
                                                Por favor, ingrese los apellidos del usuario.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 col-md-8">
                                        <div class="mb-3">
                                            <label for="email" class="form-label required">Correo Electrónico</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                                                   required placeholder="usuario@empresa.com">
                                            <div class="invalid-feedback">
                                                Por favor, ingrese un correo electrónico válido.
                                            </div>
                                            <div class="form-text">
                                                Este correo se utilizará para el inicio de sesión.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label required">Estado</label>
                                            <div class="form-switch mt-2">
                                                <input class="form-check-input" type="checkbox" id="activo" name="activo" value="1" checked>
                                                <label class="form-check-label" for="activo">
                                                    Usuario Activo
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                Desactive para crear usuario inactivo
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Paso 2: Credenciales y Rol -->
                            <div class="form-step" id="step2">
                                <h4 class="section-title">Credenciales y Rol</h4>
                                
                                <div class="alert alert-info mb-4">
                                    <div class="d-flex">
                                        <div class="me-3">
                                            <i class="bi bi-shield-check fs-4"></i>
                                        </div>
                                        <div>
                                            <h6 class="alert-heading">Seguridad de Cuenta</h6>
                                            <p class="mb-2">La contraseña debe cumplir con los requisitos de seguridad. Asegúrese de crear una contraseña fuerte.</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="rol_usuario" class="form-label required">Rol del Usuario</label>
                                            <select class="form-select" id="rol_usuario" name="rol_usuario" required>
                                                <option value="">Seleccione un rol</option>
                                                <option value="administrador" <?php echo ($rol_usuario == 'administrador') ? 'selected' : ''; ?>>Administrador</option>
                                                <option value="supervisor" <?php echo ($rol_usuario == 'supervisor') ? 'selected' : ''; ?>>Supervisor</option>
                                                <option value="contador" <?php echo ($rol_usuario == 'contador') ? 'selected' : ''; ?>>Contador</option>
                                                <option value="empleado" <?php echo ($rol_usuario == 'empleado') ? 'selected' : ''; ?>>Empleado</option>
                                            </select>
                                            <div class="invalid-feedback">
                                                Por favor, seleccione un rol para el usuario.
                                            </div>
                                            <div class="form-text mt-2">
                                                <span class="role-badge role-admin me-2">Administrador</span>
                                                <span class="role-badge role-supervisor me-2">Supervisor</span>
                                                <span class="role-badge role-contador me-2">Contador</span>
                                                <span class="role-badge role-empleado">Empleado</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Contraseña ingresada por el usuario -->
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="password" class="form-label required">Contraseña</label>
                                            <div class="password-input-group">
                                                <input type="password" class="form-control" id="password" name="password" 
                                                       required placeholder="Ingrese una contraseña segura"
                                                       minlength="8">
                                                <button type="button" class="password-toggle" id="togglePassword">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                            <div class="password-strength mt-2">
                                                <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                            </div>
                                            <div class="invalid-feedback" id="passwordError">
                                                La contraseña debe tener al menos 8 caracteres, incluir mayúsculas, minúsculas, números y caracteres especiales.
                                            </div>
                                            <div class="form-text">
                                                Mínimo 8 caracteres con mayúsculas, minúsculas, números y caracteres especiales (@$!%*?&)
                                            </div>
                                            
                                            <!-- Requisitos de contraseña -->
                                            <div class="password-secure">
                                                <h6 class="mb-3">Requisitos de seguridad:</h6>
                                                <ul class="password-requirements" id="passwordRequirements">
                                                    <li class="requirement-unmet" id="reqLength">Mínimo 8 caracteres</li>
                                                    <li class="requirement-unmet" id="reqLowercase">Al menos una letra minúscula</li>
                                                    <li class="requirement-unmet" id="reqUppercase">Al menos una letra mayúscula</li>
                                                    <li class="requirement-unmet" id="reqNumber">Al menos un número</li>
                                                    <li class="requirement-unmet" id="reqSpecial">Al menos un carácter especial</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label required">Confirmar Contraseña</label>
                                            <div class="password-input-group">
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                                       required placeholder="Repita la contraseña">
                                                <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                            <div class="invalid-feedback" id="confirmPasswordError">
                                                Las contraseñas no coinciden.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" id="mostrar_password" name="mostrar_password">
                                            <label class="form-check-label" for="mostrar_password">
                                                Mostrar contraseña al finalizar el registro
                                            </label>
                                            <div class="form-text">
                                                Se mostrará la contraseña una sola vez para que pueda compartirla con el usuario.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Navegación -->
                            <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                                <div>
                                    <button type="button" class="btn btn-prev btn-navigation" id="prevBtn" style="display: none;">
                                        <i class="bi bi-arrow-left me-2"></i>Anterior
                                    </button>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-cancel btn-navigation" id="cancelBtn">
                                        <i class="bi bi-x-circle me-2"></i>Cancelar
                                    </button>
                                    
                                    <button type="button" class="btn btn-next btn-navigation" id="nextBtn">
                                        Siguiente<i class="bi bi-arrow-right ms-2"></i>
                                    </button>
                                    
                                    <button type="submit" class="btn btn-success btn-navigation" id="submitBtn" style="display: none;">
                                        <span id="submitText">
                                            <i class="bi bi-check-circle me-2"></i>Crear Usuario
                                        </span>
                                        <span class="spinner-border spinner-border-sm d-none" id="submitSpinner"></span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Enlace a login -->
                <div class="text-center mt-4">
                    <p class="text-white mb-2 fs-5">
                        ¿Ya tienes cuenta? 
                    </p>
                    <a href="login.php" class="text-white fw-bold text-decoration-underline fs-5">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar sesión aquí
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables globales
        let currentStep = 1;
        const totalSteps = 2;
        
        // Función para verificar fortaleza de contraseña
        function checkPasswordStrength(password) {
            let strength = 0;
            const requirements = {
                length: false,
                lowercase: false,
                uppercase: false,
                number: false,
                special: false
            };
            
            // Verificar longitud
            if (password.length >= 8) {
                strength += 20;
                requirements.length = true;
            }
            
            // Verificar minúsculas
            if (/[a-z]/.test(password)) {
                strength += 20;
                requirements.lowercase = true;
            }
            
            // Verificar mayúsculas
            if (/[A-Z]/.test(password)) {
                strength += 20;
                requirements.uppercase = true;
            }
            
            // Verificar números
            if (/[0-9]/.test(password)) {
                strength += 20;
                requirements.number = true;
            }
            
            // Verificar caracteres especiales
            if (/[^A-Za-z0-9]/.test(password)) {
                strength += 20;
                requirements.special = true;
            }
            
            return { strength, requirements };
        }
        
        // Función para actualizar indicador de fortaleza
        function updatePasswordStrength(password) {
            const result = checkPasswordStrength(password);
            const strengthBar = document.getElementById('passwordStrengthBar');
            const requirements = document.querySelectorAll('.password-requirements li');
            
            // Actualizar barra de fortaleza
            strengthBar.style.width = `${result.strength}%`;
            
            // Actualizar color según fortaleza
            if (result.strength < 40) {
                strengthBar.className = 'password-strength-bar strength-weak';
            } else if (result.strength < 80) {
                strengthBar.className = 'password-strength-bar strength-medium';
            } else {
                strengthBar.className = 'password-strength-bar strength-strong';
            }
            
            // Actualizar lista de requisitos
            requirements.forEach(li => {
                const id = li.id;
                const requirementType = id.replace('req', '').toLowerCase();
                
                if (result.requirements[requirementType]) {
                    li.className = 'requirement-met';
                } else {
                    li.className = 'requirement-unmet';
                }
            });
            
            return result.strength;
        }
        
        // Inicialización cuando el DOM está listo
        document.addEventListener('DOMContentLoaded', function() {
            // Elementos del DOM
            const form = document.getElementById('registroForm');
            const steps = document.querySelectorAll('.form-step');
            const stepItems = document.querySelectorAll('.step-item');
            const progressLine = document.getElementById('progressLine');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            const formTitle = document.getElementById('formTitle');
            const formSubtitle = document.getElementById('formSubtitle');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const toggleConfirmPasswordBtn = document.getElementById('toggleConfirmPassword');
            
            // Actualizar el indicador de progreso
            function updateProgress() {
                const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
                progressLine.style.width = `${progress}%`;
                
                // Actualizar clases de los pasos
                stepItems.forEach((item, index) => {
                    const stepNumber = parseInt(item.dataset.step);
                    
                    item.classList.remove('active', 'completed');
                    
                    if (stepNumber < currentStep) {
                        item.classList.add('completed');
                    } else if (stepNumber === currentStep) {
                        item.classList.add('active');
                    }
                });
                
                // Actualizar título y subtítulo
                const titles = [
                    'Registro de Nuevo Usuario - Paso 1 de 2',
                    'Registro de Nuevo Usuario - Paso 2 de 2'
                ];
                
                const subtitles = [
                    'Información Personal del Usuario',
                    'Credenciales y Rol del Usuario'
                ];
                
                formTitle.textContent = titles[currentStep - 1];
                formSubtitle.textContent = subtitles[currentStep - 1];
                
                // Mostrar/ocultar botones
                if (currentStep === 1) {
                    prevBtn.style.display = 'none';
                    nextBtn.style.display = 'inline-block';
                    submitBtn.style.display = 'none';
                    cancelBtn.style.display = 'inline-block';
                } else if (currentStep === totalSteps) {
                    prevBtn.style.display = 'inline-block';
                    nextBtn.style.display = 'none';
                    submitBtn.style.display = 'inline-block';
                    cancelBtn.style.display = 'inline-block';
                } else {
                    prevBtn.style.display = 'inline-block';
                    nextBtn.style.display = 'inline-block';
                    submitBtn.style.display = 'none';
                    cancelBtn.style.display = 'inline-block';
                }
            }
            
            // Validar paso actual
            function validateStep(step) {
                let isValid = true;
                const currentStepElement = document.getElementById(`step${step}`);
                
                // Limpiar validaciones anteriores
                const inputs = currentStepElement.querySelectorAll('input, select');
                inputs.forEach(input => {
                    input.classList.remove('is-invalid', 'is-valid');
                });
                
                // Validar campos requeridos
                const requiredInputs = currentStepElement.querySelectorAll('[required]');
                
                requiredInputs.forEach(input => {
                    if (input.type === 'checkbox') {
                        if (!input.checked && input.required) {
                            input.classList.add('is-invalid');
                            isValid = false;
                        } else {
                            input.classList.add('is-valid');
                        }
                    } else {
                        if (!input.value.trim()) {
                            input.classList.add('is-invalid');
                            isValid = false;
                        } else if (input.type === 'email') {
                            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                            if (!emailRegex.test(input.value.trim())) {
                                input.classList.add('is-invalid');
                                isValid = false;
                            } else {
                                input.classList.add('is-valid');
                            }
                        } else {
                            input.classList.add('is-valid');
                        }
                    }
                });
                
                // Validaciones específicas para el paso 2 (contraseña)
                if (step === 2) {
                    const password = passwordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    // Validar fortaleza de contraseña
                    const strength = updatePasswordStrength(password);
                    if (strength < 80) {
                        passwordInput.classList.add('is-invalid');
                        document.getElementById('passwordError').style.display = 'block';
                        isValid = false;
                    } else {
                        passwordInput.classList.remove('is-invalid');
                        document.getElementById('passwordError').style.display = 'none';
                        passwordInput.classList.add('is-valid');
                    }
                    
                    // Validar que las contraseñas coincidan
                    if (password !== confirmPassword) {
                        confirmPasswordInput.classList.add('is-invalid');
                        document.getElementById('confirmPasswordError').style.display = 'block';
                        isValid = false;
                    } else {
                        confirmPasswordInput.classList.remove('is-invalid');
                        document.getElementById('confirmPasswordError').style.display = 'none';
                        confirmPasswordInput.classList.add('is-valid');
                    }
                }
                
                return isValid;
            }
            
            // Cambiar al siguiente paso
            function nextStep() {
                if (validateStep(currentStep)) {
                    if (currentStep < totalSteps) {
                        // Ocultar paso actual
                        document.getElementById(`step${currentStep}`).classList.remove('active');
                        
                        // Mostrar siguiente paso
                        currentStep++;
                        document.getElementById(`step${currentStep}`).classList.add('active');
                        
                        updateProgress();
                        
                        // Desplazar hacia arriba en móviles
                        if (window.innerWidth <= 768) {
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        }
                    }
                } else {
                    // Mostrar el primer error
                    const firstInvalid = document.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        firstInvalid.focus();
                    }
                }
            }
            
            // Volver al paso anterior
            function prevStep() {
                if (currentStep > 1) {
                    // Ocultar paso actual
                    document.getElementById(`step${currentStep}`).classList.remove('active');
                    
                    // Mostrar paso anterior
                    currentStep--;
                    document.getElementById(`step${currentStep}`).classList.add('active');
                    
                    updateProgress();
                    
                    // Desplazar hacia arriba en móviles
                    if (window.innerWidth <= 768) {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                }
            }
            
            // Cancelar registro
            function cancelRegistration() {
                if (confirm('¿Está seguro que desea cancelar el registro? Los datos no guardados se perderán.')) {
                    window.location.href = 'lista_usuarios.php'; // Cambia por la página a la que quieres redirigir
                }
            }
            
            // Event Listeners
            nextBtn.addEventListener('click', nextStep);
            prevBtn.addEventListener('click', prevStep);
            cancelBtn.addEventListener('click', cancelRegistration);
            
            // Mostrar/ocultar contraseña
            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
            
            toggleConfirmPasswordBtn.addEventListener('click', function() {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
            
            // Validar contraseña en tiempo real
            passwordInput.addEventListener('input', function() {
                updatePasswordStrength(this.value);
                
                // Si ya se ingresó la confirmación, validar coincidencia
                if (confirmPasswordInput.value) {
                    if (this.value !== confirmPasswordInput.value) {
                        confirmPasswordInput.classList.add('is-invalid');
                        document.getElementById('confirmPasswordError').style.display = 'block';
                    } else {
                        confirmPasswordInput.classList.remove('is-invalid');
                        document.getElementById('confirmPasswordError').style.display = 'none';
                        confirmPasswordInput.classList.add('is-valid');
                    }
                }
            });
            
            // Validar confirmación de contraseña en tiempo real
            confirmPasswordInput.addEventListener('input', function() {
                if (passwordInput.value !== this.value) {
                    this.classList.add('is-invalid');
                    document.getElementById('confirmPasswordError').style.display = 'block';
                } else {
                    this.classList.remove('is-invalid');
                    document.getElementById('confirmPasswordError').style.display = 'none';
                    this.classList.add('is-valid');
                }
            });
            
            // Permitir navegación con Enter en inputs
            form.querySelectorAll('input').forEach(input => {
                if (input.type !== 'textarea') {
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter' && currentStep < totalSteps) {
                            e.preventDefault();
                            nextStep();
                        }
                    });
                }
            });
            
            // Manejar envío del formulario
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validar todos los pasos
                let allValid = true;
                
                // Validar paso 1
                const step1Inputs = document.querySelectorAll('#step1 [required]');
                step1Inputs.forEach(input => {
                    if (!input.value.trim() && input.type !== 'checkbox') {
                        input.classList.add('is-invalid');
                        allValid = false;
                    }
                });
                
                // Validar paso 2
                if (!validateStep(2)) {
                    allValid = false;
                }
                
                if (allValid) {
                    // Mostrar spinner
                    const submitText = document.getElementById('submitText');
                    const submitSpinner = document.getElementById('submitSpinner');
                    
                    submitText.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Creando Usuario...';
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
            
            // Navegación por clic en los pasos (solo para pasos completados)
            stepItems.forEach(item => {
                item.addEventListener('click', function() {
                    const stepNumber = parseInt(this.dataset.step);
                    const clickedItem = document.querySelector(`.step-item[data-step="${stepNumber}"]`);
                    
                    // Solo permitir navegar a pasos completados
                    if (clickedItem.classList.contains('completed') && stepNumber !== currentStep) {
                        // Ocultar paso actual
                        document.getElementById(`step${currentStep}`).classList.remove('active');
                        
                        // Mostrar paso seleccionado
                        currentStep = stepNumber;
                        document.getElementById(`step${currentStep}`).classList.add('active');
                        
                        updateProgress();
                    }
                });
            });
            
            // Validación en tiempo real para campos obligatorios
            const requiredInputs = form.querySelectorAll('[required]');
            requiredInputs.forEach(input => {
                if (input.type !== 'checkbox' && input.type !== 'file') {
                    input.addEventListener('input', function() {
                        if (this.value.trim()) {
                            this.classList.remove('is-invalid');
                            this.classList.add('is-valid');
                        } else {
                            this.classList.remove('is-valid');
                        }
                    });
                }
            });
            
            // Inicializar progreso
            updateProgress();
            
            // Mejorar experiencia en móviles
            if (window.innerWidth <= 768) {
                // Ajustar foco automáticamente
                form.querySelectorAll('input, textarea, select').forEach(element => {
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