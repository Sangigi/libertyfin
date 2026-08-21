<?php
/**
 * cancelar_domiciliacion.php
 *
 * EMISOR -> Pagadetodo
 * Elimina/cancela el token de una tarjeta previamente domiciliada.
 *
 * Uso esperado: llamado por AJAX desde el panel, enviando por POST:
 *   empresa_id -> identifica la empresa cuyo token se va a cancelar
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Cargar configuración centralizada
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

// ============ FUNCIÓN PARA LOG ============
function escribirLog($mensaje, $tipo = 'INFO') {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $fecha = date('Y-m-d');
    $archivo = $logDir . "/cancelar_domiciliacion_$fecha.log";
    $timestamp = date('Y-m-d H:i:s');
    $linea = "[$timestamp] [$tipo] $mensaje" . PHP_EOL;
    file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
}

// Verificar autenticación
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

// ---------------------------------------------------------
// 1. Leer y validar datos de entrada
// ---------------------------------------------------------
$empresa_id = isset($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : 0;

if ($empresa_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de empresa inválido.']);
    exit;
}

escribirLog("Solicitud de cancelación para empresa_id: $empresa_id", 'INFO');

// ---------------------------------------------------------
// 2. Recuperar el token guardado para esa empresa
// ---------------------------------------------------------
try {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT id, nombre_empresa FROM empresas WHERE id = ? AND activo = 1");
    $stmt->execute([$empresa_id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada o inactiva.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT number_tkn, cc_expmonth, cc_expyear FROM domiciliacion_tokens WHERE empresa_id = :empresa_id LIMIT 1"
    );
    $stmt->execute([':empresa_id' => $empresa_id]);
    $tokenRow = $stmt->fetch();
} catch (PDOException $e) {
    escribirLog("Error consultando token: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno consultando el token.']);
    exit;
}

if (!$tokenRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Esta empresa no tiene una tarjeta domiciliada.']);
    exit;
}

if (empty($tokenRow['number_tkn'])) {
    escribirLog("number_tkn vacío para empresa_id={$empresa_id}", 'ERROR');
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'El token guardado está vacío.']);
    exit;
}

// ---------------------------------------------------------
// 3. Obtener configuración SPEI
// ---------------------------------------------------------
$speiConfig = speiConfig();
$domiciliacionConfig = domiciliacionConfig();

$url = $domiciliacionConfig['url_cancelar_dom'] ?? '';
$user = $domiciliacionConfig['user_dom'] ?? '';
$password = $domiciliacionConfig['password_dom'] ?? '';
$integration_id = $domiciliacionConfig['integration_id_dom'] ?? '';
$business_id = $domiciliacionConfig['business_id_dom'] ?? '';



// --- FIX 3: validar credenciales antes de llamar al servicio ---
if ($user === '' || $password === '') {
    escribirLog("Configuración SPEI incompleta: falta user o password (speiConfig).", 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuración de conexión incompleta. Contacta al administrador.']);
    exit;
}

// ---------------------------------------------------------
// 4. Construir Tkn_reference
// ---------------------------------------------------------
// --- FIX 2: agregar componente aleatorio para evitar colisiones cuando
// dos solicitudes caen en la misma ventana de milisegundo ---
$sufijo6 = substr(str_pad((string)round(microtime(true) * 1000), 6, '0', STR_PAD_LEFT), -3)
    . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
$tknReference = str_pad($empresa_id, 9, '0', STR_PAD_LEFT) . $sufijo6;

// ---------------------------------------------------------
// 5. Construir el payload
// ---------------------------------------------------------
$payload = [
    'User'          => $user,
    'Password'      => $password,
    'IntegrationID' => $integration_id,
    'BusinessID'    => $business_id,
    'Token'         => $tokenRow['number_tkn'],
    'Tkn_reference' => $tknReference
];

$payloadLog = $payload;
$payloadLog['Password'] = '***';
escribirLog("Payload enviado: " . json_encode($payloadLog), 'DEBUG');

// ---------------------------------------------------------
// 6. Llamar al servicio
// ---------------------------------------------------------
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, $speiConfig['timeout'] ?? 30);
// --- FIX 1: verificación SSL activada. Si el sandbox usa un certificado
// autofirmado, define SPEI_SANDBOX_INSECURE_SSL=true en config.php en vez
// de desactivar esto globalmente. ---
$insecureSsl = defined('SPEI_SANDBOX_INSECURE_SSL') && SPEI_SANDBOX_INSECURE_SSL === true;
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$insecureSsl);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $insecureSsl ? 0 : 2);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    escribirLog("Error CURL: " . $error_msg, 'ERROR');
    curl_close($ch);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $error_msg]);
    exit;
}
curl_close($ch);

escribirLog("Respuesta HTTP: $httpCode", 'INFO');
escribirLog("Respuesta: " . $response, 'DEBUG');

$body = json_decode($response, true);

if ($body === null) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Respuesta inválida del servicio.', 'raw' => $response]);
    exit;
}

$clean = [];
foreach ($body as $key => $value) {
    $clean[trim($key)] = $value;
}

// Éxito: code "00"
if (($clean['code'] ?? '') === '00') {
    try {
        $stmtDel = $pdo->prepare(
            "DELETE FROM domiciliacion_tokens WHERE empresa_id = :empresa_id"
        );
        $stmtDel->execute([':empresa_id' => $empresa_id]);
        escribirLog("Token eliminado localmente para empresa_id: $empresa_id", 'INFO');
    } catch (PDOException $e) {
        escribirLog("Error eliminando token local: " . $e->getMessage(), 'ERROR');
    }

    echo json_encode([
        'success' => true,
        'message' => $clean['message'] ?? 'Token cancelado correctamente.',
        'empresa_id' => $empresa_id
    ]);
    exit;
}

// Respuesta con error
$codigo = (string)($clean['code'] ?? '');
$mensaje = $clean['message'] ?? $clean['Message'] ?? 'Error desconocido';

escribirLog("Falló la cancelación. Código: $codigo, Mensaje: $mensaje", 'ERROR');

http_response_code(422);
echo json_encode(['success' => false, 'code' => $codigo, 'message' => $mensaje]);