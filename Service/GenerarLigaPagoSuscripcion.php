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
$monto = $input['monto'] ?? 0;
$descripcion = $input['descripcion'] ?? 'Pago en caja';

// Convertir a float
$monto = floatval($monto);

// Obtener datos del cliente
$ip_usuario = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

escribirLog("=== NUEVA PETICIÓN DE PAGO ===", 'INFO');
escribirLog("Monto recibido: " . $monto, 'INFO');
escribirLog("Descripción: " . $descripcion, 'INFO');

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

// OBTENER EL ID DE LA EMPRESA DE LA SESIÓN
$empresa_id = $_SESSION['empresa_id'] ?? 0;

if ($empresa_id <= 0) {
    $response = ['success' => false, 'error' => 'ID de empresa no válido'];
    echo json_encode($response);
    exit();
}

escribirLog("ID de empresa: $empresa_id", 'INFO');

// Obtener configuración
$domiciliacionConfig = domiciliacionConfig();

$url = $domiciliacionConfig['url_generar_liga_dom'] ?? 'https://pagadetodo.mx/Pagadetodo/Service/GenerarLigaDomiciliacionIndi';
$user = $domiciliacionConfig['user_dom'] ?? '';
$password = $domiciliacionConfig['password_dom'] ?? '';
$integration_id = $domiciliacionConfig['integration_id_dom'] ?? '124';
$business_id = $domiciliacionConfig['business_id_dom'] ?? '000002';
$dias_vigencia = $domiciliacionConfig['dias_vigencia_dom'] ?? 7;

// ============================================================
// GENERAR REFERENCIA: 9 dígitos de empresa + 6 dígitos de sufijo (TOTAL 15)
// ============================================================
$empresa_id_padded = str_pad($empresa_id, 9, '0', STR_PAD_LEFT);

// Función para generar un sufijo de 6 dígitos único
function generarSufijo6Digitos() {
    // Usamos microtime para obtener parte fraccionaria + random
    $micro = explode(' ', microtime());
    $frac = (int)($micro[0] * 1000000); // 6 dígitos de la fracción
    $sufijo = str_pad($frac, 6, '0', STR_PAD_LEFT);
    // Si por casualidad queda < 100000, complementamos con random
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
    // Verificar que no exista en la tabla domiciliacion_ligas
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM domiciliacion_ligas WHERE reference = ?");
    $stmt->execute([$reference_envio]);
    if ($stmt->fetchColumn() == 0) {
        break; // única, salimos del bucle
    }
    $intentos++;
    escribirLog("Referencia $reference_envio ya existe, reintentando ($intentos/$max_intentos)", 'WARNING');
}

if ($intentos >= $max_intentos) {
    // Si fallamos todos los intentos, forzamos con timestamp + random (muy improbable colisión)
    $sufijo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $reference_envio = $empresa_id_padded . $sufijo;
    escribirLog("Se forzó referencia: $reference_envio después de $max_intentos intentos", 'WARNING');
}

// ID para la transacción (hasta 10 dígitos, usamos los primeros 9)
$id_formateado = str_pad(substr($reference_envio, 0, 9), 9, '0', STR_PAD_LEFT);

$monto_centavos = intval($monto * 100);
$fecha_expiracion = date('Y-m-d', strtotime("+{$dias_vigencia} day"));

escribirLog("Referencia a enviar: $reference_envio (15 dígitos)", 'INFO');

// Construir datos para Pagadetodo
$data = [
    "User" => $user,
    "Password" => $password,
    "IntegrationID" => $integration_id,
    "BusinessID" => $business_id,
    "PaymentTypes" => "401",
    "Id" => $id_formateado,
    "Description" => substr($descripcion, 0, 40),
    "Amount" => (string)$monto_centavos,
    "Reference" => $reference_envio,
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
// CASO 1: ÉXITO - Guardar la referencia EXACTA que devuelve Pagadetodo
// ============================================================
if (isset($clean['url']) && !empty($clean['url'])) {
    escribirLog("ÉXITO: URL generada: " . $clean['url'], 'INFO');
    
    // Guardar la referencia que devuelve Pagadetodo (puede tener más dígitos, pero es la que ellos asignan)
    $reference_devuelta = $clean['reference'] ?? $reference_envio;
    $reference_emisor = $clean['referenceEmisor'] ?? $reference_envio;
    
    escribirLog("Referencia devuelta por pagalaescuela: $reference_devuelta", 'INFO');
    escribirLog("ReferenceEmisor: $reference_emisor", 'INFO');
    
    try {
        // Extraer plan de la descripción
        $plan = 'empresarial';
        if (strpos($descripcion, 'Básico') !== false) $plan = 'basico';
        elseif (strpos($descripcion, 'Profesional') !== false) $plan = 'profesional';
        elseif (strpos($descripcion, 'Plus') !== false) $plan = 'plus';
        
        $periodo = (strpos($descripcion, 'Anual') !== false) ? 'anual' : 'mensual';
        
        $stmt = $pdo->prepare(
            "INSERT INTO domiciliacion_ligas 
                (reference, reference_emisor, empresa_id, plan, periodo, monto, url_pago, status, created_at)
             VALUES 
                (:reference, :reference_emisor, :empresa_id, :plan, :periodo, :monto, :url_pago, 'pendiente', NOW())
             ON DUPLICATE KEY UPDATE
                url_pago = VALUES(url_pago),
                reference_emisor = VALUES(reference_emisor),
                updated_at = NOW()"
        );
        $stmt->execute([
            ':reference'        => $reference_devuelta,
            ':reference_emisor' => $reference_emisor,
            ':empresa_id'       => $empresa_id,
            ':plan'             => $plan,
            ':periodo'          => $periodo,
            ':monto'            => $monto,
            ':url_pago'         => $clean['url'] ?? '',
        ]);
        escribirLog("Liga guardada en BD con referencia: $reference_devuelta", 'INFO');
    } catch (PDOException $e) {
        escribirLog("Error guardando liga: " . $e->getMessage(), 'ERROR');
    }
    
    $response_array = [
        'success' => true,
        'url' => $clean['url'],
        'reference' => $reference_devuelta,
        'reference_emisor' => $reference_emisor,
        'id' => $id_formateado,
        'amount' => $monto,
        'description' => $descripcion,
        'empresa_id' => $empresa_id
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

// CASO 2: ERROR CON MENSAJE
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

// CASO 3: ERROR CON CÓDIGO NUMÉRICO
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

// CASO 4: NO CONTEMPLADO
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