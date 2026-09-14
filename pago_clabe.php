<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers_pagos_suscripcion.php';

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
    $montoRecibido = round((float) $input['monto'] / 100, 2);

    $transaccion = $input['transaccion'] ?? null;
    $fecha = $input['fecha'] ?? date('Y-m-d\TH:i:s\Z');

    $inputParaLog = $input;
    $inputParaLog['monto'] = $montoRecibido;

    pagosuscripcion_asegurar_columnas_fiscales($pdo, 'clabes_spei');

    $stmt = $pdo->prepare("
        SELECT id, account, empresa_id, plan, periodo, cliente_email, cliente_nombre,
               facturar, razon_social, rfc, email_factura, regimen_fiscal, cp_fiscal,
               metodo_pago_cfdi, uso_cfdi, estado, monto_pendiente, monto_total
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

    // IDEMPOTENCIA: si esta transacción ya fue procesada antes, no se
    // vuelve a descontar ni a reactivar el plan ni a reenviar el correo.
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

    // ============================================================
    // NUEVO: si con este abono la CLABE queda saldada, activar el plan,
    // avisar por correo y facturar (igual que hace pagar_liga.php para
    // el pago con tarjeta). Antes esto no existía: la CLABE quedaba en
    // 'pagada' pero nada más se enteraba.
    // ============================================================
    if ($nuevoEstado === 'pagada' && !empty($registro['empresa_id'])) {
        pagosuscripcion_activar_plan($pdo, $registro['empresa_id'], $registro['plan'], $registro['periodo']);
    }

    $pdo->commit();

    if ($nuevoEstado === 'pagada' && !empty($registro['empresa_id'])) {
        $stmtEmp = $pdo->prepare("SELECT nombre_empresa, nombre_contacto, email_admin FROM empresas WHERE id = ?");
        $stmtEmp->execute([$registro['empresa_id']]);
        $empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];

        $emailDestino = $registro['cliente_email'] ?: ($empresa['email_admin'] ?? null);
        pagosuscripcion_enviar_correo_confirmacion(
            $emailDestino,
            $registro['cliente_nombre'] ?? ($empresa['nombre_contacto'] ?? null),
            $empresa['nombre_empresa'] ?? null,
            $registro['plan'],
            $registro['periodo'],
            $montoEsperado,
            $clabe,
            'SPEI'
        );

        $fiscal = [
            'facturar' => $registro['facturar'] ?? 'no',
            'razon_social' => $registro['razon_social'] ?? null,
            'rfc' => $registro['rfc'] ?? null,
            'email_factura' => $registro['email_factura'] ?? null,
            'regimen_fiscal' => $registro['regimen_fiscal'] ?? null,
            'cp' => $registro['cp_fiscal'] ?? null,
            'metodo_pago' => $registro['metodo_pago_cfdi'] ?? null,
            'uso_cfdi' => $registro['uso_cfdi'] ?? null,
        ];
        $descripcionFactura = "Suscripcion {$registro['plan']} - {$registro['periodo']}";

        // En clabes_spei el identificador único de este pago es la propia
        // CLABE (no existe columna 'reference' como en domiciliacion_ligas).
        pagosuscripcion_facturar($pdo, $fiscal, $montoEsperado, $descripcionFactura, $clabe, 'clabes_spei', 'clabe');
    }

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