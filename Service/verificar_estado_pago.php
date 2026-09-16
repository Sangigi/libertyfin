<?php
/**
 * verificar_estado_pago.php
 *
 * Endpoint de polling: el frontend lo consulta cada pocos segundos
 * despues de generar una liga de pago (tarjeta) o una CLABE (SPEI)
 * para saber si ya se acredito, sin tener que recargar la pagina.
 *
 * POST JSON:
 *   { "tipo": "tarjeta", "reference": "..." }
 *   { "tipo": "spei", "clabe": "..." }
 *
 * Respuesta:
 *   { "success": true, "status": "pendiente|aprobado|rechazado", "plan": "...", "periodo": "..." }
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$tipo = $input['tipo'] ?? '';

if ($tipo === 'tarjeta') {
    $reference = $input['reference'] ?? '';
    if ($reference === '') {
        echo json_encode(['success' => false, 'error' => 'Falta reference']);
        exit();
    }

    // La referencia que Pagadetodo devuelve (y que llega al webhook) puede
    // tener mas digitos que la que nosotros mandamos, por eso buscamos con LIKE.
    $stmt = $pdo->prepare("SELECT status, plan, periodo, monto FROM domiciliacion_ligas WHERE reference = :ref OR reference LIKE :ref_like ORDER BY id DESC LIMIT 1");
    $stmt->execute([
        ':ref' => $reference,
        ':ref_like' => '%' . $reference . '%',
    ]);
    $liga = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$liga) {
        echo json_encode(['success' => true, 'status' => 'pendiente']);
        exit();
    }

    $status = 'pendiente';
    if ($liga['status'] === 'approved') $status = 'aprobado';
    elseif ($liga['status'] === 'denied') $status = 'rechazado';

    echo json_encode([
        'success' => true,
        'status' => $status,
        'plan' => $liga['plan'],
        'periodo' => $liga['periodo'],
        'monto' => $liga['monto'],
    ]);
    exit();
}

if ($tipo === 'spei') {
    $clabe = $input['clabe'] ?? '';
    if ($clabe === '') {
        echo json_encode(['success' => false, 'error' => 'Falta clabe']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT estado, monto_total, monto_pendiente FROM clabes_spei WHERE clabe = :clabe ORDER BY id DESC LIMIT 1");
    $stmt->execute([':clabe' => $clabe]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        echo json_encode(['success' => true, 'status' => 'pendiente']);
        exit();
    }

    $status = 'pendiente';
    if ($reg['estado'] === 'pagada') $status = 'aprobado';
    elseif (in_array($reg['estado'], ['cancelada', 'expirada'])) $status = 'rechazado';

    echo json_encode([
        'success' => true,
        'status' => $status,
        'monto_total' => $reg['monto_total'],
        'monto_pendiente' => $reg['monto_pendiente'],
    ]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Tipo no reconocido']);