<?php
/**
 * mailer_pagos.php
 * ------------------------------------------------------------
 * Todo lo relacionado con el envío de correos de pago:
 *   - Catálogo de planes (PLANES_DATA)
 *   - Función enviarCorreoConfirmacionPago()
 *
 * Estilos alineados con cron_envio_correos_pago.php (verde Libertyfin)
 */

if (!defined('PLANES_DATA')) {
    define('PLANES_DATA', [
        'basico' => [
            'nombre'         => 'Básico',
            'precio_mensual' => 299,
            'precio_anual'   => 239,
            'usuarios'       => 1,
            'cajas'          => 1,
            'productos'      => '100',
        ],
        'profesional' => [
            'nombre'         => 'Profesional',
            'precio_mensual' => 599,
            'precio_anual'   => 479,
            'usuarios'       => 4,
            'cajas'          => 2,
            'productos'      => '500',
        ],
        'empresarial' => [
            'nombre'         => 'Empresarial',
            'precio_mensual' => 999,
            'precio_anual'   => 799,
            'usuarios'       => 6,
            'cajas'          => 3,
            'productos'      => '500',
            'sucursales'     => 1,
        ],
        'plus' => [
            'nombre'         => 'Empresarial Plus',
            'precio_mensual' => 1499,
            'precio_anual'   => 1199,
            'usuarios'       => 10,
            'cajas'          => 10,
            'productos'      => 'Ilimitados',
            'sucursales'     => 3,
            'timbres'        => 500,
        ],
    ]);
}

// Nombre visible del remitente
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'Libertyfin');

// Asegurar autoload de Composer (PHPMailer)
if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
}


/**
 * Envía el correo de confirmación de pago al cliente.
 */
function enviarCorreoConfirmacionPago(array $empresa, string $planKey, string $periodo, $monto, array $pago): bool
{
    $emailDestino = trim($pago['email'] ?? '');
    if ($emailDestino === '' || !filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $planKey = strtolower(trim($planKey));
    if (!isset(PLANES_DATA[$planKey])) {
        $planKey = 'empresarial';
    }
    $plan = PLANES_DATA[$planKey];

    $esAnual     = (stripos($periodo, 'anual') !== false);
    $periodoTxt  = $esAnual ? 'Anual' : 'Mensual';
    $vigenciaTxt = $esAnual ? '12 meses' : '1 mes';

    $subject = "Confirmación de pago - Plan {$plan['nombre']} ({$periodoTxt})";
    $html    = construirHtmlConfirmacion($empresa, $plan, $periodoTxt, $vigenciaTxt, $monto, $pago);

    return enviarHtml($emailDestino, $subject, $html);
}


/**
 * Construye el HTML del correo con los estilos de Libertyfin (verde).
 */
function construirHtmlConfirmacion(array $empresa, array $plan, string $periodoTxt, string $vigenciaTxt, $monto, array $pago): string
{
    $nombreEmpresa = htmlspecialchars($empresa['nombre_empresa'] ?? 'Cliente', ENT_QUOTES, 'UTF-8');
    $fechaPago     = htmlspecialchars($pago['fecha_pago_legible'] ?? date('d/m/Y H:i'));
    $folio         = htmlspecialchars($pago['foliocpagos'] ?? 'N/A');
    $auth          = htmlspecialchars($pago['auth'] ?? 'N/A');
    $ccMask        = htmlspecialchars($pago['cc_mask'] ?? '****');
    $referencia    = htmlspecialchars($pago['reference'] ?? '');
    $montoFmt      = '$' . number_format((float)$monto, 2, '.', ',');

    // Extras (sucursales / timbres)
    $extrasHtml = '';
    if (isset($plan['sucursales'])) {
        $extrasHtml .= '<div class="detail-item">'
                     . '<span class="detail-label">🏢 Sucursales</span>'
                     . '<span class="detail-value">' . (int)$plan['sucursales'] . '</span>'
                     . '</div>';
    }
    if (isset($plan['timbres'])) {
        $extrasHtml .= '<div class="detail-item">'
                     . '<span class="detail-label">🧾 Timbres (CFDI)</span>'
                     . '<span class="detail-value">' . (int)$plan['timbres'] . ' al mes</span>'
                     . '</div>';
    }

    $anio        = date('Y');
    $replyTo     = defined('MAIL_REPLY_TO') ? MAIL_REPLY_TO : 'ventas@libertyfin.com.mx';
    $replyToHtml = htmlspecialchars($replyTo, ENT_QUOTES, 'UTF-8');
    $replyToTxt  = htmlspecialchars($replyTo, ENT_QUOTES, 'UTF-8');

    // Rutas de logos
    $logoWhitePath = __DIR__ . '/../img/logo-libertyfin-white.png';
    $logoPath      = __DIR__ . '/../img/logo-libertyfin.png';
    $logoWhiteCid  = 'logo_libertyfin_white';
    $logoCid       = 'logo_libertyfin';

    // Registramos las rutas de logos en constantes globales para que enviarHtml las incruste
    $GLOBALS['__mailer_embedded_images'] = [];
    if (file_exists($logoWhitePath)) {
        $GLOBALS['__mailer_embedded_images'][$logoWhiteCid] = ['path' => $logoWhitePath, 'name' => 'logo-libertyfin-white.png'];
    }
    if (file_exists($logoPath)) {
        $GLOBALS['__mailer_embedded_images'][$logoCid] = ['path' => $logoPath, 'name' => 'logo-libertyfin.png'];
    }

    $headerLogo = isset($GLOBALS['__mailer_embedded_images'][$logoWhiteCid])
        ? '<img src="cid:' . $logoWhiteCid . '" alt="Libertyfin" class="logo-img">'
        : '<div style="font-size: 28px; font-weight: 700; color: #FFFFFF;">Liberty<span style="font-weight: 300;">fin</span></div>';

    $footerLogo = isset($GLOBALS['__mailer_embedded_images'][$logoCid])
        ? '<img src="cid:' . $logoCid . '" alt="Libertyfin" class="logo-footer">'
        : '<div style="font-weight: 700; color: #10B981; font-size: 16px;">Liberty<span style="font-weight: 300;">fin</span></div>';

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirmación de pago - Libertyfin</title>
<style>
    body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #F7FAFC; color: #1A202C; line-height: 1.6; }
    .container { max-width: 580px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.12); overflow: hidden; }
    .header { background: #10B981; padding: 32px 40px 28px; text-align: center; }
    .header .logo-img { max-width: 180px; height: auto; }
    .header .tagline { font-size: 14px; color: rgba(255,255,255,0.90); margin-top: 8px; }
    .content { padding: 32px 40px 20px; background: #FFFFFF; }
    .content h1 { font-size: 22px; font-weight: 700; color: #1A202C; margin: 0 0 12px 0; }
    .highlight-text { font-size: 16px; color: #4A5568; margin-bottom: 24px; }
    .message-box { background: #F0FDF4; border-radius: 8px; padding: 20px 24px; margin: 20px 0; border-left: 5px solid #10B981; }
    .section-title { margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #1A202C; }
    .detail-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #EDF2F7; }
    .detail-item:last-child { border-bottom: none; }
    .detail-label { color: #718096; font-size: 14px; font-weight: 500; min-width: 120px; }
    .detail-value { font-weight: 600; color: #1A202C; font-size: 15px; text-align: right; }
    .plan-badge { display: inline-block; background: #10B981; color: #FFFFFF; padding: 4px 18px; border-radius: 20px; font-size: 13px; font-weight: 600; }
    .monto-highlight { color: #10B981; font-size: 18px; font-weight: 700; }
    .btn-container { margin: 28px 0 10px; text-align: center; }
    .btn-primary { display: inline-block; padding: 14px 36px; background: #10B981; color: #FFFFFF !important; text-decoration: none; border-radius: 50px; font-weight: 600; font-size: 16px; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); }
    .btn-primary:hover { background: #059669; }
    .footer { padding: 24px 40px 28px; font-size: 13px; color: #A0AEC0; border-top: 1px solid #EDF2F7; background: #FAFBFC; text-align: center; }
    .footer .logo-footer { max-width: 120px; height: auto; display: inline-block; margin-bottom: 6px; }
    .footer .divider { width: 40px; height: 2px; background: #10B981; margin: 12px auto 16px; border-radius: 2px; }
    .footer .legal { font-size: 11px; color: #A0AEC0; line-height: 1.6; margin-top: 16px; padding-top: 16px; border-top: 1px solid #EDF2F7; }
    .footer .legal a { color: #718096; text-decoration: underline; }
    .footer .legal .copyright { margin-top: 6px; font-size: 11px; color: #A0AEC0; }
    @media only screen and (max-width: 480px) {
        body { padding: 10px; }
        .header { padding: 24px 20px 20px; }
        .header .logo-img { max-width: 140px; }
        .content { padding: 24px 20px 16px; }
        .content h1 { font-size: 19px; }
        .detail-item { flex-direction: column; align-items: flex-start; gap: 4px; padding: 10px 0; }
        .detail-label { min-width: auto; font-size: 13px; }
        .detail-value { text-align: left; font-size: 14px; width: 100%; }
        .btn-primary { display: block; text-align: center; padding: 14px 20px; }
    }
</style>
</head>
<body>
    <div class="container">
        <div class="header">
            {$headerLogo}
            <div class="tagline">Vende más, administra menos desde un solo lugar</div>
        </div>
        <div class="content">
            <h1>✅ ¡Pago confirmado!</h1>
            <p class="highlight-text">Hola <strong>{$nombreEmpresa}</strong>, hemos recibido tu pago correctamente. Tu plan <strong>{$plan['nombre']}</strong> ({$periodoTxt}) ya se encuentra activo por los próximos <strong>{$vigenciaTxt}</strong>.</p>

            <div class="message-box">
                <p class="section-title">📋 Detalles del pago</p>
                <div class="detail-item">
                    <span class="detail-label">📅 Fecha</span>
                    <span class="detail-value">{$fechaPago}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">🔢 Referencia</span>
                    <span class="detail-value">{$referencia}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">📄 Folio</span>
                    <span class="detail-value">{$folio}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">🔐 Autorización</span>
                    <span class="detail-value">{$auth}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">💳 Tarjeta</span>
                    <span class="detail-value">{$ccMask}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">💰 Total pagado</span>
                    <span class="detail-value"><span class="monto-highlight">{$montoFmt} MXN</span></span>
                </div>
            </div>

            <div class="message-box">
                <p class="section-title">🎯 Plan {$plan['nombre']} — {$periodoTxt}</p>
                <div class="detail-item">
                    <span class="detail-label">👤 Usuarios</span>
                    <span class="detail-value">{$plan['usuarios']}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">🖥️ Cajas</span>
                    <span class="detail-value">{$plan['cajas']}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">📦 Productos</span>
                    <span class="detail-value">{$plan['productos']}</span>
                </div>
                {$extrasHtml}
            </div>

            <p style="font-size: 15px; color: #4A5568; margin: 20px 0 8px;">
                Si tienes alguna duda, contáctanos en <a href="mailto:{$replyToHtml}" style="color: #10B981;">{$replyToTxt}</a>.
            </p>
            <div class="btn-container">
                <a href="https://www.libertyfin.com.mx/mi-cuenta" class="btn-primary">Ir a mi cuenta</a>
            </div>
        </div>
        <div class="footer">
            {$footerLogo}
            <div class="divider"></div>
            <div class="legal">
                <p style="margin: 0 0 4px 0;">
                    Consulta nuestro aviso de privacidad en 
                    <a href="https://www.libertyfin.com.mx/pages/aviso-privacidad" target="_blank">www.libertyfin.com.mx/pages/aviso-privacidad</a>
                </p>
                <div class="copyright">
                    © {$anio} Libertyfin. Todos los derechos reservados.<br>
                    Este es un correo automático, por favor no respondas directamente.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
}


/**
 * Envía un correo HTML usando PHPMailer con la configuración SMTP de Libertyfin.
 * Incrusta los logos si fueron registrados por construirHtmlConfirmacion().
 */
function enviarHtml(string $to, string $subject, string $html): bool
{
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('[mailer_pagos] PHPMailer no está disponible (falta vendor/autoload.php).');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = env('SMTP_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = env('SMTP_USERNAME');
        $mail->Password   = env('SMTP_PASSWORD');
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = (int) env('SMTP_PORT', 465);

        $mail->SMTPDebug = 0;
        $mail->CharSet   = 'UTF-8';
        $mail->Encoding  = 'base64';

        $mail->setFrom(env('SMTP_USERNAME'), MAIL_FROM_NAME);

        // Reply-To opcional
        if (defined('MAIL_REPLY_TO') && filter_var(MAIL_REPLY_TO, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
        }

        // Incrustar logos registrados por construirHtmlConfirmacion()
        if (!empty($GLOBALS['__mailer_embedded_images'])) {
            foreach ($GLOBALS['__mailer_embedded_images'] as $cid => $info) {
                if (!empty($info['path']) && file_exists($info['path'])) {
                    try {
                        $mail->addEmbeddedImage(
                            $info['path'],
                            $cid,
                            $info['name'] ?? basename($info['path']),
                            'base64',
                            'image/png'
                        );
                    } catch (\Throwable $e) {
                        error_log('[mailer_pagos] Error al incrustar logo ' . $cid . ': ' . $e->getMessage());
                    }
                }
            }
        }

        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)));

        $ok = $mail->send();

        // Limpiar imágenes incrustadas para la siguiente llamada
        $GLOBALS['__mailer_embedded_images'] = [];

        return $ok;
    } catch (\Throwable $e) {
        error_log('[mailer_pagos] Error PHPMailer: ' . $e->getMessage());
        $GLOBALS['__mailer_embedded_images'] = [];
        return false;
    }
}