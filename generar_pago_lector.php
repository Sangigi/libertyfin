<?php
// generar_pago_lector.php
session_start();
require_once 'vendor/autoload.php';

// Configuración PagaDetodo - SANDBOX
define('USER_PAGADETODO', 'p9E5Vdu5Ya');
define('PASSWORD_PAGADETODO', 'Ak63MKo#1/');
define('INTEGRATIONID_PAGADETODO', '124');
define('BUSINESSID_PAGADETODO', '000060');
define('URL_GENERAR_PAGO', 'https://pagadetodo.mx/Pagadetodo/Service/GenerarPagoLectorIndi');

// Verificar autenticación
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

header('Content-Type: application/json');

try {
    // Validar datos de entrada
    $monto = floatval($_POST['monto'] ?? 0);
    $referencia = $_POST['referencia'] ?? '';
    $descripcion = $_POST['descripcion'] ?? 'Pago en caja';
    
    if ($monto <= 0) {
        throw new Exception('El monto debe ser mayor a 0');
    }
    
    if ($monto < 50) {
        throw new Exception('El monto mínimo es $50.00');
    }
    
    // Generar referencia única si no se proporcionó
    if (empty($referencia)) {
        $referencia = generarReferenciaUnica();
    }
    
    // Convertir a centavos
    $montoCentavos = intval($monto * 100);
    
    // Preparar payload según documentación
    $payload = [
        "User"          => USER_PAGADETODO,
        "Password"      => PASSWORD_PAGADETODO,
        "IntegrationID" => INTEGRATIONID_PAGADETODO,
        "BusinessID"    => BUSINESSID_PAGADETODO,
        "Reference"     => $referencia,
        "Amount"        => $montoCentavos,
        "Description"   => substr($descripcion, 0, 100),
        "CustomerEmail" => $_POST['email'] ?? ''
    ];
    
    error_log("📤 Enviando pago a PagaDetodo: " . json_encode($payload));
    
    // Ejecutar CURL
    $curl = curl_init(URL_GENERAR_PAGO);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false // Solo para pruebas
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        throw new Exception("Error CURL: " . $error);
    }
    
    error_log("📥 Respuesta PagaDetodo: " . $response);
    
    $resultado = json_decode($response, true);
    
    if (!$resultado) {
        throw new Exception("Respuesta inválida del servidor");
    }
    
    // Formatear respuesta
    $response_data = [
        'success' => true,
        'code' => $resultado['code'] ?? 'ERROR',
        'message' => $resultado['message'] ?? 'Procesado',
        'reference' => $referencia,
        'monto' => $monto,
        'fecha' => date('Y-m-d H:i:s')
    ];
    
    // Si hay codeQR, agregarlo
    if (isset($resultado['codeQR'])) {
        $response_data['codeQR'] = $resultado['codeQR'];
        $response_data['qr_url'] = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=10&data=' . 
                                   urlencode($resultado['codeQR']);
    }
    
    echo json_encode($response_data);
    
} catch (Exception $e) {
    error_log("❌ Error en generar_pago_lector.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Genera una referencia única de 15 dígitos
 */
function generarReferenciaUnica() {
    $timestamp = time();
    $random = rand(10000, 99999);
    $referencia = $timestamp . $random;
    return substr(str_pad($referencia, 15, '0', STR_PAD_LEFT), 0, 15);
}
?>