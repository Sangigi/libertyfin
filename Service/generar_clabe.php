<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../config/database.php';
    
    $speiConfig = speiConfig();
    $pdo = getDBConnection();
    
    $inputRaw = file_get_contents('php://input');
    
    if (empty($inputRaw)) {
        throw new Exception('No se recibieron datos');
    }
    
    $data = json_decode($inputRaw, true);
    
    if ($data === null) {
        throw new Exception('JSON inválido');
    }
    
    function generarAccountUnico() {
        $microtime = microtime(true);
        $timestamp = (int) ($microtime * 1000000);
        $random = rand(10000, 99999);
        $account = substr($timestamp . $random, 0, 15);
        if (strlen($account) < 15) {
            $account = str_pad($account, 15, '0');
        }
        return $account;
    }
    
    $account = generarAccountUnico();
    $clienteEmail = $data['CustomerEmail'] ?? 'cliente@libertyfin.com.mx';
    $clienteNombre = $data['CustomerName'] ?? 'Cliente Libertyfin';
    $descripcion = $data['Description'] ?? 'Pago Libertyfin';
    $montoTotal = isset($data['MontoTotal']) ? (float) $data['MontoTotal'] : 0;
    $montoTotalCentavos = (int) round($montoTotal);
    $productos = $data['Productos'] ?? null;
    
    $payload = [
        'User' => $speiConfig['user'],
        'Password' => $speiConfig['password'],
        'IntegrationID' => $speiConfig['integration_id'],
        'BusinessID' => $speiConfig['business_id'],
        'Description' => $descripcion . ' - ' . date('Y-m-d H:i:s'),
        'Account' => $account,
        'CustomerEmail' => $clienteEmail,
        'CustomerName' => $clienteNombre,
        'ExpirationDate' => date('Y-m-d', strtotime('+1 day'))
    ];
    
    error_log("SPEI Payload - Account: " . $account . " - Descripción: " . $payload['Description']);
    
    $ch = curl_init($speiConfig['url_generar']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('Error de conexión: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        throw new Exception('Error HTTP ' . $httpCode . ': ' . $response);
    }
    
    $apiResponse = json_decode($response, true);
    
    if (!$apiResponse || !isset($apiResponse['Clabe']) || empty($apiResponse['Clabe'])) {
        $errorMsg = $apiResponse['Error'] ?? $apiResponse['Message'] ?? 'No se recibió CLABE válida';
        throw new Exception('Error de Cobroscontarjeta: ' . $errorMsg);
    }
    
    $stmt = $pdo->prepare("
        UPDATE clabes_spei 
        SET estado = 'expirada' 
        WHERE cliente_email = ? AND estado = 'vigente'
    ");
    $stmt->execute([$clienteEmail]);
    
    $stmt = $pdo->prepare("
        INSERT INTO clabes_spei (
            account, clabe, cliente_email, cliente_nombre, descripcion,
            monto_total, monto_pendiente, fecha_expiracion, estado, 
            folio, productos_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'vigente', ?, ?)
    ");
    
    $fechaExpiracion = date('Y-m-d H:i:s', strtotime('+1 day'));
    
    $stmt->execute([
        $account,
        $apiResponse['Clabe'],
        $clienteEmail,
        $clienteNombre,
        $descripcion,
        $montoTotalCentavos,
        $montoTotalCentavos,
        $fechaExpiracion,
        $apiResponse['Folio'] ?? null,
        $productos ? json_encode($productos, JSON_UNESCAPED_UNICODE) : null
    ]);
    
    $id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'clabe' => $apiResponse['Clabe'],
        'account' => $account,
        'message' => $apiResponse['Message'] ?? 'Exitosa',
        'folio' => $apiResponse['Folio'] ?? null,
        'id' => $id,
        'fecha_expiracion' => $fechaExpiracion,
        'reutilizada' => false,
        'nueva' => true,
        'monto_total' => $montoTotal,
        'monto_total_centavos' => $montoTotalCentavos
    ]);
    
} catch (Exception $e) {
    error_log("Error en generar_clabe: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>