<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

// Inicializamos $fecha ANTES del try para evitar "undefined variable"
// si la excepción ocurre antes de leer el input (ej. falla de conexión a BD).
$fecha = date('Y-m-d\TH:i:s\Z');

try {
    $pdo = getDBConnection();

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['clabe']) || !isset($input['monto'])) {
        $response = [
            'codigo' => 50,
            'mensaje' => 'Datos incompletos',
            'autorizacion' => null,
            'transaccion' => $input['transaccion'] ?? null,
            'fecha' => date('Y-m-d')
        ];
        echo json_encode($response);
        exit;
    }

    $clabe = $input['clabe'];

    // El monto viene multiplicado por 100 (ej: 20200 = $202.00).
    // round() a 2 decimales asegura que no se arrastren errores de
    // precisión de punto flotante antes de guardar en BD.
    $montoRecibido = round((float) $input['monto'] / 100, 2);

    $transaccion = $input['transaccion'] ?? null;
    $fecha = $input['fecha'] ?? date('Y-m-d\TH:i:s\Z');

    // Copia del input SOLO para logging: se sobrescribe el campo "monto"
    // con el valor ya convertido a pesos reales ($202.00 en vez de 20200),
    // así en spei_transacciones_log se ve directamente el importe real
    // sin necesidad de un campo adicional ni de recalcular nada.
    $inputParaLog = $input;
    $inputParaLog['monto'] = $montoRecibido;

    $stmt = $pdo->prepare("
        SELECT id, account, estado, monto_pendiente, monto_total
        FROM clabes_spei 
        WHERE clabe = ?
    ");
    $stmt->execute([$clabe]);
    $registro = $stmt->fetch();

    if (!$registro) {
        $response = [
            'codigo' => 40,
            'mensaje' => 'Adquiriente inválido',
            'autorizacion' => null,
            'transaccion' => $transaccion,
            'fecha' => date('Y-m-d')
        ];
        logSpeiTransaction($pdo, 'pago', $clabe, null, $inputParaLog, $response, 40);
        echo json_encode($response);
        exit;
    }

    if ($registro['estado'] === 'pagada') {
        $response = [
            'codigo' => 13,
            'mensaje' => 'Referencia sin adeudo',
            'autorizacion' => null,
            'transaccion' => $transaccion,
            'fecha' => date('Y-m-d')
        ];
        logSpeiTransaction($pdo, 'pago', $clabe, $registro['account'], $inputParaLog, $response, 13);
        echo json_encode($response);
        exit;
    }

    if (in_array($registro['estado'], ['cancelada', 'expirada'])) {
        $response = [
            'codigo' => 14,
            'mensaje' => 'Referencia fuera de vigencia',
            'autorizacion' => null,
            'transaccion' => $transaccion,
            'fecha' => date('Y-m-d')
        ];
        logSpeiTransaction($pdo, 'pago', $clabe, $registro['account'], $inputParaLog, $response, 14);
        echo json_encode($response);
        exit;
    }

    // IDEMPOTENCIA: si esta transacción ya fue procesada antes (reintento
    // de red, timeout, reenvío duplicado de Cobroscontarjeta.com), no
    // volvemos a descontar el monto ni a generar una nueva autorización.
    if ($transaccion !== null) {
        $stmtCheck = $pdo->prepare("
            SELECT numero_autorizacion, fecha_confirmacion
            FROM pagos_spei_recibidos
            WHERE clabe_id = ? AND transaccion = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$registro['id'], $transaccion]);
        $pagoExistente = $stmtCheck->fetch();

        if ($pagoExistente) {
            $response = [
                'codigo' => 0,
                'mensaje' => 'Operación exitosa',
                'autorizacion' => $pagoExistente['numero_autorizacion'],
                'transaccion' => $transaccion,
                'fecha' => date('Y-m-d', strtotime($pagoExistente['fecha_confirmacion']))
            ];
            logSpeiTransaction($pdo, 'pago', $clabe, $registro['account'], $inputParaLog, $response, 0);
            echo json_encode($response);
            exit;
        }
    }

    $montoEsperado = round((float) ($registro['monto_pendiente'] ?? $registro['monto_total'] ?? 0), 2);

    if ($montoRecibido > $montoEsperado) {
        $response = [
            'codigo' => 30,
            'mensaje' => 'Monto inválido',
            'autorizacion' => null,
            'transaccion' => $transaccion,
            'fecha' => date('Y-m-d')
        ];
        logSpeiTransaction($pdo, 'pago', $clabe, $registro['account'], $inputParaLog, $response, 30);
        echo json_encode($response);
        exit;
    }

    $numeroAutorizacion = str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();

    $nuevoMontoPendiente = round($montoEsperado - $montoRecibido, 2);
    $nuevoEstado = ($nuevoMontoPendiente <= 0) ? 'pagada' : 'vigente';

    $stmt = $pdo->prepare("
        UPDATE clabes_spei 
        SET estado = ?, 
            monto_pendiente = ?,
            numero_autorizacion = ?,
            fecha_pago = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$nuevoEstado, $nuevoMontoPendiente, $numeroAutorizacion, $registro['id']]);

    $stmt = $pdo->prepare("
        INSERT INTO pagos_spei_recibidos 
        (clabe_id, clabe, monto_recibido, referencia, numero_autorizacion, transaccion, estado, fecha_confirmacion, fecha_recibido)
        VALUES (?, ?, ?, ?, ?, ?, 'confirmado', NOW(), ?)
    ");
    $referencia = 'SPEI-' . date('YmdHis') . '-' . rand(100, 999);
    $stmt->execute([
        $registro['id'],
        $clabe,
        $montoRecibido,
        $referencia,
        $numeroAutorizacion,
        $transaccion,
        $fecha
    ]);

    $pdo->commit();

    // La respuesta debe regresar la fecha en formato yyyy-MM-dd (sin hora),
    // tal como especifica la documentación, no el valor crudo recibido en
    // el input (que puede venir como yyyy-MM-ddTHH:mm:ssZ).
    $response = [
        'codigo' => 0,
        'mensaje' => 'Operación exitosa',
        'autorizacion' => $numeroAutorizacion,
        'transaccion' => $transaccion,
        'fecha' => date('Y-m-d', strtotime($fecha))
    ];

    logSpeiTransaction($pdo, 'pago', $clabe, $registro['account'], $inputParaLog, $response, 0);
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error en autoriza_pago: " . $e->getMessage());

    $response = [
        'codigo' => 50,
        'mensaje' => 'Error de sistema',
        'autorizacion' => null,
        'transaccion' => $input['transaccion'] ?? null,
        'fecha' => date('Y-m-d', strtotime($fecha))
    ];
    echo json_encode($response);
}