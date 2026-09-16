<?php
/**
 * helpers_pagos_suscripcion.php
 *
 * Lógica compartida para cuando un pago de SUSCRIPCIÓN (tarjeta vía liga
 * Pagadetodo o SPEI/CLABE) se confirma como aprobado, sin importar el canal:
 *   1. Asegura que existan las columnas necesarias (fiscales / plan / periodo)
 *      en las tablas involucradas, con el mismo patrón de auto-migración que
 *      ya se usaba en generar_clabe.php (ALTER TABLE si la columna no existe).
 *   2. Activa/renueva el plan de la empresa (misma lógica que ya existía en
 *      EntregarPagoLineaToken.php, reutilizada aquí para que TODOS los
 *      canales de pago la apliquen igual).
 *   3. Envía el correo de confirmación de pago al cliente.
 *   4. Si el cliente pidió factura, genera el CFDI de la suscripción.
 *
 * Se centraliza aquí para no duplicar esta lógica entre pagar_liga.php
 * (tarjeta) y pago_clabe.php (SPEI).
 */

require_once __DIR__ . '/../config.php';

// ============================================================
// 1. AUTO-MIGRACIÓN DE COLUMNAS
// ============================================================

/**
 * Agrega una columna a una tabla si no existe todavía.
 * Mismo patrón defensivo que ya usaba generar_clabe.php para empresa_id.
 */
function pagosuscripcion_asegurar_columna(PDO $pdo, $tabla, $columna, $definicionSql) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE " . $pdo->quote($columna));
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$columna` $definicionSql");
        error_log("pagosuscripcion: columna `$columna` agregada a `$tabla`");
        return true;
    } catch (PDOException $e) {
        error_log("pagosuscripcion: no se pudo asegurar columna `$columna` en `$tabla`: " . $e->getMessage());
        return false;
    }
}

/**
 * Asegura las columnas de datos fiscales + plan/periodo en la tabla que
 * se le indique (domiciliacion_ligas para tarjeta, clabes_spei para SPEI).
 */
function pagosuscripcion_asegurar_columnas_fiscales(PDO $pdo, $tabla) {
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'facturar', "VARCHAR(5) DEFAULT 'no'");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'razon_social', "VARCHAR(150) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'rfc', "VARCHAR(20) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'email_factura', "VARCHAR(150) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'regimen_fiscal', "VARCHAR(10) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'cp_fiscal', "VARCHAR(10) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'metodo_pago_cfdi', "VARCHAR(5) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'uso_cfdi', "VARCHAR(5) DEFAULT NULL");
    // Solo aplica (y solo hace falta) en clabes_spei; en domiciliacion_ligas
    // ya existen desde antes, así que el ALTER simplemente no hace nada.
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'plan', "VARCHAR(20) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'periodo', "VARCHAR(10) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'factura_uuid', "VARCHAR(64) DEFAULT NULL");
    pagosuscripcion_asegurar_columna($pdo, $tabla, 'factura_folio', "VARCHAR(20) DEFAULT NULL");
}

// ============================================================
// 2. ACTIVACIÓN / RENOVACIÓN DEL PLAN DE LA EMPRESA
//    (misma lógica que EntregarPagoLineaToken.php, centralizada)
// ============================================================
function pagosuscripcion_activar_plan(PDO $pdo, $empresa_id, $plan, $periodo) {
    $plan_a_usar = $plan ?: 'empresarial';

    $esAnual = $periodo && stripos($periodo, 'anual') !== false;
    $intervalo = $esAnual ? 'INTERVAL 1 YEAR' : 'INTERVAL 1 MONTH';

    $stmtFecha = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = :empresa_id");
    $stmtFecha->execute([':empresa_id' => $empresa_id]);
    $empresaActual = $stmtFecha->fetch(PDO::FETCH_ASSOC);

    $fechaBase = 'NOW()';
    if ($empresaActual && !empty($empresaActual['fecha_vencimiento'])) {
        $fechaVencimiento = new DateTime($empresaActual['fecha_vencimiento']);
        if ($fechaVencimiento > new DateTime()) {
            // Aún vigente: la renovación se suma al vencimiento actual.
            $fechaBase = $fechaVencimiento->format('Y-m-d H:i:s');
        }
    }

    if ($fechaBase === 'NOW()') {
        $sql = "UPDATE empresas SET
                    plan = :plan,
                    fecha_actualizacion = NOW(),
                    fecha_vencimiento = DATE_ADD(NOW(), $intervalo),
                    activo = 1
                WHERE id = :empresa_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':plan' => $plan_a_usar, ':empresa_id' => $empresa_id]);
    } else {
        $sql = "UPDATE empresas SET
                    plan = :plan,
                    fecha_actualizacion = NOW(),
                    fecha_vencimiento = DATE_ADD(:fecha_base, $intervalo),
                    activo = 1
                WHERE id = :empresa_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':plan' => $plan_a_usar, ':empresa_id' => $empresa_id, ':fecha_base' => $fechaBase]);
    }

    error_log("pagosuscripcion: empresa $empresa_id activada con plan=$plan_a_usar periodo=$periodo (base=$fechaBase)");
    return true;
}

// ============================================================
// 3. CORREO DE CONFIRMACIÓN DE PAGO
// ============================================================
function pagosuscripcion_cargar_phpmailer() {
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }
    $base = __DIR__ . '/../PHPMailer/src/';
    if (file_exists($base . 'PHPMailer.php')) {
        require_once $base . 'PHPMailer.php';
        require_once $base . 'SMTP.php';
        require_once $base . 'Exception.php';
        return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }
    error_log('pagosuscripcion: PHPMailer no encontrado, no se puede enviar correo');
    return false;
}

/**
 * Envía el correo de "pago confirmado" al cliente.
 * Devuelve true/false; nunca lanza excepción (un correo fallido no debe
 * tumbar la confirmación del pago en sí).
 */
function pagosuscripcion_enviar_correo_confirmacion($destinatario, $nombreContacto, $nombreEmpresa, $plan, $periodo, $monto, $referencia, $metodo) {
    if (empty($destinatario)) {
        error_log('pagosuscripcion: no hay email de destino, se omite correo de confirmación');
        return false;
    }

    if (!pagosuscripcion_cargar_phpmailer()) {
        return false;
    }

    try {
        $smtp = Config::getInstance()->getSmtpConfig();

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $smtp['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('notificaciones@libertyfin.com.mx', 'LibertyFin');
        $mail->addAddress($destinatario, $nombreContacto ?: $destinatario);

        $mail->isHTML(true);
        $mail->Subject = 'Confirmación de pago - Suscripción LibertyFin';

        $montoFmt = '$' . number_format((float)$monto, 2) . ' MXN';
        $periodoTexto = (stripos((string)$periodo, 'anual') !== false) ? 'Anual' : 'Mensual';

        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width:600px; margin:0 auto;">
            <div style="background:#27ae60; color:#fff; padding:20px; text-align:center;">
                <h2 style="margin:0;">¡Pago confirmado!</h2>
            </div>
            <div style="padding:20px; background:#f9f9f9;">
                <p>Hola ' . htmlspecialchars($nombreContacto ?: '') . ',</p>
                <p>Confirmamos que recibimos correctamente el pago de tu suscripción para <strong>' . htmlspecialchars($nombreEmpresa ?: '') . '</strong>.</p>
                <table style="width:100%; border-collapse:collapse; margin:15px 0;">
                    <tr><td style="padding:6px 0;">Plan</td><td style="padding:6px 0; text-align:right;"><strong>' . htmlspecialchars(ucfirst((string)$plan)) . '</strong></td></tr>
                    <tr><td style="padding:6px 0;">Periodo</td><td style="padding:6px 0; text-align:right;">' . htmlspecialchars($periodoTexto) . '</td></tr>
                    <tr><td style="padding:6px 0;">Monto</td><td style="padding:6px 0; text-align:right;">' . htmlspecialchars($montoFmt) . '</td></tr>
                    <tr><td style="padding:6px 0;">Método de pago</td><td style="padding:6px 0; text-align:right;">' . htmlspecialchars($metodo) . '</td></tr>
                    <tr><td style="padding:6px 0;">Referencia</td><td style="padding:6px 0; text-align:right;">' . htmlspecialchars($referencia) . '</td></tr>
                </table>
                <p>Tu suscripción ya quedó activa.</p>
            </div>
            <div style="text-align:center; padding:15px; color:#888; font-size:12px;">LibertyFin</div>
        </div>';

        $mail->AltBody = "Pago confirmado. Plan: $plan ($periodoTexto). Monto: $montoFmt. Referencia: $referencia.";

        $mail->send();
        error_log("pagosuscripcion: correo de confirmación enviado a $destinatario");
        return true;
    } catch (Exception $e) {
        error_log('pagosuscripcion: error enviando correo de confirmación: ' . $e->getMessage());
        return false;
    }
}

// ============================================================
// 4. FACTURACIÓN DE LA SUSCRIPCIÓN (CFDI) VÍA FACTURAPI
// ============================================================
/**
 * $fiscal = ['razon_social','rfc','email_factura','regimen_fiscal','cp','metodo_pago','uso_cfdi']
 *
 * IMPORTANTE: esto factura la suscripción que la EMPRESA le paga a
 * LibertyFin/Grupo Ideas — es un emisor distinto al que usa cada empresa
 * para facturar a SUS propios clientes (ese usa facturapi_organization_id
 * de la tabla empresas, ver Service/facturar_venta.php).
 *
 * Para timbrar aquí se necesita la organización de Facturapi que representa
 * a Grupo Ideas como EMISOR. Se toma de la variable de entorno
 * FACTURAPI_ORGANIZATION_ID_SUSCRIPCIONES. Si no está configurada, se
 * registra la solicitud como pendiente en vez de fallar en silencio, para
 * poder timbrarla manualmente después.
 */
function pagosuscripcion_facturar(PDO $pdo, array $fiscal, $monto, $descripcion, $referencia, $tabla, $columnaId = 'reference') {
    $resultado = ['intentado' => false, 'success' => false, 'message' => ''];

    if (($fiscal['facturar'] ?? 'no') !== 'si') {
        return $resultado; // el cliente no pidió factura
    }

    $resultado['intentado'] = true;

    $rfc = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $fiscal['rfc'] ?? ''));
    if (empty($fiscal['razon_social']) || strlen($rfc) < 12 || empty($fiscal['email_factura']) || empty($fiscal['regimen_fiscal']) || empty($fiscal['cp'])) {
        $resultado['message'] = 'Datos fiscales incompletos, no se generó la factura automáticamente.';
        error_log("pagosuscripcion_facturar: datos fiscales incompletos para referencia $referencia");
        return $resultado;
    }

    $orgIdSuscripciones = env("FACTURAPI_LIBERTYFIN_ORGANIZATION_ID") ?: "";
    $apiKey = env('FACTURAPI_API_KEY');

    if (empty($orgIdSuscripciones) || empty($apiKey)) {
        $resultado['message'] = 'Falta configurar FACTURAPI_ORGANIZATION_ID_SUSCRIPCIONES en el .env; la factura de esta suscripción quedó pendiente de timbrar manualmente.';
        error_log("pagosuscripcion_facturar: falta configuración de Facturapi para suscripciones (referencia $referencia)");
        return $resultado;
    }

    if (!class_exists('Facturapi\\Facturapi')) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    if (!class_exists('Facturapi\\Facturapi')) {
        $resultado['message'] = 'Librería de Facturapi no disponible.';
        error_log('pagosuscripcion_facturar: librería Facturapi no encontrada');
        return $resultado;
    }

    try {
        $facturapiMaster = new \Facturapi\Facturapi($apiKey);
        $orgApiKeyObj = $facturapiMaster->Organizations->getTestApiKey($orgIdSuscripciones);
        $orgApiKey = is_object($orgApiKeyObj)
            ? ($orgApiKeyObj->key ?? $orgApiKeyObj->api_key ?? $orgApiKeyObj->secret ?? null)
            : $orgApiKeyObj;

        if (empty($orgApiKey)) {
            throw new Exception('No se pudo obtener la API key de la organización de suscripciones');
        }

        $facturapi = new \Facturapi\Facturapi($orgApiKey);

        $invoiceData = [
            'customer' => [
                'legal_name' => $fiscal['razon_social'],
                'email' => $fiscal['email_factura'],
                'tax_id' => $rfc,
                'tax_system' => $fiscal['regimen_fiscal'],
                'address' => ['zip' => $fiscal['cp']],
            ],
            'items' => [[
                'quantity' => 1,
                'product' => [
                    'description' => $descripcion,
                    'product_key' => '81112101', // Software / servicios de suscripción SAT
                    'price' => (float) $monto,
                ],
            ]],
            'payment_form' => (($fiscal['metodo_pago'] ?? 'PUE') === 'PPD') ? '31' : '28',
            'use' => $fiscal['uso_cfdi'] ?? 'G03',
        ];

        $invoice = $facturapi->Invoices->create($invoiceData);
        $uuid = $invoice->uuid ?? $invoice->id ?? null;
        $folio = $invoice->folio_number ?? $invoice->folio ?? null;

        if (!$uuid) {
            throw new Exception('Facturapi no devolvió UUID.');
        }

        $stmt = $pdo->prepare("UPDATE `$tabla` SET factura_uuid = ?, factura_folio = ? WHERE `$columnaId` = ?");
        $stmt->execute([$uuid, $folio, $referencia]);

        try {
            $facturapi->Invoices->send_by_email($invoice->id, $fiscal['email_factura']);
        } catch (Exception $e) {
            error_log('pagosuscripcion_facturar: factura timbrada pero falló el envío por correo: ' . $e->getMessage());
        }

        $resultado['success'] = true;
        $resultado['message'] = "Factura timbrada correctamente (folio $folio).";
        error_log("pagosuscripcion_facturar: factura OK para referencia $referencia, uuid=$uuid");
    } catch (Exception $e) {
        $resultado['message'] = 'Error al timbrar: ' . $e->getMessage();
        error_log('pagosuscripcion_facturar: error - ' . $e->getMessage());
    }

    return $resultado;
}