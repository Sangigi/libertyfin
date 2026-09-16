<?php
/**
 * consultar_status_pago.php
 *
 * Endpoint NUEVO para que el checkout (planes.js) pueda preguntar, mientras
 * el usuario está pagando en el iframe/CLABE, si el webhook ya marcó el
 * pago como confirmado. Antes no existía ningún mecanismo (ni éste, ni
 * postMessage desde el iframe) para que el frontend se enterara de que el
 * pago se completó, así que el usuario pagaba y la pantalla no cambiaba.
 *
 * Uso: GET/POST Service/consultar_status_pago.php?reference=...&tipo=tarjeta
 *      Service/consultar_status_pago.php?reference=...&tipo=spei  (reference = CLABE)
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$reference = trim($_GET['reference'] ?? $_POST['reference'] ?? '');
$tipo = trim($_GET['tipo'] ?? $_POST['tipo'] ?? 'tarjeta');

if ($reference === '') {
    echo json_encode(['success' => false, 'error' => 'Falta reference']);
    exit;
}

try {
    $pdo = getDBConnection();

    if ($tipo === 'spei') {
        $stmt = $pdo->prepare("SELECT estado FROM clabes_spei WHERE clabe = ? LIMIT 1");
        $stmt->execute([$reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'CLABE no encontrada']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'pagado' => $row['estado'] === 'pagada',
            'estado' => $row['estado'],
        ]);
    } else {
        $stmt = $pdo->prepare("SELECT status FROM domiciliacion_ligas WHERE reference = ? OR reference_emisor = ? LIMIT 1");
        $stmt->execute([$reference, $reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Referencia no encontrada']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'pagado' => $row['status'] === 'pagado' || $row['status'] === 'approved',
            'estado' => $row['status'],
        ]);
    }
} catch (Exception $e) {
    error_log('consultar_status_pago: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno']);
}