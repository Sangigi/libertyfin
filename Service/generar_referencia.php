<?php
/**
 * Service/generar_referencia.php
 * -----------------------------------------------------------------------------
 * Genera una referencia de pago en efectivo (formato PDF + código de barras)
 * llamando al API "Generador de Referencias" de Cobroscontarjeta.com / Paga de Todo.
 *
 * Documento base: IntegracionesReferencias V1.4 (28-abr-2022)
 *
 * Fix aplicado:
 *   - Error 22: la Reference del EMISOR ahora es de EXACTAMENTE 15 dígitos
 *     (antes era de 13, por eso CCT respondía "formato incorrecto").
 *   - Se guardan los datos de facturación (RFC, razón social, régimen, etc.)
 *     que envía el front al generar la referencia.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido. Use POST.']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

/* =============================================================================
 * 1) CONFIGURACIÓN
 * ========================================================================== */
$cfg = referenciaConfig();

if (empty($cfg['user_ref']) || empty($cfg['password_ref'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Faltan credenciales REFERENCIA_USER / REFERENCIA_PASSWORD en el .env.',
    ]);
    exit;
}

$endpoint = $cfg['url_general_ref'] ?? '';
if ($endpoint === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'No está configurado REFERENCIA_GENERAR en el .env.',
    ]);
    exit;
}

/* =============================================================================
 * 2) UTILIDADES
 * ========================================================================== */

function json_out(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function leer_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Reference del EMISOR: EXACTAMENTE 15 dígitos numéricos (obligatorio para CCT).
 * Formato: YYMMDDHHMMSS (12) + 3 dígitos aleatorios = 15 caracteres.
 * Ejemplo: 260915061145007
 */
function generar_reference_emisor(): string
{
    $base = date('ymdHis');                                             // 12 dígitos
    $rand = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT); // 3 dígitos
    return $base . $rand;                                               // 15 dígitos exactos
}

/** Monto decimal → entero ×100 (ej. 150.00 → 15000). */
function monto_a_centavos(float $monto): int
{
    return (int) round($monto * 100);
}

/** Traduce códigos de error del Generador de Referencias (pág. 9). */
function mensaje_error_cct(string $codigo): string
{
    $mapa = [
        '00'  => 'Los datos enviados son nulos.',
        '1'   => 'El usuario y/o contraseña son inválidos.',
        '2'   => 'El usuario y la contraseña son obligatorios.',
        '3'   => 'El ID de la integración no existe.',
        '4'   => 'El formato del ID de la integración es incorrecto.',
        '5'   => 'El ID de la integración es obligatorio.',
        '6'   => 'El ID del comercio no existe.',
        '7'   => 'El formato del ID del comercio es incorrecto.',
        '8'   => 'El ID del comercio es obligatorio.',
        '15'  => 'Este comercio no está vinculado a la integración.',
        '17'  => 'La descripción es obligatoria.',
        '18'  => 'El importe debe estar entre $50.00 y $15,000.00 MXN.',
        '19'  => 'El formato del importe es incorrecto.',
        '20'  => 'El importe es obligatorio.',
        '21'  => 'La referencia es obligatoria.',
        '22'  => 'El formato de la referencia es incorrecto.',
        '23'  => 'La referencia es única e irrepetible.',
        '24'  => 'La fecha de vencimiento debe ser mayor o igual a hoy.',
        '25'  => 'El formato de la fecha de vencimiento es incorrecto.',
        '200' => 'Datos correctos.',
        '401' => 'Su cuenta no tiene acceso, contacte a los administradores.',
        '404' => 'No tiene permiso para generar formas de pago.',
    ];
    return $mapa[$codigo] ?? "Error desconocido (código {$codigo}).";
}

/* =============================================================================
 * 3) VALIDACIÓN DEL BODY
 * ========================================================================== */
$input = leer_json_body();

$monto         = (float)  ($input['monto']         ?? $input['MontoTotal'] ?? 0);
$descripcion   = trim((string) ($input['descripcion'] ?? $input['Description'] ?? ''));
$customerEmail = trim((string) ($input['CustomerEmail'] ?? $input['cliente_email'] ?? ''));
$customerName  = trim((string) ($input['CustomerName']  ?? $input['cliente_nombre'] ?? ''));
$plan          = trim((string) ($input['plan']          ?? ''));
$plazo         = trim((string) ($input['plazo']         ?? ''));
$tipoServicio  = trim((string) ($input['tipo_servicio'] ?? 'Suscripcion'));

$minMonto = (float) ($cfg['monto_min_ref'] ?? 50);
$maxMonto = (float) ($cfg['monto_max_ref'] ?? 15000);

if ($monto < $minMonto || $monto > $maxMonto) {
    json_out([
        'success' => false,
        'error'   => sprintf('El monto debe estar entre $%.2f y $%.2f MXN.', $minMonto, $maxMonto),
    ], 422);
}

if ($descripcion === '') $descripcion = 'Suscripción Libertyfin';
if (mb_strlen($descripcion) > 50) $descripcion = mb_substr($descripcion, 0, 50);

if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    $customerEmail = '';
}
if (mb_strlen($customerEmail) > 50) $customerEmail = mb_substr($customerEmail, 0, 50);
if (mb_strlen($customerName)  > 50) $customerName  = mb_substr($customerName, 0, 50);

$referenceEmisor = generar_reference_emisor();

$diasVigencia    = (int) ($cfg['dias_vigencia_ref'] ?? 3);
$fechaExpiracion = (string) ($input['ExpirationDate'] ?? date('Y-m-d', strtotime("+{$diasVigencia} days")));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaExpiracion)) {
    $fechaExpiracion = date('Y-m-d', strtotime("+{$diasVigencia} days"));
}

/* =============================================================================
 * 3.1) DATOS DE FACTURACIÓN (vienen en el mismo payload del front)
 * ========================================================================== */
$requiereFactura = !empty($input['requiere_factura']) ? 1 : 0;
$facturar        = $requiereFactura ? 'si' : 'no';

$razonSocial   = trim((string) ($input['razon_social']    ?? ''));
$rfc           = strtoupper(trim((string) ($input['rfc']  ?? '')));
$emailFactura  = trim((string) ($input['email_factura']   ?? ''));
$regimenFiscal = trim((string) ($input['regimen_fiscal']  ?? ''));
$cpFiscal      = trim((string) ($input['cp']              ?? ''));
$metodoPagoSat = trim((string) ($input['metodo_pago_sat'] ?? ''));
$usoCfdi       = trim((string) ($input['uso_cfdi']        ?? ''));

// Si NO requiere factura, limpiamos todo
if (!$requiereFactura) {
    $razonSocial = $rfc = $emailFactura = $regimenFiscal = '';
    $cpFiscal = $metodoPagoSat = $usoCfdi = '';
}

// Ajuste a longitudes del esquema
if (mb_strlen($razonSocial)   > 150) $razonSocial   = mb_substr($razonSocial, 0, 150);
if (mb_strlen($rfc)           > 20)  $rfc           = mb_substr($rfc, 0, 20);
if (mb_strlen($emailFactura)  > 150) $emailFactura  = mb_substr($emailFactura, 0, 150);
if (mb_strlen($regimenFiscal) > 10)  $regimenFiscal = mb_substr($regimenFiscal, 0, 10);
if (mb_strlen($cpFiscal)      > 10)  $cpFiscal      = mb_substr($cpFiscal, 0, 10);
if (mb_strlen($metodoPagoSat) > 10)  $metodoPagoSat = mb_substr($metodoPagoSat, 0, 10);
if (mb_strlen($usoCfdi)       > 5)   $usoCfdi       = mb_substr($usoCfdi, 0, 5);

if ($emailFactura !== '' && !filter_var($emailFactura, FILTER_VALIDATE_EMAIL)) {
    $emailFactura = '';
}

/* =============================================================================
 * 4) PAYLOAD HACIA CCT
 * ========================================================================== */
$payloadCCT = [
    'User'           => (string) $cfg['user_ref'],
    'Password'       => (string) $cfg['password_ref'],
    'IntegrationID'  => (string) $cfg['integration_id_ref'],
    'BusinessID'     => (string) $cfg['business_id_ref'],
    'Description'    => $descripcion,
    'Amount'         => (string) monto_a_centavos($monto),
    'Reference'      => $referenceEmisor,
    'CustomerEmail'  => $customerEmail,
    'CustomerName'   => $customerName,
    'ExpirationDate' => $fechaExpiracion,
];

// Log temporal para depurar (bórralo cuando ya funcione)
error_log('[CCT GenerarReferencia] Payload enviado: ' . json_encode($payloadCCT, JSON_UNESCAPED_UNICODE));

/* =============================================================================
 * 5) LLAMADA HTTPS
 * ========================================================================== */
$timeout = (int) ($cfg['timeout_ref'] ?? 20);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payloadCCT, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => false,
]);

$rawResponse = curl_exec($ch);
$httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
curl_close($ch);

if ($rawResponse === false) {
    error_log('[CCT GenerarReferencia] cURL error: ' . $curlError);
    json_out([
        'success' => false,
        'error'   => 'No se pudo conectar con Cobroscontarjeta.com. Intenta más tarde.',
    ], 502);
}

if ($httpCode < 200 || $httpCode >= 300) {
    error_log("[CCT GenerarReferencia] HTTP {$httpCode} - Body: {$rawResponse}");
    json_out([
        'success' => false,
        'error'   => "El servicio de referencias respondió HTTP {$httpCode}.",
    ], 502);
}

/* =============================================================================
 * 6) PARSEO RESPUESTA
 * ========================================================================== */
$data = json_decode($rawResponse, true);
if (!is_array($data)) {
    error_log('[CCT GenerarReferencia] Respuesta no-JSON: ' . $rawResponse);
    json_out([
        'success' => false,
        'error'   => 'Respuesta inválida del servicio de referencias.',
    ], 502);
}

$message = (string) ($data['Message'] ?? '');
$error   = $data['Error'] ?? null;

$esExitosa = ($error === null || $error === '' || $error === false)
          && stripos($message, 'exitosa') !== false;

if (!$esExitosa) {
    $codigoError = is_scalar($error) ? (string) $error : '';
    $mensaje     = $codigoError !== ''
        ? mensaje_error_cct($codigoError)
        : ($message !== '' ? $message : 'Error al generar la referencia.');

    error_log("[CCT GenerarReferencia] Error: {$mensaje} - Body: {$rawResponse}");

    json_out([
        'success'    => false,
        'error'      => $mensaje,
        'codigo_cct' => $codigoError,
        'reference'  => $data['Reference'] ?? null,
        'folio'      => $data['Folio']     ?? null,
    ], 422);
}

/* =============================================================================
 * 7) PERSISTENCIA
 * ========================================================================== */
try {
    $conn = getDBConnection();
    if ($conn) {
        $stmt = $conn->prepare(
            'INSERT INTO referencias_pago
                (empresa_id, plan, plazo, tipo_servicio,
                 reference_cct, reference_emisor, folio_cct,
                 monto, descripcion, barcode_url, payformat_url,
                 customer_email, customer_name,
                 fecha_expiracion, fecha_creacion, estado, metodo_pago, json_respuesta,
                 requiere_factura, facturar,
                 razon_social, rfc, email_factura, regimen_fiscal, cp_fiscal,
                 metodo_pago_sat, uso_cfdi)
             VALUES
                (:empresa_id, :plan, :plazo, :tipo_servicio,
                 :reference_cct, :reference_emisor, :folio_cct,
                 :monto, :descripcion, :barcode_url, :payformat_url,
                 :customer_email, :customer_name,
                 :fecha_expiracion, NOW(), "pendiente", "efectivo", :json_respuesta,
                 :requiere_factura, :facturar,
                 :razon_social, :rfc, :email_factura, :regimen_fiscal, :cp_fiscal,
                 :metodo_pago_sat, :uso_cfdi)'
        );

        $stmt->execute([
            ':empresa_id'       => $input['empresa_id'] ?? null,
            ':plan'             => $plan,
            ':plazo'            => $plazo,
            ':tipo_servicio'    => $tipoServicio,
            ':reference_cct'    => $data['Reference'],
            ':reference_emisor' => $data['ReferenceEmisor'] ?? $referenceEmisor,
            ':folio_cct'        => $data['Folio'] ?? null,
            ':monto'            => $monto,
            ':descripcion'      => $descripcion,
            ':barcode_url'      => $data['BarCode']   ?? null,
            ':payformat_url'    => $data['PayFormat'] ?? null,
            ':customer_email'   => $customerEmail ?: null,
            ':customer_name'    => $customerName ?: null,
            ':fecha_expiracion' => $fechaExpiracion,
            ':json_respuesta'   => json_encode($data, JSON_UNESCAPED_UNICODE),

            // --- Facturación ---
            ':requiere_factura' => $requiereFactura,
            ':facturar'         => $facturar,
            ':razon_social'     => $razonSocial   !== '' ? $razonSocial   : null,
            ':rfc'              => $rfc           !== '' ? $rfc           : null,
            ':email_factura'    => $emailFactura  !== '' ? $emailFactura  : null,
            ':regimen_fiscal'   => $regimenFiscal !== '' ? $regimenFiscal : null,
            ':cp_fiscal'        => $cpFiscal      !== '' ? $cpFiscal      : null,
            ':metodo_pago_sat'  => $metodoPagoSat !== '' ? $metodoPagoSat : null,
            ':uso_cfdi'         => $usoCfdi       !== '' ? $usoCfdi       : null,
        ]);
    }
} catch (Throwable $e) {
    error_log('[CCT GenerarReferencia] Error guardando en BD: ' . $e->getMessage());
}

/* =============================================================================
 * 8) RESPUESTA FINAL
 * ========================================================================== */
json_out([
    'success'          => true,
    'reference'        => $data['Reference']       ?? null,
    'reference_emisor' => $data['ReferenceEmisor'] ?? $referenceEmisor,
    'barcode'          => $data['BarCode']         ?? null,
    'payformat'        => $data['PayFormat']       ?? null,
    'folio'            => $data['Folio']           ?? null,
    'fecha'            => $data['Date']            ?? date('c'),
    'monto'            => $monto,
    'descripcion'      => $descripcion,
    'fecha_expiracion' => $fechaExpiracion,
    'message'          => $message,

    // Datos de facturación devueltos (opcional, para debug del front)
    'requiere_factura' => (bool) $requiereFactura,
    'factura_rfc'      => $rfc,
    'factura_email'    => $emailFactura,
]);