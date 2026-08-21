<?php
// Service/generar_link_pago_paypal.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PayPalService.php';

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Sesión no válida']);
    exit();
}

$empresa_id = $_SESSION['empresa_id'] ?? 0;
$dbname = $_SESSION['empresa_db'] ?? '';

if (empty($dbname)) {
    echo json_encode(['success' => false, 'error' => 'Base de datos no especificada']);
    exit();
}

try {
    $conn = getEmpresaDBConnection($dbname);
    
    // Obtener credenciales de PayPal
    $sql = "SELECT paypal_client_id, paypal_secret, paypal_mode FROM sistema_config WHERE id = 1";
    $stmt = $conn->query($sql);
    $config = $stmt->fetch();
    
    if (!$config || empty($config['paypal_client_id']) || empty($config['paypal_secret'])) {
        echo json_encode(['success' => false, 'error' => 'Credenciales de PayPal no configuradas']);
        exit();
    }
    
    $monto = floatval($_POST['monto'] ?? 0);
    $descripcion = $_POST['descripcion'] ?? 'Pago en caja';
    
    if ($monto <= 0) {
        echo json_encode(['success' => false, 'error' => 'Monto inválido']);
        exit();
    }
    
    // Obtener moneda de la empresa
    $sql_moneda = "SELECT moneda FROM sistema_config WHERE id = 1";
    $stmt_moneda = $conn->query($sql_moneda);
    $moneda_data = $stmt_moneda->fetch();
    $moneda = $moneda_data['moneda'] ?? 'MXN';
    
    // Crear servicio PayPal
    $paypal = new PayPalService(
        $config['paypal_client_id'],
        $config['paypal_secret'],
        $config['paypal_mode'] ?? 'sandbox'
    );
    
    // Generar referencia única
    $referencia = 'VENTA_' . date('YmdHis') . '_' . rand(1000, 9999);
    
    // Crear orden
    $result = $paypal->createOrder($monto, $moneda, $referencia);
    
    if (isset($result['id'])) {
        // Guardar referencia en sesión
        $_SESSION['paypal_order_id'] = $result['id'];
        $_SESSION['paypal_amount'] = $monto;
        $_SESSION['paypal_reference'] = $referencia;
        
        // Buscar el link de aprobación
        $approveLink = null;
        foreach ($result['links'] as $link) {
            if ($link['rel'] === 'approve') {
                $approveLink = $link['href'];
                break;
            }
        }
        
        echo json_encode([
            'success' => true,
            'order_id' => $result['id'],
            'approve_url' => $approveLink,
            'reference' => $referencia,
            'amount' => $monto
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al crear orden PayPal']);
    }
    
} catch (Exception $e) {
    error_log('Error PayPal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}