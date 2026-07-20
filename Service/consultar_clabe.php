<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    $clabe = $_GET['r'] ?? '';
    
    if (empty($clabe) || !preg_match('/^\d{18}$/', $clabe)) {
        $response = [
            'codigo' => 15,
            'mensaje' => 'Referencia con error de formato',
            'monto' => 0,
            'clabe' => $clabe,
            'transaccion' => 0,
            'parcial' => false
        ];
        echo json_encode($response);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT id, account, cliente_email, cliente_nombre, descripcion,
               monto_total, monto_pendiente, fecha_expiracion, estado
        FROM clabes_spei 
        WHERE clabe = ?
    ");
    $stmt->execute([$clabe]);
    $registro = $stmt->fetch();
    
    if (!$registro) {
        $response = [
            'codigo' => 40,
            'mensaje' => 'Adquirente inválido',
            'monto' => 0,
            'clabe' => $clabe,
            'transaccion' => 0,
            'parcial' => false
        ];
        logSpeiTransaction($pdo, 'consulta', $clabe, null, $_GET, $response, 40);
        echo json_encode($response);
        exit;
    }
    
    if ($registro['estado'] === 'pagada') {
        $response = [
            'codigo' => 13,
            'mensaje' => 'Referencia sin adeudo',
            'monto' => 0,
            'clabe' => $clabe,
            'transaccion' => 0,
            'parcial' => false
        ];
        logSpeiTransaction($pdo, 'consulta', $clabe, $registro['account'], $_GET, $response, 13);
        echo json_encode($response);
        exit;
    }
    
    if ($registro['estado'] === 'cancelada') {
        $response = [
            'codigo' => 13,
            'mensaje' => 'Referencia cancelada',
            'monto' => 0,
            'clabe' => $clabe,
            'transaccion' => 0,
            'parcial' => false
        ];
        logSpeiTransaction($pdo, 'consulta', $clabe, $registro['account'], $_GET, $response, 13);
        echo json_encode($response);
        exit;
    }
    
    $fechaExpiracion = new DateTime($registro['fecha_expiracion']);
    $ahora = new DateTime();
    
    if ($fechaExpiracion < $ahora) {
        $stmt = $pdo->prepare("UPDATE clabes_spei SET estado = 'expirada' WHERE id = ?");
        $stmt->execute([$registro['id']]);
        
        $response = [
            'codigo' => 14,
            'mensaje' => 'Referencia fuera de vigencia',
            'monto' => 0,
            'clabe' => $clabe,
            'transaccion' => 0,
            'parcial' => false
        ];
        logSpeiTransaction($pdo, 'consulta', $clabe, $registro['account'], $_GET, $response, 14);
        echo json_encode($response);
        exit;
    }
    
    $montoPendienteCentavos = $registro['monto_pendiente'] ?? $registro['monto_total'] ?? 0;
    
    if ($montoPendienteCentavos == 0) {
        $stmt = $pdo->prepare("SELECT productos_json FROM clabes_spei WHERE id = ?");
        $stmt->execute([$registro['id']]);
        $productosData = $stmt->fetch();
        
        if ($productosData && $productosData['productos_json']) {
            $productos = json_decode($productosData['productos_json'], true);
            if ($productos) {
                foreach ($productos as $producto) {
                    $precio = ($producto['precio'] ?? 0);
                    $cantidad = ($producto['cantidad'] ?? 1);
                    $montoPendienteCentavos += (int) round($precio * $cantidad * 100);
                }
            }
        }
    }
    
    $stmt = $pdo->query("SELECT MAX(transaccion) as max_trans FROM pagos_spei_recibidos");
    $row = $stmt->fetch();
    $transaccion = ($row['max_trans'] ?? 0) + 1;
    
    // 🔥 MONTO COMO ENTERO SIN DECIMALES
    $response = [
        'codigo' => 0,
        'mensaje' => 'Operación exitosa',
        'monto' => $montoPendienteCentavos, // 🔥 Entero, ej: 5800
        'clabe' => $clabe,
        'transaccion' => (string) $transaccion,
        'parcial' => true
    ];
    
    logSpeiTransaction($pdo, 'consulta', $clabe, $registro['account'], $_GET, $response, 0);
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error en consulta_clabe: " . $e->getMessage());
    
    $response = [
        'codigo' => 50,
        'mensaje' => 'Error de sistema: ' . $e->getMessage(),
        'monto' => 0,
        'clabe' => $_GET['r'] ?? '',
        'transaccion' => 0,
        'parcial' => false
    ];
    echo json_encode($response);
}
?>