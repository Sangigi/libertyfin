<?php
// test-correo.php - Prueba de envío de correo
session_start();

$smtp_host = "smtp.titan.email";
$smtp_username = "notificaciones@libertyfin.com.mx";
$smtp_password = "N0tific4ci0n3s.2026#";
$smtp_port = 465;

// Buscar PHPMailer
$phpmailer_paths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../PHPMailer/src/PHPMailer.php',
];

$phpmailer_cargado = false;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
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
    die("PHPMailer no encontrado");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $destinatario = $_POST['email'] ?? '';
    
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $smtp_port;
        $mail->SMTPDebug = 2; // Mostrar debug
        
        $mail->setFrom($smtp_username, 'Test LibertyFin');
        $mail->addAddress($destinatario);
        $mail->Subject = 'Prueba de correo LibertyFin';
        $mail->Body = 'Este es un correo de prueba';
        
        if ($mail->send()) {
            $mensaje = "✅ Correo enviado a $destinatario";
        } else {
            $mensaje = "❌ Error: " . $mail->ErrorInfo;
        }
    } catch (Exception $e) {
        $mensaje = "❌ Excepción: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Correo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2>Probar Envío de Correo</h2>
        
        <?php if (isset($mensaje)): ?>
            <div class="alert alert-info"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label>Email de prueba:</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Enviar Correo de Prueba</button>
        </form>
    </div>
</body>
</html>