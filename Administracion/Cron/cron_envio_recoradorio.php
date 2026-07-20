<?php
// /scripts/verificar_vencimiento.php

// Configurar zona horaria
date_default_timezone_set('America/Mexico_City');

// ============================================
// CARGAR CONFIGURACIONES
// ============================================

require_once __DIR__ . '/../../env_loader.php';
require_once __DIR__ . '/../../config/database.php';

// ============================================
// FUNCIÓN PARA ENVIAR CORREO CON PHPMailer
// ============================================

function enviarCorreoVencimiento($destinatario, $nombre_destinatario, $nombre_empresa, $fecha_vencimiento, $plan) {
    try {
        // Verificar si PHPMailer está disponible
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $phpmailer_path = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($phpmailer_path)) {
                require_once $phpmailer_path;
            } else {
                $phpmailer_path = __DIR__ . '/../../PHPMailer/src/PHPMailer.php';
                if (file_exists($phpmailer_path)) {
                    require_once $phpmailer_path;
                    require_once __DIR__ . '/../../PHPMailer/src/SMTP.php';
                    require_once __DIR__ . '/../../PHPMailer/src/Exception.php';
                } else {
                    error_log("❌ PHPMailer no encontrado");
                    return false;
                }
            }
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Configuración SMTP usando variables de entorno
        $mail->isSMTP();
        $mail->Host = env('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = env('SMTP_USERNAME');
        $mail->Password = env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = (int)env('SMTP_PORT', 465);

        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // Remitente y destinatario
        $mail->setFrom(env('SMTP_USERNAME'), 'Libertyfin');
        $mail->addAddress($destinatario, $nombre_destinatario);

        $fecha_formateada = date('d/m/Y', strtotime($fecha_vencimiento));
        
        // Asunto
        $mail->Subject = "⏳ Su suscripción vence en 5 días - " . $nombre_empresa;

        // ============================================
        // LOGOS - Verificar y adjuntar
        // ============================================
        
        // Logo blanco para header (fondo verde)
        $logo_white_path = __DIR__ . '/../../img/logo-libertyfin-white.png';
        $logo_white_embedded = false;
        $logo_white_cid = 'logo_libertyfin_white';
        
        if (file_exists($logo_white_path)) {
            try {
                $mail->addEmbeddedImage($logo_white_path, $logo_white_cid, 'logo-libertyfin-white.png', 'base64', 'image/png');
                $logo_white_embedded = true;
                error_log("✅ Logo blanco embebido correctamente: " . $logo_white_path);
            } catch (Exception $e) {
                error_log("⚠️ Error al embeker logo blanco: " . $e->getMessage());
            }
        } else {
            error_log("⚠️ Logo blanco no encontrado en: " . $logo_white_path);
        }

        // Logo normal para footer (fondo blanco)
        $logo_path = __DIR__ . '/../../img/logo-libertyfin.png';
        $logo_embedded = false;
        $logo_cid = 'logo_libertyfin';
        
        if (file_exists($logo_path)) {
            try {
                $mail->addEmbeddedImage($logo_path, $logo_cid, 'logo-libertyfin.png', 'base64', 'image/png');
                $logo_embedded = true;
                error_log("✅ Logo normal embebido correctamente: " . $logo_path);
            } catch (Exception $e) {
                error_log("⚠️ Error al embeker logo normal: " . $e->getMessage());
            }
        } else {
            error_log("⚠️ Logo normal no encontrado en: " . $logo_path);
        }

        // Mensaje HTML - Estilo Libertyfin en VERDE con logos
        $mail->isHTML(true);
        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Aviso de Vencimiento Libertyfin</title>
            <style>
                /* ============================================
                   ESTILOS BASE Y REINICIO
                   ============================================ */
                body {
                    margin: 0;
                    padding: 20px;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                    background-color: #F7FAFC;
                    color: #1A202C;
                    line-height: 1.6;
                }

                /* ============================================
                   CONTENEDOR PRINCIPAL
                   ============================================ */
                .container {
                    max-width: 580px;
                    margin: 0 auto;
                    background: #FFFFFF;
                    border-radius: 12px;
                    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.12);
                    overflow: hidden;
                }

                /* ============================================
                   HEADER - Con el VERDE característico de Libertyfin
                   ============================================ */
                .header {
                    background: #10B981;
                    padding: 32px 40px 28px;
                    text-align: center;
                }

                .header .logo-img {
                    max-width: 180px;
                    height: auto;
                    display: inline-block;
                }

                .header .tagline {
                    font-size: 14px;
                    color: rgba(255, 255, 255, 0.90);
                    margin-top: 8px;
                    font-weight: 400;
                }

                /* ============================================
                   CONTENIDO
                   ============================================ */
                .content {
                    padding: 32px 40px 20px;
                    background: #FFFFFF;
                }

                .content h1 {
                    font-size: 22px;
                    font-weight: 700;
                    color: #1A202C;
                    margin: 0 0 12px 0;
                    letter-spacing: -0.3px;
                }

                .content .highlight-text {
                    font-size: 16px;
                    color: #4A5568;
                    margin-bottom: 24px;
                }

                .content .highlight-text strong {
                    color: #10B981;
                }

                /* ============================================
                   CAJA DE MENSAJE - Con borde verde
                   ============================================ */
                .message-box {
                    background: #F0FDF4;
                    border-radius: 8px;
                    padding: 20px 24px;
                    margin: 20px 0;
                    border-left: 5px solid #10B981;
                }

                .message-box p {
                    margin: 0 0 8px 0;
                    font-size: 15px;
                    color: #1A202C;
                }

                .message-box p:last-child {
                    margin-bottom: 0;
                }

                .message-box strong {
                    color: #1A202C;
                }

                /* ============================================
                   DETALLES DE LA SUSCRIPCIÓN
                   ============================================ */
                .details-box {
                    margin: 24px 0;
                    padding: 0;
                }

                .detail-item {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 12px 0;
                    border-bottom: 1px solid #EDF2F7;
                }

                .detail-item:last-child {
                    border-bottom: none;
                }

                .detail-label {
                    color: #718096;
                    font-size: 14px;
                    font-weight: 500;
                    min-width: 120px;
                }

                .detail-value {
                    font-weight: 600;
                    color: #1A202C;
                    font-size: 15px;
                    text-align: right;
                }

                /* Badge del plan - Verde Libertyfin */
                .detail-value .plan-badge {
                    display: inline-block;
                    background: #10B981;
                    color: #FFFFFF;
                    padding: 4px 18px;
                    border-radius: 20px;
                    font-size: 13px;
                    font-weight: 600;
                }

                /* Fecha de vencimiento - Rojo suave */
                .detail-value .warning-date {
                    color: #E53E3E;
                    font-weight: 700;
                    font-size: 15px;
                }

                /* ============================================
                   TEXTO DEL MENSAJE (Tomado de Libertyfin)
                   ============================================ */
                .message-text {
                    font-size: 15px;
                    color: #4A5568;
                    margin: 20px 0 8px;
                    line-height: 1.7;
                }

                .message-text strong {
                    color: #1A202C;
                }

                .message-text a {
                    color: #10B981;
                    text-decoration: none;
                    font-weight: 600;
                }

                .message-text a:hover {
                    text-decoration: underline;
                }

                /* ============================================
                   BOTÓN DE ACCIÓN - Verde Libertyfin
                   ============================================ */
                .btn-container {
                    margin: 28px 0 10px;
                    text-align: center;
                }

                .btn-primary {
                    display: inline-block;
                    padding: 14px 36px;
                    background: #10B981;
                    color: #FFFFFF !important;
                    text-decoration: none;
                    border-radius: 50px;
                    font-weight: 600;
                    font-size: 16px;
                    transition: background 0.2s ease;
                    box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
                }

                .btn-primary:hover {
                    background: #059669;
                    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
                }

                /* ============================================
                   FOOTER - Con logo normal y aviso de privacidad
                   ============================================ */
                .footer {
                    padding: 24px 40px 28px;
                    font-size: 13px;
                    color: #A0AEC0;
                    border-top: 1px solid #EDF2F7;
                    background: #FAFBFC;
                    text-align: center;
                }

                .footer .logo-footer {
                    max-width: 120px;
                    height: auto;
                    display: inline-block;
                    margin-bottom: 6px;
                }

                .footer .address {
                    font-size: 13px;
                    color: #718096;
                    line-height: 1.5;
                    margin: 4px 0 8px;
                }

                .footer .divider {
                    width: 40px;
                    height: 2px;
                    background: #10B981;
                    margin: 12px auto 16px;
                    border-radius: 2px;
                }

                .footer .unsubscribe {
                    font-size: 12px;
                    color: #A0AEC0;
                    margin-top: 14px;
                }

                .footer .unsubscribe a {
                    color: #718096;
                    text-decoration: underline;
                }

                .footer .unsubscribe a:hover {
                    color: #10B981;
                }

                /* ============================================
                   AVISO DE PRIVACIDAD Y COPYRIGHT
                   ============================================ */
                .footer .legal {
                    font-size: 11px;
                    color: #A0AEC0;
                    line-height: 1.6;
                    margin-top: 16px;
                    padding-top: 16px;
                    border-top: 1px solid #EDF2F7;
                }

                .footer .legal a {
                    color: #718096;
                    text-decoration: underline;
                }

                .footer .legal a:hover {
                    color: #10B981;
                }

                .footer .legal .copyright {
                    margin-top: 6px;
                    font-size: 11px;
                    color: #A0AEC0;
                }

                .footer .legal .auto-msg {
                    font-size: 11px;
                    color: #A0AEC0;
                    margin-top: 4px;
                    font-style: italic;
                }

                /* ============================================
                   RESPONSIVE PARA DISPOSITIVOS MÓVILES
                   ============================================ */
                @media only screen and (max-width: 480px) {
                    body { padding: 10px; }
                    .header { padding: 24px 20px 20px; }
                    .header .logo-img { max-width: 140px; }
                    .content { padding: 24px 20px 16px; }
                    .content h1 { font-size: 19px; }
                    .detail-item { 
                        flex-direction: column;
                        align-items: flex-start;
                        gap: 4px;
                        padding: 10px 0;
                    }
                    .detail-label { 
                        min-width: auto;
                        font-size: 13px;
                    }
                    .detail-value {
                        text-align: left;
                        font-size: 14px;
                        width: 100%;
                    }
                    .message-box { padding: 16px 18px; }
                    .btn-primary { 
                        display: block;
                        text-align: center;
                        padding: 14px 20px;
                    }
                    .footer { padding: 20px 20px 24px; }
                    .footer .logo-footer { max-width: 100px; }
                    .footer .legal { font-size: 10px; }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <!-- HEADER - Con logo blanco (fondo verde) -->
                <div class="header">
                    ' . ($logo_white_embedded ? '<img src="cid:' . $logo_white_cid . '" alt="Libertyfin" class="logo-img">' : '<div style="font-size: 28px; font-weight: 700; color: #FFFFFF;">Liberty<span style="font-weight: 300;">fin</span></div>') . '
                    <div class="tagline">Vende más, administra menos desde un solo lugar</div>
                </div>
                
                <!-- CONTENIDO -->
                <div class="content">
                    <h1>⏳ Estás a 5 días del vencimiento de tu suscripción</h1>
                    
                    <p class="highlight-text">
                        A partir de este momento aún cuentas con <strong>5 días</strong> para renovar tu suscripción.
                    </p>
                    
                    <!-- Mensaje principal con borde verde -->
                    <div class="message-box">
                        <p style="margin-bottom: 12px; font-size: 15px; font-weight: 600; color: #1A202C;">
                            📋 Detalles de tu suscripción
                        </p>
                        <div class="details-box">
                            <div class="detail-item">
                                <span class="detail-label">🏢 Empresa</span>
                                <span class="detail-value">' . htmlspecialchars($nombre_empresa) . '</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">📋 Plan actual</span>
                                <span class="detail-value"><span class="plan-badge">' . ucfirst(htmlspecialchars($plan)) . '</span></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">📅 Fecha de vencimiento</span>
                                <span class="detail-value"><span class="warning-date">' . $fecha_formateada . '</span></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">⏳ Días restantes</span>
                                <span class="detail-value"><span class="warning-date">5 días</span></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Mensaje tomado de Libertyfin -->
                    <div class="message-text">
                        <strong>🚀 Libertyfin unifica inventario, facturación CFDI, clientes y reportes</strong> en una plataforma potente y fácil de usar para cualquier negocio.
                    </div>
                    
                    <div class="message-text" style="margin-top: 12px;">
                        Recuerda que estamos para apoyarte, no dudes en contactarnos en el correo 
                        <a href="mailto:ventas@libertyfin.com.mx">ventas@libertyfin.com.mx</a>.
                    </div>
                    
                    <p style="font-size: 15px; color: #4A5568; margin: 20px 0 8px;">
                        ¡Ten un excelente día!
                    </p>
                </div>
                
                <!-- FOOTER - Con logo normal, aviso de privacidad y copyright -->
                <div class="footer">
                    ' . ($logo_embedded ? '<img src="cid:' . $logo_cid . '" alt="Libertyfin" class="logo-footer">' : '<div style="font-weight: 700; color: #10B981; font-size: 16px;">Liberty<span style="font-weight: 300;">fin</span></div>') . '
                    <div class="address">
                        Vende más, administra menos desde un solo lugar<br>
                    </div>
                    <div class="divider"></div>
                    
                    <!-- ============================================
                    AVISO DE PRIVACIDAD Y COPYRIGHT
                    ============================================ -->
                    <div class="legal">
                        <p style="margin: 0 0 4px 0;">
                            Consulta nuestro aviso de privacidad en 
                            <a href="https://www.libertyfin.com.mx/pages/aviso-privacidad" target="_blank">www.libertyfin.com.mx/pages/aviso-privacidad</a>
                        </p>
                        <div class="copyright">
                            © ' . date('Y') . ' Libertyfin. Todos los derechos reservados.
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>';

        // Versión texto plano - Actualizada con aviso de privacidad
        $mail->AltBody = "⏳ Estás a 5 días del vencimiento de tu suscripción\n" .
            "========================================\n\n" .
            "A partir de este momento aún cuentas con 5 días para renovar tu suscripción.\n\n" .
            "--- DETALLES DE TU SUSCRIPCIÓN ---\n" .
            "Empresa: " . $nombre_empresa . "\n" .
            "Plan actual: " . ucfirst($plan) . "\n" .
            "Fecha de vencimiento: " . $fecha_formateada . "\n" .
            "Días restantes: 5 días\n\n" .
            "🚀 Libertyfin unifica inventario, facturación CFDI, clientes y reportes en una plataforma potente y fácil de usar para cualquier negocio.\n\n" .
            "Recuerda que estamos para apoyarte, no dudes en contactarnos en el correo ventas@libertyfin.com.mx.\n\n" .
            "¡Ten un excelente día!\n\n" .
            "Libertyfin - Vende más, administra menos desde un solo lugar\n" .
            "Recibiste este correo porque tienes una cuenta activa en Libertyfin o realizaste una compra.\n" .
            "--- AVISO DE PRIVACIDAD ---\n" .
            "Consulta nuestro aviso de privacidad en:\n" .
            "https://www.libertyfin.com.mx/pages/aviso-privacidad\n\n" .
            "© " . date('Y') . " Libertfin. Todos los derechos reservados.\n" ;

        if ($mail->send()) {
            error_log("✅ Correo enviado a: " . $destinatario . " - Empresa: " . $nombre_empresa);
            return true;
        } else {
            error_log("❌ Error al enviar correo a: " . $destinatario . " - " . $mail->ErrorInfo);
            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Excepción al enviar correo: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNCIÓN PARA ENVIAR REPORTE AL ADMIN
// ============================================

function enviarReporteAdmin($empresas, $correos_enviados, $cantidad_vencidas) {
    try {
        // Verificar si PHPMailer está disponible
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $phpmailer_path = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($phpmailer_path)) {
                require_once $phpmailer_path;
            } else {
                $phpmailer_path = __DIR__ . '/../../PHPMailer/src/PHPMailer.php';
                if (file_exists($phpmailer_path)) {
                    require_once $phpmailer_path;
                    require_once __DIR__ . '/../../PHPMailer/src/SMTP.php';
                    require_once __DIR__ . '/../../PHPMailer/src/Exception.php';
                } else {
                    error_log("❌ PHPMailer no encontrado para reporte admin");
                    return false;
                }
            }
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Configuración SMTP
        $mail->isSMTP();
        $mail->Host = env('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = env('SMTP_USERNAME');
        $mail->Password = env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = (int)env('SMTP_PORT', 465);

        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom(env('SMTP_USERNAME'), 'Libertyfin - Sistema');
        $mail->addAddress('admin@libertyfin.com.mx', 'Administrador Libertyfin');

        $mail->Subject = "📊 Reporte de Vencimientos - " . date('d/m/Y');

        // Construir tabla de empresas
        $tabla_empresas = '';
        if (!empty($empresas)) {
            $tabla_empresas = '
            <h3 style="color: #1A202C; margin-top: 24px; font-weight: 600;">📋 Empresas que vencen en 5 días</h3>
            <table style="width:100%; border-collapse: collapse; font-size: 14px; margin: 12px 0; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                <thead>
                    <tr style="background: #10B981; color: white;">
                        <th style="padding: 10px 14px; text-align: left;">Empresa</th>
                        <th style="padding: 10px 14px; text-align: left;">Vence</th>
                        <th style="padding: 10px 14px; text-align: left;">Email</th>
                        <th style="padding: 10px 14px; text-align: left;">Plan</th>
                    </tr>
                </thead>
                <tbody>';
            
            $alternate = false;
            foreach ($empresas as $empresa) {
                $bg_color = $alternate ? '#F0FDF4' : '#ffffff';
                $tabla_empresas .= '
                    <tr style="background: ' . $bg_color . '; border-bottom: 1px solid #EDF2F7;">
                        <td style="padding: 8px 14px;">' . htmlspecialchars($empresa['nombre_empresa']) . '</td>
                        <td style="padding: 8px 14px; color: #E53E3E; font-weight: 600;">' . date('d/m/Y', strtotime($empresa['fecha_vencimiento'])) . '</td>
                        <td style="padding: 8px 14px;">' . htmlspecialchars($empresa['email_admin']) . '</td>
                        <td style="padding: 8px 14px;"><span style="background: #10B981; color: white; padding: 2px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">' . ucfirst(htmlspecialchars($empresa['plan'])) . '</span></td>
                    </tr>';
                $alternate = !$alternate;
            }
            $tabla_empresas .= '
                </tbody>
            </table>';
        }

        $mail->isHTML(true);
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color: #1A202C; background: #F7FAFC; padding: 20px; }
                .container { max-width: 680px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.10); }
                .header { border-bottom: 3px solid #10B981; padding-bottom: 20px; margin-bottom: 24px; }
                .header h2 { margin: 0; color: #10B981; font-weight: 700; }
                .header p { color: #718096; margin: 4px 0 0; }
                .stats { display: flex; gap: 16px; margin: 20px 0; flex-wrap: wrap; }
                .stat-card { flex: 1; min-width: 100px; background: #F7FAFC; padding: 14px 18px; border-radius: 8px; text-align: center; border: 1px solid #EDF2F7; }
                .stat-number { font-size: 26px; font-weight: 700; color: #10B981; }
                .stat-label { font-size: 13px; color: #718096; }
                .footer { text-align: center; padding-top: 20px; margin-top: 24px; border-top: 1px solid #EDF2F7; font-size: 13px; color: #A0AEC0; }
                .footer .brand { font-weight: 700; color: #10B981; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>📊 Reporte de Vencimientos</h2>
                    <p>' . date('d/m/Y H:i:s') . '</p>
                </div>
                
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-number">' . count($empresas) . '</div>
                        <div class="stat-label">📧 Vencen en 5 días</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #10B981;">' . $correos_enviados . '</div>
                        <div class="stat-label">✅ Correos enviados</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #E53E3E;">' . $cantidad_vencidas . '</div>
                        <div class="stat-label">❌ Desactivadas</div>
                    </div>
                </div>
                
                ' . $tabla_empresas . '
                
                <div class="footer">
                    <p class="brand">Libertyfin</p>
                    <p style="margin: 4px 0 0;">Vende más, administra menos desde un solo lugar</p>
                    <p style="margin: 12px 0 0; font-size: 12px;">© ' . date('Y') . ' Libertyfin</p>
                </div>
            </div>
        </body>
        </html>';

        $mail->AltBody = "REPORTE DE VENCIMIENTOS\n" .
            "========================\n" .
            "Fecha: " . date('d/m/Y H:i:s') . "\n\n" .
            "Empresas que vencen en 5 días: " . count($empresas) . "\n" .
            "Correos enviados: " . $correos_enviados . "\n" .
            "Empresas desactivadas: " . $cantidad_vencidas . "\n";

        if ($mail->send()) {
            error_log("✅ Reporte enviado al administrador");
            return true;
        } else {
            error_log("❌ Error al enviar reporte al administrador: " . $mail->ErrorInfo);
            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Excepción al enviar reporte admin: " . $e->getMessage());
        return false;
    }
}

// ============================================
// PROCESAMIENTO PRINCIPAL
// ============================================

try {
    // Obtener conexión usando getDBConnection() de database.php (PDO)
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception("No se pudo establecer conexión a la base de datos");
    }
    
    // 1. BUSCAR EMPRESAS QUE VENCEN EN 5 DÍAS
    $sql = "SELECT id, nombre_empresa, fecha_vencimiento, email_admin, 
                   nombre_contacto, apellido_contacto, plan
            FROM empresas 
            WHERE activo = 1 
            AND fecha_vencimiento IS NOT NULL 
            AND fecha_vencimiento = DATE_ADD(CURDATE(), INTERVAL 5 DAY)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $correos_enviados = 0;
    $errores = [];
    
    // 2. ENVIAR CORREOS
    foreach ($empresas as $empresa) {
        // Nombre completo del contacto
        $nombre_completo = trim($empresa['nombre_contacto'] . ' ' . $empresa['apellido_contacto']);
        $nombre_completo = empty($nombre_completo) ? $empresa['nombre_empresa'] : $nombre_completo;
        
        // Enviar correo
        if (enviarCorreoVencimiento(
            $empresa['email_admin'],
            $nombre_completo,
            $empresa['nombre_empresa'],
            $empresa['fecha_vencimiento'],
            $empresa['plan']
        )) {
            $correos_enviados++;
        } else {
            $errores[] = $empresa['nombre_empresa'] . " (" . $empresa['email_admin'] . ")";
        }
    }
    
    // 3. DESACTIVAR EMPRESAS VENCIDAS
    $sql_vencidas = "UPDATE empresas 
                     SET activo = 0 
                     WHERE activo = 1 
                     AND fecha_vencimiento IS NOT NULL 
                     AND fecha_vencimiento < CURDATE()";
    
    $stmt_update = $pdo->prepare($sql_vencidas);
    $stmt_update->execute();
    $cantidad_vencidas = $stmt_update->rowCount();
    
    // 4. REGISTRAR EN LOG
    $log_dir = __DIR__ . '/../../logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    
    $log_file = $log_dir . '/vencimiento_' . date('Y-m-d') . '.log';
    $log_message = date('Y-m-d H:i:s') . "\n";
    $log_message .= "========================================\n";
    $log_message .= "Empresas que vencen en 5 días: " . count($empresas) . "\n";
    $log_message .= "Correos enviados: " . $correos_enviados . "\n";
    $log_message .= "Empresas desactivadas (vencidas): " . $cantidad_vencidas . "\n";
    
    if (!empty($empresas)) {
        $log_message .= "\nDetalle de empresas:\n";
        foreach ($empresas as $empresa) {
            $log_message .= "  - " . $empresa['nombre_empresa'] . " | Vence: " . $empresa['fecha_vencimiento'] . " | Email: " . $empresa['email_admin'] . " | Plan: " . $empresa['plan'] . "\n";
        }
    }
    
    if (!empty($errores)) {
        $log_message .= "\n❌ Errores al enviar correo:\n";
        foreach ($errores as $error) {
            $log_message .= "  - " . $error . "\n";
        }
    }
    
    $log_message .= "========================================\n\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
    
    // 5. Enviar reporte al admin del sistema (solo si hay actividad)
    if ($correos_enviados > 0 || $cantidad_vencidas > 0 || !empty($empresas)) {
        enviarReporteAdmin($empresas, $correos_enviados, $cantidad_vencidas);
    }
    
    // 6. Mostrar resultados en consola
    echo "✅ Proceso completado\n";
    echo "📧 Correos enviados: " . $correos_enviados . "\n";
    echo "📊 Empresas que vencen en 5 días: " . count($empresas) . "\n";
    echo "❌ Empresas desactivadas: " . $cantidad_vencidas . "\n";
    
} catch (PDOException $e) {
    // Registrar error PDO
    $error_log = __DIR__ . '/../../logs/error_vencimiento.log';
    $error_dir = dirname($error_log);
    if (!file_exists($error_dir)) {
        mkdir($error_dir, 0777, true);
    }
    
    $error_message = date('Y-m-d H:i:s') . " - ERROR PDO: " . $e->getMessage() . "\n";
    file_put_contents($error_log, $error_message, FILE_APPEND);
    echo "❌ Error PDO: " . $e->getMessage() . "\n";
    
} catch (Exception $e) {
    // Registrar error general
    $error_log = __DIR__ . '/../../logs/error_vencimiento.log';
    $error_dir = dirname($error_log);
    if (!file_exists($error_dir)) {
        mkdir($error_dir, 0777, true);
    }
    
    $error_message = date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    file_put_contents($error_log, $error_message, FILE_APPEND);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>