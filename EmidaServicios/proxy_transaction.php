<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Obtener el tipo de operación del body
$input = json_decode(file_get_contents('php://input'), true);
$esPago = ($input['tipo_operacion'] === 'pago' || $input['flow_type'] === 'B' || $input['flow_type'] === 'F');

// Determinar el endpoint remoto
if ($esPago) {
    $url = 'http://104.248.179.142/BillPaymentUserFee.php';
} else {
    $url = 'http://104.248.179.142/pinDistSale.php';
}

// Inicializar cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Timeout más largo para transacciones
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($input))
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo json_encode([
        'success' => false, 
        'responseMessage' => 'Error de conexión: ' . curl_error($ch),
        'responseCode' => 'ERROR_CONEXION'
    ]);
} else {
    http_response_code($httpCode);
    echo $response;
}

curl_close($ch);
?>