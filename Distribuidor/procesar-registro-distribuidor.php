<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// ============================================
// CONFIGURACIONES
// ============================================

// Configuración SMTP
$smtp_host = "smtp.titan.email";
$smtp_username = "notificaciones@libertyfin.com.mx";
$smtp_password = "N0tific4ci0n3s.2026#";
$smtp_port = 465;

// Configuración de la base de datos
$db_config = [
    'host' => 'libertyfin.com.mx',
    'user' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'database' => 'juanc141_ventas'
];

$upload_dir = 'uploads/distribuidores/';
$constancia_dir = $upload_dir . 'constancias/';
$credencial_dir = $upload_dir . 'credenciales/';

// Crear directorios
foreach ([$upload_dir, $constancia_dir, $credencial_dir] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// ============================================
// CARGA DE PHPMailer (usando las rutas que funcionan en test-correo.php)
// ============================================

$phpmailer_cargado = false;
$phpmailer_paths = [
    __DIR__ . '/../vendor/autoload.php',     // Busca en la raíz del sitio
    __DIR__ . '/../PHPMailer/src/PHPMailer.php', // Busca en PHPMailer manual
    __DIR__ . '/vendor/autoload.php',        // Busca en la carpeta actual
    __DIR__ . '/PHPMailer/src/PHPMailer.php', // Busca en carpeta actual
];

foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
        error_log("PHPMailer encontrado en: " . $path);
        if (strpos($path, 'autoload.php') !== false) {
            require_once $path;
        } else {
            require_once $path;
            require_once dirname($path) . '/SMTP.php';
            require_once dirname($path) . '/Exception.php';
        }
        $phpmailer_cargado = true;
        break;
    }
}

if (!$phpmailer_cargado) {
    error_log("PHPMailer no encontrado - se usará función alternativa");
}

// ============================================
// FUNCIONES
// ============================================

function generarNumeroControl($id) {
    $numero = str_pad($id, 5, '0', STR_PAD_LEFT);
    return "LI{$numero}";
}

function generarPasswordSeguro($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-+=';
    $password = '';
    $chars_length = strlen($chars) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $chars_length)];
    }
    
    return $password;
}

function validarRFC($rfc) {
    $rfc = strtoupper(trim($rfc));
    if (empty($rfc)) return false;
    $patron = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/';
    return preg_match($patron, $rfc);
}

function validarArchivo($archivo) {
    $max_size = 5 * 1024 * 1024; // 5MB
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
    
    $file_size = $archivo['size'];
    $file_type = mime_content_type($archivo['tmp_name']);
    $file_extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    
    if ($file_size > $max_size) {
        return ['valido' => false, 'mensaje' => 'El archivo excede el tamaño máximo de 5MB'];
    }
    
    if (!in_array($file_type, $allowed_types)) {
        return ['valido' => false, 'mensaje' => 'Tipo de archivo no permitido. Solo PDF, JPG, JPEG, PNG'];
    }
    
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['valido' => false, 'mensaje' => 'Extensión no permitida. Solo .pdf, .jpg, .jpeg, .png'];
    }
    
    return ['valido' => true, 'mensaje' => 'Archivo válido'];
}

function subirArchivo($archivo, $nombre_distribuidor, $tipo, $directorio) {
    $nombre_limpio = preg_replace('/[^a-zA-Z0-9_]/', '_', $nombre_distribuidor);
    $timestamp = date('Ymd_His');
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    
    $nombre_archivo = $tipo . '_' . $nombre_limpio . '_' . $timestamp . '.' . $extension;
    $ruta_completa = $directorio . $nombre_archivo;
    
    if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
        return $nombre_archivo;
    }
    
    throw new Exception("Error al subir el archivo {$tipo}");
}

// ============================================
// FUNCIÓN PARA ENVIAR CORREO (basada en test-correo.php)
// ============================================

function enviarCorreoCredenciales($destinatario, $nombre_distribuidor, $numero_control, $password) {
    global $smtp_host, $smtp_username, $smtp_password, $smtp_port, $phpmailer_cargado;
    
    // Si no se pudo cargar PHPMailer, usar método alternativo
    if (!$phpmailer_cargado) {
        error_log("PHPMailer no cargado, usando método alternativo");
        return enviarCorreoAlternativo($destinatario, $nombre_distribuidor, $numero_control, $password);
    }
    
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuración SMTP (exactamente igual que en test-correo.php)
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $smtp_port;
        $mail->SMTPDebug = 0; // 0 para producción, 2 para debug
        
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        // Remitente y destinatario
        $mail->setFrom($smtp_username, 'LibertyFin - Distribuidores');
        $mail->addAddress($destinatario, $nombre_distribuidor);
        $mail->addReplyTo($smtp_username, 'Soporte LibertyFin');
        
        // Asunto
        $mail->Subject = '¡Bienvenido a LibertyFin! - Tus credenciales de acceso';
        
        // Cuerpo HTML
        $mail->isHTML(true);
        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #27ae60; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #f9f9f9; border: 1px solid #ddd; }
                .credentials { background: white; padding: 20px; border: 2px solid #27ae60; border-radius: 10px; margin: 20px 0; }
                .credential-box { background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 10px 0; }
                .control-number { font-size: 24px; color: #27ae60; font-weight: bold; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                .btn { background: #27ae60; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>¡Bienvenido a LibertyFin!</h1>
                    <p>Tu registro como distribuidor ha sido exitoso</p>
                </div>
                <div class="content">
                    <h2>Hola ' . htmlspecialchars($nombre_distribuidor) . ',</h2>
                    <p>Te damos la bienvenida a nuestra red de distribuidores. Tu cuenta ha sido creada exitosamente.</p>
                    
                    <div class="credentials">
                        <h3 style="color: #27ae60; text-align: center;">🔐 TUS CREDENCIALES DE ACCESO</h3>
                        
                        <div class="credential-box">
                            <p><strong>📋 Número de Control:</strong><br>
                            <span class="control-number">' . htmlspecialchars($numero_control) . '</span></p>
                            
                            <p><strong>🔑 Contraseña:</strong><br>
                            <span style="font-size: 18px;">' . htmlspecialchars($password) . '</span></p>
                        </div>
                        
                        <p style="text-align: center;">
                            <a href="https://libertyfin.com.mx/Distribuidor/login-distribuidor.php" class="btn">INICIAR SESIÓN</a>
                        </p>
                    </div>
                    
                    <div style="background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <p><strong>⚠️ IMPORTANTE:</strong></p>
                        <ul>
                            <li>Guarda este número de control, lo necesitarás para iniciar sesión</li>
                            <li>Por seguridad, cambia tu contraseña en el primer acceso</li>
                            <li>Tus documentos serán verificados en las próximas 24-48 horas</li>
                        </ul>
                    </div>
                    
                    <p><strong>📞 Soporte técnico:</strong><br>
                    Email: contacto@libertyfin.com.mx<br>
                    Teléfono: (55) 1234 5678</p>
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' LibertyFin - Todos los derechos reservados</p>
                    <p>Este es un correo automático, por favor no respondas a este mensaje.</p>
                </div>
            </div>
        </body>
        </html>';
        
        // Versión texto plano
        $mail->AltBody = "BIENVENIDO A LIBERTYFIN\n\n" .
            "Hola " . $nombre_distribuidor . ",\n\n" .
            "Tu registro como distribuidor ha sido exitoso.\n\n" .
            "TUS CREDENCIALES DE ACCESO:\n" .
            "============================\n" .
            "Número de Control: " . $numero_control . "\n" .
            "Contraseña: " . $password . "\n" .
            "Email: " . $destinatario . "\n\n" .
            "URL de acceso: https://libertyfin.com.mx/Distribuidor/login-distribuidor.php\n\n" .
            "IMPORTANTE:\n" .
            "- Guarda este número de control\n" .
            "- Cambia tu contraseña en el primer acceso\n" .
            "- Tus documentos serán verificados en 24-48 horas\n\n" .
            "SOPORTE:\n" .
            "Email: contacto@libertyfin.com.mx\n" .
            "Teléfono: (55) 1234 5678\n\n" .
            "© " . date('Y') . " LibertyFin";
        
        if ($mail->send()) {
            error_log("✅ Correo enviado a: " . $destinatario);
            return true;
        } else {
            error_log("❌ Error al enviar correo: " . $mail->ErrorInfo);
            return enviarCorreoAlternativo($destinatario, $nombre_distribuidor, $numero_control, $password);
        }
        
    } catch (Exception $e) {
        error_log("❌ Excepción al enviar correo: " . $e->getMessage());
        return enviarCorreoAlternativo($destinatario, $nombre_distribuidor, $numero_control, $password);
    }
}

// ============================================
// FUNCIÓN ALTERNATIVA (respaldo)
// ============================================

function enviarCorreoAlternativo($destinatario, $nombre_distribuidor, $numero_control, $password) {
    $asunto = "=?UTF-8?B?" . base64_encode('¡Bienvenido a LibertyFin! - Tus credenciales') . "?=";
    
    $mensaje = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Bienvenido a LibertyFin</title>
    </head>
    <body>
        <div style='max-width:600px;margin:0 auto;font-family:Arial,sans-serif;'>
            <div style='background:#27ae60;color:white;padding:20px;text-align:center;'>
                <h2>¡Bienvenido a LibertyFin!</h2>
            </div>
            <div style='padding:20px;background:#f9f9f9;'>
                <h3>Hola $nombre_distribuidor,</h3>
                <p>Tu registro como distribuidor ha sido exitoso.</p>
                
                <div style='background:white;padding:20px;border:2px solid #27ae60;border-radius:10px;margin:20px 0;'>
                    <h3 style='color:#27ae60;'>Tus credenciales:</h3>
                    <p><strong>Número de Control:</strong> <span style='font-size:20px;color:#27ae60;'>$numero_control</span></p>
                    <p><strong>Contraseña:</strong> $password</p>
                    <p><strong>Email:</strong> $destinatario</p>
                </div>
                
                <p><a href='https://libertyfin.com.mx/Distribuidor/login-distribuidor.php' style='background:#27ae60;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;'>Iniciar Sesión</a></p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $cabeceras = "MIME-Version: 1.0\r\n";
    $cabeceras .= "Content-type: text/html; charset=UTF-8\r\n";
    $cabeceras .= "From: notificaciones@libertyfin.com.mx\r\n";
    $cabeceras .= "Reply-To: notificaciones@libertyfin.com.mx\r\n";
    
    if (mail($destinatario, $asunto, $mensaje, $cabeceras)) {
        error_log("✅ Correo alternativo enviado a: " . $destinatario);
        return true;
    } else {
        error_log("❌ Error en correo alternativo");
        return false;
    }
}

// ============================================
// CONEXIÓN A BASE DE DATOS
// ============================================

$conn = new mysqli(
    $db_config['host'],
    $db_config['user'],
    $db_config['password'],
    $db_config['database']
);

if ($conn->connect_error) {
    $_SESSION['mensaje'] = "Error de conexión a la base de datos.";
    $_SESSION['mensaje_tipo'] = "danger";
    header("Location: registro-distribuidor.php");
    exit;
}

$conn->set_charset("utf8mb4");

// ============================================
// PROCESAR REGISTRO
// ============================================

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: registro-distribuidor.php");
    exit;
}

// Recoger datos
$nombre_distribuidor = isset($_POST['nombre_distribuidor']) ? trim($_POST['nombre_distribuidor']) : '';
$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$rfc = isset($_POST['rfc']) ? strtoupper(trim($_POST['rfc'])) : '';
$declaracion_veracidad = isset($_POST['declaracion_veracidad']) ? true : false;

// Validar datos obligatorios
if (empty($nombre_distribuidor) || empty($telefono) || empty($email) || empty($rfc)) {
    $_SESSION['mensaje'] = "Todos los campos obligatorios deben ser completados.";
    $_SESSION['mensaje_tipo'] = "danger";
    header("Location: registro-distribuidor.php");
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mensaje'] = "Por favor, ingrese un correo electrónico válido.";
    $_SESSION['mensaje_tipo'] = "danger";
    header("Location: registro-distribuidor.php");
    exit;
}

if (!validarRFC($rfc)) {
    $_SESSION['mensaje'] = "El RFC ingresado no es válido.";
    $_SESSION['mensaje_tipo'] = "danger";
    header("Location: registro-distribuidor.php");
    exit;
}

if (!$declaracion_veracidad) {
    $_SESSION['mensaje'] = "Debe aceptar la declaración de veracidad.";
    $_SESSION['mensaje_tipo'] = "danger";
    header("Location: registro-distribuidor.php");
    exit;
}

// Validar archivos
$archivos_validos = true;
$error_archivos = "";

// Validar credencial
if (!isset($_FILES['credencial_identificacion']) || $_FILES['credencial_identificacion']['error'] == UPLOAD_ERR_NO_FILE) {
    $archivos_validos = false;
    $error_archivos .= "La Credencial de Identificación es obligatoria. ";
} elseif ($_FILES['credencial_identificacion']['error'] != UPLOAD_ERR_OK) {
    $archivos_validos = false;
    $error_archivos .= "Error al subir la Credencial de Identificación. ";
} else {
    $credencial_info = validarArchivo($_FILES['credencial_identificacion']);
    if (!$credencial_info['valido']) {
        $archivos_validos = false;
        $error_archivos .= "Credencial: " . $credencial_info['mensaje'] . " ";
    }
}

// Validar constancia
if (!isset($_FILES['constancia_fiscal']) || $_FILES['constancia_fiscal']['error'] == UPLOAD_ERR_NO_FILE) {
    $archivos_validos = false;
    $error_archivos .= "La Constancia Fiscal es obligatoria. ";
} elseif ($_FILES['constancia_fiscal']['error'] != UPLOAD_ERR_OK) {
    $archivos_validos = false;
    $error_archivos .= "Error al subir la Constancia Fiscal. ";
} else {
    $constancia_info = validarArchivo($_FILES['constancia_fiscal']);
    if (!$constancia_info['valido']) {
        $archivos_validos = false;
        $error_archivos .= "Constancia Fiscal: " . $constancia_info['mensaje'] . " ";
    }
}

if (!$archivos_validos) {
    $_SESSION['mensaje'] = $error_archivos;
    $_SESSION['mensaje_tipo'] = "danger";
    header("Location: registro-distribuidor.php");
    exit;
}

// Procesar registro
try {
    $conn->begin_transaction();
    
    // Verificar email duplicado
    $stmt_check = $conn->prepare("SELECT id FROM distribuidores WHERE email = ?");
    $stmt_check->bind_param("s", $email);
    $stmt_check->execute();
    $stmt_check->store_result();
    
    if ($stmt_check->num_rows > 0) {
        throw new Exception("El correo electrónico ya está registrado como distribuidor.");
    }
    $stmt_check->close();
    
    // Generar contraseña
    $password_plano = generarPasswordSeguro(12);
    $password_hash = password_hash($password_plano, PASSWORD_DEFAULT);
    
    // Subir archivos
    $now = date('Y-m-d H:i:s');
    
    $credencial_archivo = subirArchivo(
        $_FILES['credencial_identificacion'], 
        $nombre_distribuidor, 
        'credencial', 
        $credencial_dir
    );
    
    $constancia_archivo = subirArchivo(
        $_FILES['constancia_fiscal'], 
        $nombre_distribuidor, 
        'constancia', 
        $constancia_dir
    );
    
    // Insertar distribuidor
    $sql_insert = "INSERT INTO distribuidores (
        nombre_distribuidor, telefono, email, rfc,
        password, constancia_fiscal, credencial_identificacion,
        fecha_subida_constancia, fecha_subida_credencial, declaracion_veracidad
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_insert = $conn->prepare($sql_insert);
    $stmt_insert->bind_param(
        "sssssssssi",
        $nombre_distribuidor,
        $telefono,
        $email,
        $rfc,
        $password_hash,
        $constancia_archivo,
        $credencial_archivo,
        $now,
        $now,
        $declaracion_veracidad
    );
    
    if (!$stmt_insert->execute()) {
        throw new Exception("Error al guardar el registro: " . $conn->error);
    }
    
    $distribuidor_id = $stmt_insert->insert_id;
    
    // Generar número de control
    $numero_control = generarNumeroControl($distribuidor_id);
    
    // Actualizar número de control
    $stmt_update = $conn->prepare("UPDATE distribuidores SET numero_control = ? WHERE id = ?");
    $stmt_update->bind_param("si", $numero_control, $distribuidor_id);
    
    if (!$stmt_update->execute()) {
        throw new Exception("Error al generar número de control");
    }
    
    // Enviar correo
    $correo_enviado = enviarCorreoCredenciales($email, $nombre_distribuidor, $numero_control, $password_plano);
    
    if ($correo_enviado) {
        $stmt_correo = $conn->prepare("UPDATE distribuidores SET correo_enviado = TRUE, fecha_envio_correo = NOW() WHERE id = ?");
        $stmt_correo->bind_param("i", $distribuidor_id);
        $stmt_correo->execute();
        $stmt_correo->close();
    }
    
    $conn->commit();
    
    $mensaje_adicional = "";
    if (!$correo_enviado) {
        $mensaje_adicional = "<br><br><div class='alert alert-warning'>
            <strong>⚠️ No se pudo enviar el correo, pero aquí están tus credenciales:</strong><br>
            Número de Control: <strong>$numero_control</strong><br>
            Contraseña: <strong>$password_plano</strong><br>
            <em>Guarda esta información en un lugar seguro.</em>
        </div>";
    }
    
    $_SESSION['mensaje'] = "¡Registro exitoso!<br>Se ha enviado un correo a <strong>{$email}</strong> con tus credenciales de acceso.<br>Tu número de control es: <strong>{$numero_control}</strong><br>Contraseña: <strong>$password_plano</strong>" . $mensaje_adicional;
    $_SESSION['mensaje_tipo'] = "success";
    
    $stmt_insert->close();
    $stmt_update->close();
    
} catch (Exception $e) {
    $conn->rollback();
    
    // Eliminar archivos subidos
    if (isset($constancia_archivo) && file_exists($constancia_dir . $constancia_archivo)) {
        unlink($constancia_dir . $constancia_archivo);
    }
    if (isset($credencial_archivo) && file_exists($credencial_dir . $credencial_archivo)) {
        unlink($credencial_dir . $credencial_archivo);
    }
    
    $_SESSION['mensaje'] = "Error en el registro: " . $e->getMessage();
    $_SESSION['mensaje_tipo'] = "danger";
    
    error_log("Error en registro distribuidor: " . $e->getMessage());
}

$conn->close();
header("Location: registro-distribuidor.php");
exit;
?>