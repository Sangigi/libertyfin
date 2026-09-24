<?php
// Configuración de seguridad de sesión
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_secure', 1);   // Solo HTTPS
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict'); // Protección CSRF
session_start();

// VERIFICAR SI EL USUARIO YA ESTÁ LOGUEADO
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
        session_destroy();
        error_log("Posible secuestro de sesión - IP cambio de {$_SESSION['ip_address']} a {$_SERVER['REMOTE_ADDR']}");
    } elseif (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
        session_destroy();
        error_log("Posible secuestro de sesión - User Agent cambio");
    } elseif (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
        session_destroy();
        error_log("Sesión expirada por tiempo");
    } else {
        // Si la sesión es de suscripción expirada, mantener en esa página
        if (isset($_SESSION['suscripcion_expirada']) && $_SESSION['suscripcion_expirada'] === true) {
            header("Location: suscripcion_expirada.php");
            exit();
        }
        header("Location: Inicio");
        exit();
    }
}

// Regenerar ID de sesión para prevenir fijación
if (!isset($_SESSION['initialized'])) {
    session_regenerate_id(true);
    $_SESSION['initialized'] = true;
}

// Configuración de seguridad adicional
$mensaje = "";
$tipo_mensaje = "";

// Limitar intentos de login (protección contra fuerza bruta)
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

if ($_SESSION['login_attempts'] >= 5) {
    $time_diff = time() - $_SESSION['last_attempt_time'];
    if ($time_diff < 900) {
        $mensaje = "Demasiados intentos fallidos. Por favor, espere " . ceil((900 - $time_diff) / 60) . " minutos.";
        $tipo_mensaje = "danger";
        $_POST = [];
    } else {
        $_SESSION['login_attempts'] = 0;
    }
}

// Procesar formulario de login
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($mensaje)) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $mensaje = "Error de seguridad. Por favor, recargue la página.";
        $tipo_mensaje = "danger";
    } else {
        $usuario = trim(htmlspecialchars($_POST['usuario'], ENT_QUOTES, 'UTF-8'));
        $password = $_POST['password'];

        if (empty($usuario) || empty($password)) {
            $mensaje = "Usuario/Email y contraseña son obligatorios.";
            $tipo_mensaje = "danger";
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
        } else {
            try {
                $servername = "libertyfin.com.mx";
                $username = "juanc141_alexis";
                $password_db = "Alexis1997";
                $db_main = "juanc141_ventas";

                $conn_main = new mysqli();
                $conn_main->ssl_set(null, null, null, null, null);
                $conn_main->real_connect($servername, $username, $password_db, $db_main, 3306, null, MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);

                if ($conn_main->connect_error) {
                    throw new Exception("Error de conexión temporal. Intente más tarde.");
                }

                $conn_main->set_charset("utf8mb4");

                // ⚠️ IMPORTANTE: Quitamos "WHERE activo = TRUE" para poder detectar
                // empresas inactivas/expiradas y redirigir a suscripcion_expirada.php
                $sql_empresas = "SELECT id, nombre_empresa, nombre_base_datos, activo, 
                                        fecha_vencimiento, plan 
                                 FROM empresas";
                $result_empresas = $conn_main->query($sql_empresas);

                if (!$result_empresas) {
                    throw new Exception("Error al obtener información de empresas.");
                }

                $usuario_encontrado = false;
                $empresa_db = "";
                $empresa_nombre = "";
                $empresa_id_principal = 0;
                $empresa_plan = 'prueba';
                $usuario_data = null;
                $sucursal_data = null;
                $suscripcion_vencida = false;
                $datos_empresa_vencida = null;

                // Buscar el usuario en cada base de datos de empresa
                while ($empresa = $result_empresas->fetch_assoc()) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $empresa['nombre_base_datos'])) {
                        error_log("Nombre de BD inválido: " . $empresa['nombre_base_datos']);
                        continue;
                    }

                    $conn_empresa = new mysqli();
                    $conn_empresa->ssl_set(null, null, null, null, null);
                    $conn_empresa->real_connect($servername, $username, $password_db, $empresa['nombre_base_datos'], 3306, null, MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);

                    if ($conn_empresa->connect_error) {
                        error_log("Error conectando a BD " . $empresa['nombre_base_datos'] . ": " . $conn_empresa->connect_error);
                        continue;
                    }

                    $conn_empresa->set_charset("utf8mb4");

                    // ⚠️ Ya NO incluimos "activo" porque esa columna NO existe en usuarios
                    $sql_usuario = "SELECT id, username, password, nombre, rol, sucursal_id, email 
                                    FROM usuarios 
                                    WHERE (username = ? OR email = ?) 
                                    LIMIT 1";

                    $stmt_usuario = $conn_empresa->prepare($sql_usuario);

                    if (!$stmt_usuario) {
                        error_log("Error en prepare: " . $conn_empresa->error);
                        $conn_empresa->close();
                        continue;
                    }

                    $stmt_usuario->bind_param("ss", $usuario, $usuario);
                    $stmt_usuario->execute();
                    $result_usuario = $stmt_usuario->get_result();

                    if ($result_usuario->num_rows > 0) {
                        $usuario_temp = $result_usuario->fetch_assoc();

                        if (password_verify($password, $usuario_temp['password'])) {

                            // ============================================================
                            // VERIFICAR SI LA EMPRESA ESTÁ ACTIVA Y NO EXPIRADA
                            // ============================================================
                            $empresa_activa = intval($empresa['activo']) === 1;
                            $fecha_venc = $empresa['fecha_vencimiento'] ?? null;
                            $empresa_no_vencida = true;
                            
                            if (!empty($fecha_venc)) {
                                // Comparar con fecha actual (la fecha_vencimiento es tipo DATE)
                                $hoy = date('Y-m-d');
                                $empresa_no_vencida = ($fecha_venc >= $hoy);
                            }

                            error_log("LOGIN DEBUG - Empresa: {$empresa['nombre_empresa']} | activo: " . var_export($empresa['activo'], true) . " | fecha_venc: " . var_export($fecha_venc, true) . " | no_vencida: " . var_export($empresa_no_vencida, true));

                            if (!$empresa_activa || !$empresa_no_vencida) {
                                // --------------------------------------------------------
                                // EMPRESA INACTIVA O SUSCRIPCIÓN VENCIDA
                                // --------------------------------------------------------
                                $suscripcion_vencida = true;
                                $datos_empresa_vencida = [
                                    'empresa_id' => intval($empresa['id']),
                                    'empresa_db' => $empresa['nombre_base_datos'],
                                    'empresa_nombre' => $empresa['nombre_empresa'],
                                    'empresa_plan' => $empresa['plan'] ?? 'prueba',
                                    'usuario_id' => intval($usuario_temp['id']),
                                    'usuario_nombre' => $usuario_temp['nombre'],
                                    'usuario_rol' => $usuario_temp['rol'],
                                    'usuario_email' => $usuario_temp['email'] ?? ''
                                ];

                                $stmt_usuario->close();
                                $conn_empresa->close();
                                break; // Salir del while, ya encontramos al usuario
                            }
                            // ============================================================
                            // FIN VERIFICACIÓN - Empresa activa, continuar
                            // ============================================================

                            // Rehash si es necesario
                            if (password_needs_rehash($usuario_temp['password'], PASSWORD_DEFAULT)) {
                                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                                $update_stmt = $conn_empresa->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                                $update_stmt->bind_param("si", $new_hash, $usuario_temp['id']);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }

                            // Obtener sucursal
                            $sql_sucursal = "SELECT s.id, s.nombre, s.es_matriz, s.activo 
                                            FROM sucursales s 
                                            WHERE s.id = ? AND s.activo = TRUE";
                            $stmt_sucursal = $conn_empresa->prepare($sql_sucursal);

                            if ($stmt_sucursal) {
                                $stmt_sucursal->bind_param("i", $usuario_temp['sucursal_id']);
                                $stmt_sucursal->execute();
                                $result_sucursal = $stmt_sucursal->get_result();

                                if ($result_sucursal->num_rows > 0) {
                                    $sucursal_temp = $result_sucursal->fetch_assoc();

                                    $usuario_encontrado = true;
                                    $empresa_db = $empresa['nombre_base_datos'];
                                    $empresa_nombre = $empresa['nombre_empresa'];
                                    $empresa_id_principal = intval($empresa['id']);
                                    $empresa_plan = $empresa['plan'] ?? 'prueba';
                                    $usuario_data = $usuario_temp;
                                    $sucursal_data = $sucursal_temp;

                                    $stmt_sucursal->close();
                                    $stmt_usuario->close();
                                    $conn_empresa->close();
                                    break;
                                }
                                $stmt_sucursal->close();
                            }
                        }
                    }

                    $stmt_usuario->close();
                    $conn_empresa->close();
                }

                $conn_main->close();

                // --------------------------------------------------------
                // REDIRIGIR A SUSCRIPCIÓN EXPIRADA SI APLICA
                // --------------------------------------------------------
                if ($suscripcion_vencida && $datos_empresa_vencida) {
                    session_regenerate_id(true);

                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    $_SESSION['empresa_id'] = $datos_empresa_vencida['empresa_id'];
                    $_SESSION['empresa_db'] = $datos_empresa_vencida['empresa_db'];
                    $_SESSION['empresa_nombre'] = htmlspecialchars($datos_empresa_vencida['empresa_nombre']);
                    $_SESSION['empresa_plan'] = $datos_empresa_vencida['empresa_plan'];
                    $_SESSION['usuario_id'] = $datos_empresa_vencida['usuario_id'];
                    $_SESSION['usuario_nombre'] = htmlspecialchars($datos_empresa_vencida['usuario_nombre']);
                    $_SESSION['usuario_rol'] = htmlspecialchars($datos_empresa_vencida['usuario_rol']);
                    $_SESSION['usuario_email'] = htmlspecialchars($datos_empresa_vencida['usuario_email']);
                    $_SESSION['suscripcion_expirada'] = true;

                    error_log("Login con suscripción expirada - Empresa: {$datos_empresa_vencida['empresa_nombre']}, IP: {$_SERVER['REMOTE_ADDR']}");

                    header("Location: suscripcion_expirada.php");
                    exit();
                }

                // --------------------------------------------------------
                // LOGIN EXITOSO NORMAL
                // --------------------------------------------------------
                if ($usuario_encontrado && $usuario_data && $sucursal_data) {
                    $_SESSION['login_attempts'] = 0;
                    session_regenerate_id(true);

                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    $_SESSION['empresa_id'] = $empresa_id_principal;
                    $_SESSION['empresa_db'] = $empresa_db;
                    $_SESSION['empresa_nombre'] = htmlspecialchars($empresa_nombre);
                    $_SESSION['empresa_plan'] = $empresa_plan;
                    $_SESSION['usuario_id'] = intval($usuario_data['id']);
                    $_SESSION['usuario_nombre'] = htmlspecialchars($usuario_data['nombre']);
                    $_SESSION['usuario_rol'] = htmlspecialchars($usuario_data['rol']);
                    $_SESSION['sucursal_id'] = intval($sucursal_data['id']);
                    $_SESSION['sucursal_nombre'] = htmlspecialchars($sucursal_data['nombre']);
                    $_SESSION['sucursal_es_matriz'] = intval($sucursal_data['es_matriz']);
                    $_SESSION['usuario_email'] = htmlspecialchars($usuario_data['email'] ?? '');

                    // Limpiar bandera por si acaso
                    unset($_SESSION['suscripcion_expirada']);

                    error_log("Login exitoso - Usuario: {$usuario_data['username']}, Empresa: $empresa_nombre, IP: {$_SERVER['REMOTE_ADDR']}");

                    header("Location: Inicio");
                    exit();
                } else {
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    throw new Exception("Usuario/Email o contraseña incorrectos.");
                }
            } catch (Exception $e) {
                $mensaje = $e->getMessage();
                $tipo_mensaje = "danger";
                error_log("Error de login: " . $e->getMessage() . " - IP: " . $_SERVER['REMOTE_ADDR']);
            }
        }
    }
}

// Generar token CSRF para el formulario
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema Caja</title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        body {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
        }

        .login-container {
            max-width: 400px;
            width: 100%;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 2rem;
            text-align: center;
        }

        .logo-horizontal {
            max-width: 280px;
            max-height: 90px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 576px) {
            .logo-horizontal {
                max-width: 220px;
                max-height: 70px;
            }
        }

        @media (min-width: 1200px) {
            .logo-horizontal {
                max-width: 320px;
                max-height: 100px;
            }
        }

        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(46, 204, 113, 0.25);
        }

        .password-group {
            position: relative;
        }

        .password-group .form-control {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            background: transparent;
            border: none;
            z-index: 10;
            transition: color 0.3s ease;
        }

        .toggle-password:hover {
            color: var(--primary-color);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.4);
        }

        .alert {
            border-radius: 10px;
            border: none;
        }

        .login-help {
            font-size: 0.875rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6 col-xl-4">
                <div class="login-container">
                    <div class="card">
                        <div class="card-header">
                            <div class="text-center mb-4">
                                <img src="img/logo-libertyfin-white.png" alt="Logo Empresa" class="logo-horizontal">
                            </div>
                            <p class="mb-0 mt-2 opacity-75">Iniciar Sesión</p>
                        </div>
                        <div class="card-body p-4">
                            <?php if (!empty($mensaje)): ?>
                                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-exclamation-circle me-2"></i>
                                        <div><?php echo htmlspecialchars($mensaje); ?></div>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" id="loginForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                
                                <div class="mb-3">
                                    <label for="usuario" class="form-label">
                                        <i class="fas fa-user me-2"></i>Usuario o Email
                                    </label>
                                    <input type="text" class="form-control" id="usuario" name="usuario"
                                        value="<?php echo isset($_POST['usuario']) ? htmlspecialchars($_POST['usuario']) : ''; ?>"
                                        required placeholder="Ingrese su usuario o email"
                                        autocomplete="username"
                                        maxlength="100"
                                        pattern="[a-zA-Z0-9@._-]{3,100}"
                                        title="El usuario debe tener entre 3 y 100 caracteres y solo puede contener letras, números, @, ., _ y -">
                                </div>

                                <div class="mb-4">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Contraseña
                                    </label>
                                    <div class="password-group">
                                        <input type="password" class="form-control" id="password" name="password"
                                            required placeholder="Ingrese su contraseña"
                                            autocomplete="current-password"
                                            minlength="6"
                                            maxlength="255">
                                        <button type="button" class="toggle-password" id="togglePasswordBtn" tabindex="-1">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted mt-1 d-block">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Haz clic en el ojo para ver/ocultar la contraseña
                                    </small>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 btn-lg" id="submitBtn">
                                    <i class="fas fa-sign-in-alt me-2"></i>Ingresar al Sistema
                                </button>
                            </form>

                            <div class="text-center mt-4">
                                <p class="text-muted login-help mb-2">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Puedes usar tu nombre de usuario o email para ingresar
                                </p>
                                <a href="SolicitudEmpresa" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-plus me-1"></i>Registrar Nueva Empresa
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-body text-center">
                            <h6 class="mb-3">Sistema Multi-Sucursal</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-building text-primary me-1"></i>Multi-Sucursal
                                    </small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-cash-register text-info me-1"></i>Ventas Rápidas
                                    </small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-credit-card text-success me-1"></i>Múltiples Pagos
                                    </small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-chart-bar text-warning me-1"></i>Reportes
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('usuario').focus();

            const togglePasswordBtn = document.getElementById('togglePasswordBtn');
            const passwordInput = document.getElementById('password');
            const eyeIcon = togglePasswordBtn.querySelector('i');

            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                if (type === 'password') {
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                } else {
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                }
            });

            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');

            form.addEventListener('submit', function(e) {
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return;
                }
                
                const usuario = document.getElementById('usuario').value.trim();
                const password = document.getElementById('password').value;
                
                if (usuario.length < 3) {
                    e.preventDefault();
                    alert('El usuario debe tener al menos 3 caracteres');
                    return;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    alert('La contraseña debe tener al menos 6 caracteres');
                    return;
                }
                
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando...';
            });
        });
    </script>
</body>

</html>