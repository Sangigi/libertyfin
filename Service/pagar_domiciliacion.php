<?php
/**
 * pagar_domiciliacion.php
 *
 * EMISOR -> Pagadetodo
 * Ejecuta un Cargo Automático Individual (CAI) usando el token guardado.
 * Se puede ejecutar vía CRON para renovaciones automáticas.
 *
 * Uso esperado: llamado por AJAX/CRON, enviando por POST:
 *   empresa_id -> identifica la empresa y el token guardado
 *   monto     -> monto decimal a cobrar (ej. 350.00)
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
    $archivo = $logDir . "/pagar_domiciliacion_$fecha.log";
    $timestamp = date('Y-m-d H:i:s');
    $linea = "[$timestamp] [$tipo] $mensaje" . PHP_EOL;
    file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
}

// Verificar autenticación (opcional para CRON)
$esCron = isset($_POST['cron']) && $_POST['cron'] === 'true';

if (!$esCron) {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado.']);
        exit;
    }
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
$monto = isset($_POST['monto']) ? (float)$_POST['monto'] : 0;

$errores = [];
if ($empresa_id <= 0) {
    $errores[] = 'ID de empresa inválido.';
}
if ($monto < 50 || $monto > 15000) {
    $errores[] = 'El monto debe ser entre $50.00 y $15,000.00 MXN.';
}

if (!empty($errores)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(' ', $errores)]);
    exit;
}

escribirLog("Solicitud de cargo para empresa_id: $empresa_id, Monto: $monto", 'INFO');

// ---------------------------------------------------------
// 2. Recuperar el token guardado para la empresa
// ---------------------------------------------------------
try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("SELECT id, nombre_empresa, plan FROM empresas WHERE id = ? AND activo = 1");
    $stmt->execute([$empresa_id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada o inactiva.']);
        exit;
    }
    
    $stmt = $pdo->prepare(
        "SELECT number_tkn, cc_expmonth, cc_expyear, cc_mask FROM domiciliacion_tokens WHERE empresa_id = :empresa_id LIMIT 1"
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
$domiciliacionConfig = domiciliacionConfig();

$url = $domiciliacionConfig['url_pago_dom'] ?? '';
$user = $domiciliacionConfig['user_dom'] ?? '';
$password = $domiciliacionConfig['password_dom'] ?? '';
$integration_id = $domiciliacionConfig['integration_id_dom'] ?? '';
$business_id = $domiciliacionConfig['business_id_dom'] ?? '';

// ---------------------------------------------------------
// 4. Construir nueva referencia única para este cargo
// ---------------------------------------------------------
$sufijo = substr(str_pad((string)round(microtime(true) * 1000), 6, '0', STR_PAD_LEFT), -6);
$nuevaReference = str_pad($empresa_id, 9, '0', STR_PAD_LEFT) . $sufijo;

// ---------------------------------------------------------
// 5. Construir el payload
// ---------------------------------------------------------
$payload = [
    'User'          => $user,
    'Password'      => $password,
    'IntegrationID' => $integration_id,
    'BusinessID'    => $business_id,
    'Token'         => $tokenRow['number_tkn'],
    'Reference'     => $nuevaReference,
    'Amount'        => (string)intval($monto * 100),
    'ExpMonth'      => $tokenRow['cc_expmonth'],
    'ExpYear'       => $tokenRow['cc_expyear'],
];

escribirLog("Payload enviado: " . json_encode($payload), 'DEBUG');

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
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

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
if (($clean['code'] ?? '') === '00' && isset($clean['txResponse'])) {
    $txResponse = $clean['txResponse'];
    
    try {
        $stmtLog = $pdo->prepare(
            "INSERT INTO domiciliacion_cargos
                (reference, empresa_id, response, foliocpagos, auth, amount, voucher, created_at)
             VALUES
                (:reference, :empresa_id, :response, :foliocpagos, :auth, :amount, :voucher, NOW())"
        );
        $stmtLog->execute([
            ':reference'  => $txResponse['reference'] ?? $nuevaReference,
            ':empresa_id' => $empresa_id,
            ':response'   => $txResponse['response'] ?? '',
            ':foliocpagos'=> $txResponse['foliocpagos'] ?? null,
            ':auth'       => $txResponse['auth'] ?? '',
            ':amount'     => $txResponse['amount'] ?? $monto,
            ':voucher'    => $txResponse['voucher'] ?? '',
        ]);
        
        escribirLog("Cargo registrado en BD para empresa: $empresa_id", 'INFO');
    } catch (PDOException $e) {
        escribirLog("Error guardando bitácora de cargo: " . $e->getMessage(), 'ERROR');
    }

    $aprobado = ($txResponse['response'] ?? '') === 'approved';
    
    if ($aprobado) {
        try {
            $stmtUpdate = $pdo->prepare(
                "UPDATE empresas SET 
                    fecha_vencimiento = DATE_ADD(NOW(), INTERVAL 1 MONTH),
                    fecha_actualizacion = NOW()
                 WHERE id = :empresa_id"
            );
            $stmtUpdate->execute([':empresa_id' => $empresa_id]);
            escribirLog("Fecha de vencimiento actualizada para empresa: $empresa_id", 'INFO');
        } catch (PDOException $e) {
            escribirLog("Error actualizando fecha de vencimiento: " . $e->getMessage(), 'ERROR');
        }
    }
    
    echo json_encode([
        'success'    => $aprobado,
        'response'   => $txResponse['response'] ?? '',
        'auth'       => $txResponse['auth'] ?? '',
        'reference'  => $txResponse['reference'] ?? $nuevaReference,
        'message'    => $aprobado ? 'Cargo aprobado.' : ('Cargo no aprobado: ' . ($txResponse['nb_error'] ?? 'sin detalle')),
        'empresa_id' => $empresa_id
    ]);
    exit;
}

// Respuesta con error
$codigo = (string)($clean['code'] ?? '');
$mensaje = $clean['message'] ?? $clean['Message'] ?? 'Error desconocido';

escribirLog("Error en pago. Código: $codigo, Mensaje: $mensaje", 'ERROR');

http_response_code(422);
echo json_encode(['success' => false, 'code' => $codigo, 'message' => $mensaje]);