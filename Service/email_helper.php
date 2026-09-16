<?php
/**
 * email_helper.php
 *
 * Funciones de correo compartidas para las confirmaciones de pago
 * de suscripcion (tarjeta y SPEI). Reutiliza las mismas credenciales
 * SMTP y patron de PHPMailer que ya usa registroEmpresa.php.
 */

function cargarPHPMailer() {
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return true;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        return true;
    }
    $phpmailer_path = __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    if (file_exists($phpmailer_path)) {
        require_once $phpmailer_path;
        require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
        require_once __DIR__ . '/../PHPMailer/src/Exception.php';
        return true;
    }
    error_log("PHPMailer no encontrado (email_helper.php)");
    return false;
}

/**
 * Envia el correo de confirmacion de pago de suscripcion.
 *
 * @param string $email        Correo del destinatario
 * @param string $nombreEmpresa
 * @param string $plan
 * @param string $periodo      'mensual' | 'anual'
 * @param float  $monto
 * @param string $metodo       'Tarjeta' | 'SPEI'
 * @param string $nuevaVigencia Fecha de vencimiento (Y-m-d), opcional
 */
function enviarCorreoConfirmacionPago($email, $nombreEmpresa, $plan, $periodo, $monto, $metodo, $nuevaVigencia = null) {
    if (empty($email)) {
        error_log("enviarCorreoConfirmacionPago: sin email de destino, se omite el envío");
        return false;
    }

    try {
        if (!cargarPHPMailer()) {
            return false;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.titan.email';
        $mail->SMTPAuth = true;
        $mail->Username = 'notificaciones@libertyfin.com.mx';
        $mail->Password = 'N0tific4ci0n3s.2026#';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom('notificaciones@libertyfin.com.mx', 'LibertyFin');
        $mail->addAddress($email);

        $logo_path = __DIR__ . '/../images/LibertyfinBlanco.png';
        if (file_exists($logo_path)) {
            $mail->addEmbeddedImage($logo_path, 'logo', 'LibertyfinBlanco.png');
        }

        $mail->Subject = 'Confirmación de pago - LibertyFin';
        $mail->isHTML(true);

        $vigenciaHtml = $nuevaVigencia
            ? '<p><strong>Tu servicio esta vigente hasta:</strong> ' . htmlspecialchars($nuevaVigencia) . '</p>'
            : '';

        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head><meta charset="UTF-8"></head>
        <body style="font-family: Arial, sans-serif; line-height:1.6; color:#333;">
            <div style="max-width:600px; margin:0 auto; padding:20px;">
                <div style="background:#27ae60; color:white; padding:20px; text-align:center;">
                    <h1>¡Pago confirmado!</h1>
                </div>
                <div style="padding:20px; background:#f9f9f9;">
                    <p>Hola,</p>
                    <p>Confirmamos que recibimos tu pago para <strong>' . htmlspecialchars($nombreEmpresa) . '</strong>.</p>
                    <div style="background:white; padding:15px; border:1px solid #ddd; margin:15px 0;">
                        <p><strong>Plan:</strong> ' . htmlspecialchars($plan) . '</p>
                        <p><strong>Periodo:</strong> ' . htmlspecialchars($periodo) . '</p>
                        <p><strong>Monto:</strong> $' . number_format((float)$monto, 2) . ' MXN</p>
                        <p><strong>Método de pago:</strong> ' . htmlspecialchars($metodo) . '</p>
                        ' . $vigenciaHtml . '
                    </div>
                    <p>Gracias por confiar en LibertyFin.</p>
                </div>
                <div style="text-align:center; padding:20px; color:#666; font-size:12px;">
                    LibertyFin &middot; notificaciones@libertyfin.com.mx
                </div>
            </div>
        </body>
        </html>';

        $mail->AltBody = "Confirmamos tu pago de $" . number_format((float)$monto, 2) . " MXN para el plan $plan ($periodo).";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error enviando correo de confirmación de pago: " . $e->getMessage());
        return false;
    }
}