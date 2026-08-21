<?php
// paypal_webhook.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once 'PayPalService.php';

header('Content-Type: application/json');

// Leer el payload
$payload = file_get_contents('php://input');
$headers = getallheaders();

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit();
}

$data = json_decode($payload, true);

if (!isset($data['event_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event']);
    exit();
}

// Procesar solo eventos de pago completado
if ($data['event_type'] !== 'PAYMENT.CAPTURE.COMPLETED') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit();
}

// Verificar firma del webhook (implementar según documentación de PayPal)

$orderId = $data['resource']['supplementary_data']['related_ids']['order_id'] ?? '';
$captureId = $data['resource']['id'] ?? '';

if (empty($orderId)) {
    http_response_code(400);
    echo json_encode(['error' => 'No order ID']);
    exit();
}

$dbname = $_SESSION['empresa_db'] ?? '';

try {
    $conn = getEmpresaDBConnection($dbname);
    
    // Buscar venta por order_id
    $sql = "SELECT id, estado FROM ventas WHERE paypal_order_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$orderId]);
    $venta = $stmt->fetch();
    
    if ($venta && $venta['estado'] !== 'completada') {
        // Actualizar venta
        $sql_update = "UPDATE ventas SET 
            paypal_status = 'completed',
            estado = 'completada'
            WHERE id = ?";
        $stmt = $conn->prepare($sql_update);
        $stmt->execute([$venta['id']]);
        
        // Actualizar caja
        $sql_caja = "UPDATE caja SET 
            total_ventas = COALESCE(total_ventas, 0) + (
                SELECT total FROM ventas WHERE id = ?
            )
            WHERE id = (SELECT caja_id FROM ventas WHERE id = ?)";
        $stmt = $conn->prepare($sql_caja);
        $stmt->execute([$venta['id'], $venta['id']]);
        
        error_log("Webhook PayPal: Venta {$venta['id']} completada automáticamente");
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    
} catch (Exception $e) {
    error_log('Webhook PayPal error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}