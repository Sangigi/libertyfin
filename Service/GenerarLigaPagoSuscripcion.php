<?php
// GenerarLigaPagoSuscripcion.php
session_start();
header('Content-Type: application/json');

// Cargar configuración centralizada
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

// ============ FUNCIÓN PARA LOG EN ARCHIVO ============
function escribirLog($mensaje, $tipo = 'INFO') {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $fecha = date('Y-m-d');
    $archivo = $logDir . "/pagos_$fecha.log";
    $timestamp = date('Y-m-d H:i:s');
    $linea = "[$timestamp] [$tipo] $mensaje" . PHP_EOL;
    file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
}

// Función para guardar log en BD
function guardarLogEnBD($pdo, $datos) {
    if (!$pdo) return false;

    try {
        $sql = "INSERT INTO pagos_generadas (
                    fecha, monto, descripcion, request_data, response_data,
                    status, url_generada, reference, id_generado, http_code,
                    error_message, ip_usuario, user_agent
                ) VALUES (
                    NOW(), :monto, :descripcion, :request_data, :response_data,
                    :status, :url_generada, :reference, :id_generado, :http_code,
                    :error_message, :ip_usuario, :user_agent
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':monto' => $datos['monto'] ?? null,
            ':descripcion' => $datos['descripcion'] ?? null,
            ':request_data' => $datos['request_data'] ?? null,
            ':response_data' => $datos['response_data'] ?? null,
            ':status' => $datos['status'] ?? null,
            ':url_generada' => $datos['url_generada'] ?? null,
            ':reference' => $datos['reference'] ?? null,
            ':id_generado' => $datos['id_generado'] ?? null,
            ':http_code' => $datos['http_code'] ?? null,
            ':error_message' => $datos['error_message'] ?? null,
            ':ip_usuario' => $datos['ip_usuario'] ?? null,
            ':user_agent' => $datos['user_agent'] ?? null
        ]);

        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        escribirLog("Error en guardarLogEnBD: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * La tabla `domiciliacion_ligas` YA contiene las columnas necesarias:
 *   tipo_servicio, descripcion
 * Se conserva por compatibilidad.
 */
function asegurarColumnasFacturacion($pdo) {
    return;
}

// Conectar a la base de datos
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    escribirLog("Error de conexión: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
    exit();
}

// Obtener datos del POST
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

// Log del body crudo para diagnóstico
escribirLog("Body JSON recibido: " . json_encode($input), 'DEBUG');

$monto       = $input['monto']        ?? $input['MontoTotal'] ?? 0;
$descripcion = $input['descripcion']  ?? $input['Description'] ?? 'Pago en caja';

/* =============================================================================
 * PLAN Y PERIODO
 * ========================================================================== */
$plan = trim((string) ($input['plan'] ?? $_GET['plan'] ?? ''));

// Si no vino el plan, intentamos inferirlo de la descripción
if ($plan === '' && preg_match('/suscripcion\s+([a-z0-9áéíóúñ_ ]+?)\s*-/iu', $descripcion, $m)) {
    $plan = strtolower(trim($m[1]));
}

// El front envía "plazo" ('mensual'|'anual'). Se guarda en la columna "periodo".
$plazo = trim((string) ($input['plazo'] ?? $_GET['periodo'] ?? ''));
if ($plazo === '') {
    $plazo = (stripos($descripcion, 'anual') !== false) ? 'anual' : 'mensual';
}

// Normalizar contra el enum('mensual','anual')
$plazo = strtolower($plazo);
if (!in_array($plazo, ['mensual', 'anual'], true)) {
    $plazo = 'mensual';
}

/* =============================================================================
 * TIPO DE SERVICIO
 * ========================================================================== */
$tipoServicio = trim((string) ($input['tipo_servicio'] ?? 'Suscripcion'));
if ($tipoServicio === '') {
    $tipoServicio = 'Suscripcion';
}
if (mb_strlen($tipoServicio) > 50) {
    $tipoServicio = mb_substr($tipoServicio, 0, 50);
}

/* =============================================================================
 * DESCRIPCIÓN — normalización para BD (VARCHAR 255)
 * ========================================================================== */
if (mb_strlen($descripcion) > 255) {
    $descripcion = mb_substr($descripcion, 0, 255);
}
if ($descripcion === '') {
    $descripcion = 'Pago en caja';
}

/* =============================================================================
 * DATOS DE FACTURACIÓN
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

$facturacion = [
    'razon_social'    => $razonSocial   !== '' ? $razonSocial   : null,
    'rfc'             => $rfc           !== '' ? $rfc           : null,
    'email_factura'   => $emailFactura  !== '' ? $emailFactura  : null,
    'regimen_fiscal'  => $regimenFiscal !== '' ? $regimenFiscal : null,
    'cp'              => $cpFiscal      !== '' ? $cpFiscal      : null,
    'metodo_pago_sat' => $metodoPagoSat !== '' ? $metodoPagoSat : null,
    'uso_cfdi'        => $usoCfdi       !== '' ? $usoCfdi       : null,
];

// Convertir monto a float
$monto = floatval($monto);

$ip_usuario = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

escribirLog("=== NUEVA PETICIÓN DE PAGO ===", 'INFO');
escribirLog("Monto recibido: " . $monto, 'INFO');
escribirLog("Descripción: " . $descripcion, 'INFO');
escribirLog("Plan: '" . $plan . "' | Periodo: " . $plazo . " | Tipo: " . $tipoServicio, 'INFO');
escribirLog("Requiere factura: " . $requiereFactura . " (" . $facturar . ")", 'INFO');

// Validar monto
if ($monto <= 0) {
    $response = ['success' => false, 'error' => 'Monto no válido: ' . $monto];
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($input),
        'response_data' => json_encode($response),
        'status' => 'error',
        'error_message' => 'Monto no válido: ' . $monto,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    echo json_encode($response);
    exit();
}

if ($monto < 50 || $monto > 15000) {
    $response = [
        'success' => false,
        'error' => 'El monto debe estar entre $50.00 y $15,000.00 MXN'
    ];
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($input),
        'response_data' => json_encode($response),
        'status' => 'error',
        'error_message' => 'Monto fuera de rango',
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    echo json_encode($response);
    exit();
}

/* =============================================================================
 * EMPRESA_ID
 * ========================================================================== */
$empresa_id = (int) ($input['empresa_id'] ?? $_SESSION['empresa_id'] ?? 0);

if ($empresa_id <= 0) {
    $response = ['success' => false, 'error' => 'ID de empresa no válido'];
    escribirLog("empresa_id inválido. Body: " . json_encode($input), 'ERROR');
    echo json_encode($response);
    exit();
}

escribirLog("ID de empresa: $empresa_id", 'INFO');

// Obtener configuración
$domiciliacionConfig = domiciliacionConfig();

$url            = $domiciliacionConfig['url_generar_liga_dom'] ?? 'https://pagadetodo.mx/Pagadetodo/Service/GenerarLigaDomiciliacionIndi';
$user           = $domiciliacionConfig['user_dom'] ?? '';
$password       = $domiciliacionConfig['password_dom'] ?? '';
$integration_id = $domiciliacionConfig['integration_id_dom'] ?? '124';
$business_id    = $domiciliacionConfig['business_id_dom'] ?? '000002';
$dias_vigencia  = $domiciliacionConfig['dias_vigencia_dom'] ?? 7;

// ============================================================
// GENERAR REFERENCIA: 9 dígitos de empresa + 6 dígitos de sufijo (TOTAL 15)
// ============================================================
$empresa_id_padded = str_pad((string) $empresa_id, 9, '0', STR_PAD_LEFT);

function generarSufijo6Digitos() {
    $micro = explode(' ', microtime());
    $frac = (int)($micro[0] * 1000000);
    $sufijo = str_pad((string) $frac, 6, '0', STR_PAD_LEFT);
    if (strlen($sufijo) < 6) {
        $sufijo = str_pad($sufijo . rand(0, 9), 6, '0', STR_PAD_LEFT);
    }
    return substr($sufijo, -6);
}

// Generar referencia y verificar unicidad en BD (hasta 5 intentos)
$reference_envio = '';
$intentos = 0;
$max_intentos = 5;
while ($intentos < $max_intentos) {
    $sufijo = generarSufijo6Digitos();
    $reference_envio = $empresa_id_padded . $sufijo; // 15 dígitos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM domiciliacion_ligas WHERE reference = ?");
    $stmt->execute([$reference_envio]);
    if ($stmt->fetchColumn() == 0) {
        break;
    }
    $intentos++;
    escribirLog("Referencia $reference_envio ya existe, reintentando ($intentos/$max_intentos)", 'WARNING');
}

if ($intentos >= $max_intentos) {
    $sufijo = str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $reference_envio = $empresa_id_padded . $sufijo;
    escribirLog("Se forzó referencia: $reference_envio después de $max_intentos intentos", 'WARNING');
}

// ID para la transacción (hasta 10 dígitos, usamos los primeros 9)
$id_formateado = str_pad(substr($reference_envio, 0, 9), 9, '0', STR_PAD_LEFT);

$monto_centavos   = intval($monto * 100);
$fecha_expiracion = date('Y-m-d', strtotime("+{$dias_vigencia} day"));

escribirLog("Referencia a enviar: $reference_envio (15 dígitos)", 'INFO');

// Construir datos para Pagadetodo
$data = [
    "User"           => $user,
    "Password"       => $password,
    "IntegrationID"  => $integration_id,
    "BusinessID"     => $business_id,
    "PaymentTypes"   => "41",
    "Id"             => $id_formateado,
    "Description"    => substr($descripcion, 0, 40),
    "Amount"         => (string)$monto_centavos,
    "Reference"      => $reference_envio,
    "ExpirationDate" => $fecha_expiracion
];

escribirLog("Datos enviados a pagalaescuela: " . json_encode($data), 'DEBUG');

// Realizar petición CURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, $domiciliacionConfig['timeout'] ?? 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

escribirLog("HTTP Code: $httpCode", 'INFO');
escribirLog("Respuesta: " . $response, 'DEBUG');

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    escribirLog("Error CURL: " . $error_msg, 'ERROR');
    $response_array = ['success' => false, 'error' => 'Error CURL: ' . $error_msg];
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'Error CURL: ' . $error_msg,
        'id_generado' => $id_formateado,
        'reference' => $reference_envio,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    echo json_encode($response_array);
    curl_close($ch);
    exit();
}
curl_close($ch);

$result = json_decode($response, true);

if ($result === null) {
    $response_array = [
        'success' => false,
        'error' => 'Respuesta no válida del servidor',
        'raw_response' => $response
    ];
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'Respuesta no válida del servidor',
        'id_generado' => $id_formateado,
        'reference' => $reference_envio,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    echo json_encode($response_array);
    exit();
}

$clean = [];
foreach ($result as $key => $value) {
    $clean[trim($key)] = $value;
}

// ============================================================
// CASO 1: ÉXITO
// ============================================================
if (isset($clean['url']) && !empty($clean['url'])) {
    escribirLog("ÉXITO: URL generada: " . $clean['url'], 'INFO');

    $reference_devuelta = $clean['reference'] ?? $reference_envio;
    $reference_emisor   = $clean['referenceEmisor'] ?? $reference_envio;

    escribirLog("Referencia devuelta por pagalaescuela: $reference_devuelta", 'INFO');
    escribirLog("ReferenceEmisor: $reference_emisor", 'INFO');

    // Log antes del INSERT para confirmar qué se va a guardar
    escribirLog(
        "INSERT domiciliacion_ligas → plan='$plan', periodo='$plazo', tipo_servicio='$tipoServicio', "
        . "descripcion='$descripcion', empresa_id=$empresa_id, facturar='$facturar'",
        'DEBUG'
    );

    try {
        asegurarColumnasFacturacion($pdo);

        $stmt = $pdo->prepare(
            "INSERT INTO domiciliacion_ligas
                (reference, reference_emisor, empresa_id, plan, periodo, tipo_servicio, descripcion,
                 monto, url_pago, status,
                 requiere_factura, facturar,
                 razon_social, rfc, email_factura, regimen_fiscal, cp, metodo_pago_sat, uso_cfdi,
                 created_at)
             VALUES
                (:reference, :reference_emisor, :empresa_id, :plan, :periodo, :tipo_servicio, :descripcion,
                 :monto, :url_pago, 'pendiente',
                 :requiere_factura, :facturar,
                 :razon_social, :rfc, :email_factura, :regimen_fiscal, :cp, :metodo_pago_sat, :uso_cfdi,
                 NOW())
             ON DUPLICATE KEY UPDATE
                url_pago         = VALUES(url_pago),
                reference_emisor = VALUES(reference_emisor),
                plan             = VALUES(plan),
                periodo          = VALUES(periodo),
                tipo_servicio    = VALUES(tipo_servicio),
                descripcion      = VALUES(descripcion),
                requiere_factura = VALUES(requiere_factura),
                facturar         = VALUES(facturar),
                razon_social     = VALUES(razon_social),
                rfc              = VALUES(rfc),
                email_factura    = VALUES(email_factura),
                regimen_fiscal   = VALUES(regimen_fiscal),
                cp               = VALUES(cp),
                metodo_pago_sat  = VALUES(metodo_pago_sat),
                uso_cfdi         = VALUES(uso_cfdi),
                updated_at       = NOW()"
        );
        $stmt->execute([
            ':reference'        => $reference_devuelta,
            ':reference_emisor' => $reference_emisor,
            ':empresa_id'       => $empresa_id,
            ':plan'             => $plan !== '' ? $plan : null,
            ':periodo'          => $plazo,
            ':tipo_servicio'    => $tipoServicio,
            ':descripcion'      => $descripcion,
            ':monto'            => $monto,
            ':url_pago'         => $clean['url'] ?? '',
            ':requiere_factura' => $requiereFactura,
            ':facturar'         => $facturar,
            ':razon_social'     => $facturacion['razon_social'],
            ':rfc'              => $facturacion['rfc'],
            ':email_factura'    => $facturacion['email_factura'],
            ':regimen_fiscal'   => $facturacion['regimen_fiscal'],
            ':cp'               => $facturacion['cp'],
            ':metodo_pago_sat'  => $facturacion['metodo_pago_sat'],
            ':uso_cfdi'         => $facturacion['uso_cfdi'],
        ]);

        // Log de filas afectadas para saber si el INSERT realmente tocó la BD
        escribirLog(
            "Liga guardada en BD. Filas afectadas: " . $stmt->rowCount()
            . " (plan=$plan, periodo=$plazo, tipo_servicio=$tipoServicio, descripcion='$descripcion', facturar=$facturar)",
            'INFO'
        );

    } catch (PDOException $e) {
        // Log enriquecido con el SQLSTATE y el mensaje real de MySQL
        escribirLog("Error guardando liga: SQLSTATE=" . $e->getCode()
            . " | " . $e->getMessage(), 'ERROR');
    }

    $response_array = [
        'success'          => true,
        'url'              => $clean['url'],
        'reference'        => $reference_devuelta,
        'reference_emisor' => $reference_emisor,
        'id'               => $id_formateado,
        'amount'           => $monto,
        'description'      => $descripcion,
        'empresa_id'       => $empresa_id,
        'plan'             => $plan,
        'periodo'          => $plazo,
        'tipo_servicio'    => $tipoServicio,
        'requiere_factura' => (bool) $requiereFactura,
    ];

    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'success',
        'url_generada' => $clean['url'],
        'reference' => $reference_devuelta,
        'id_generado' => $id_formateado,
        'http_code' => $httpCode,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);

    echo json_encode($response_array);
    exit();
}

// ============================================================
// CASO 2: ERROR CON MENSAJE
// ============================================================
if (isset($clean['Message']) && !empty($clean['Message'])) {
    $mensaje_error = $clean['Message'];
    $codigo_error = $clean['Error'] ?? 'Desconocido';
    escribirLog("Error de pagalaescuela: " . $mensaje_error . " (Código: " . $codigo_error . ")", 'ERROR');

    $response_array = [
        'success' => false,
        'error' => $mensaje_error,
        'code' => $codigo_error,
        'response' => $clean
    ];

    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => $mensaje_error,
        'id_generado' => $id_formateado,
        'reference' => $reference_envio,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);

    echo json_encode($response_array);
    exit();
}

// ============================================================
// CASO 3: ERROR CON CÓDIGO NUMÉRICO
// ============================================================
if (isset($clean['Error']) && !empty($clean['Error'])) {
    $mensaje_error = $clean['Message'] ?? 'Error código ' . $clean['Error'];
    escribirLog("Error de pagalaescuela: " . $mensaje_error, 'ERROR');

    $response_array = [
        'success' => false,
        'error' => $mensaje_error,
        'code' => $clean['Error'] ?? null,
        'response' => $clean
    ];

    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => $mensaje_error,
        'id_generado' => $id_formateado,
        'reference' => $reference_envio,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);

    echo json_encode($response_array);
    exit();
}

// ============================================================
// CASO 4: NO CONTEMPLADO
// ============================================================
escribirLog("Caso no contemplado: " . json_encode($clean), 'ERROR');

$response_array = [
    'success' => false,
    'error' => 'Respuesta no reconocida',
    'response' => $clean,
    'http_code' => $httpCode
];

guardarLogEnBD($pdo, [
    'monto' => $monto,
    'descripcion' => $descripcion,
    'request_data' => json_encode($data),
    'response_data' => json_encode($clean),
    'status' => 'error',
    'http_code' => $httpCode,
    'error_message' => 'Respuesta no reconocida',
    'id_generado' => $id_formateado,
    'reference' => $reference_envio,
    'ip_usuario' => $ip_usuario,
    'user_agent' => $user_agent
]);

echo json_encode($response_array);