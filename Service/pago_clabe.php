<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/facturapi_suscripcion.php';

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
        SELECT id, account, estado, monto_pendiente, monto_total,
               cliente_email, cliente_nombre, descripcion, empresa_id,
               requiere_factura, razon_social, rfc, email_factura, regimen_fiscal, cp_factura, metodo_pago_sat, uso_cfdi
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

    // Activar la suscripción: actualizar plan y fecha de vencimiento de la
    // empresa cuando la CLABE queda completamente saldada (igual que ya se
    // hace en EntregarPagoLineaToken.php para el flujo de tarjeta).
    $emp = null;
    if ($nuevoEstado === 'pagada' && !empty($registro['empresa_id'])) {
        try {
            // Extraer plan y periodo de la descripción guardada al generar la CLABE
            $descripcionClabe = $registro['descripcion'] ?? '';
            $plan_a_usar = 'empresarial';
            if (stripos($descripcionClabe, 'Básico') !== false || stripos($descripcionClabe, 'Basico') !== false) $plan_a_usar = 'basico';
            elseif (stripos($descripcionClabe, 'Profesional') !== false) $plan_a_usar = 'profesional';
            elseif (stripos($descripcionClabe, 'Plus') !== false) $plan_a_usar = 'plus';

            $esAnualClabe = (stripos($descripcionClabe, 'Anual') !== false);
            $intervalo = $esAnualClabe ? "INTERVAL 1 YEAR" : "INTERVAL 1 MONTH";

            $stmtFecha = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = :empresa_id");
            $stmtFecha->execute([':empresa_id' => $registro['empresa_id']]);
            $empresaActual = $stmtFecha->fetch(PDO::FETCH_ASSOC);

            $fechaBase = 'NOW()';
            if ($empresaActual && $empresaActual['fecha_vencimiento']) {
                $fechaVencimiento = new DateTime($empresaActual['fecha_vencimiento']);
                $hoy = new DateTime();
                if ($fechaVencimiento > $hoy) {
                    $fechaBase = $fechaVencimiento->format('Y-m-d H:i:s');
                }
            }

            if ($fechaBase === 'NOW()') {
                $sqlUpdate = "UPDATE empresas SET 
                                plan = :plan, 
                                fecha_actualizacion = NOW(),
                                fecha_vencimiento = DATE_ADD(NOW(), $intervalo), 
                                activo = 1
                              WHERE id = :empresa_id";
                $stmtUpd = $pdo->prepare($sqlUpdate);
                $stmtUpd->execute([':plan' => $plan_a_usar, ':empresa_id' => $registro['empresa_id']]);
            } else {
                $sqlUpdate = "UPDATE empresas SET 
                                plan = :plan, 
                                fecha_actualizacion = NOW(),
                                fecha_vencimiento = DATE_ADD(:fecha_base, $intervalo), 
                                activo = 1
                              WHERE id = :empresa_id";
                $stmtUpd = $pdo->prepare($sqlUpdate);
                $stmtUpd->execute([':plan' => $plan_a_usar, ':empresa_id' => $registro['empresa_id'], ':fecha_base' => $fechaBase]);
            }

            error_log("Empresa {$registro['empresa_id']} actualizada por SPEI con plan: $plan_a_usar");
        } catch (PDOException $e) {
            error_log("Error activando suscripción por SPEI: " . $e->getMessage());
        }
    }

    // Enviar correo de confirmación si con este pago se saldó por completo
    if ($nuevoEstado === 'pagada') {
        $destino = $registro['cliente_email'] ?? null;
        if (!empty($registro['empresa_id'])) {
            try {
                $stmtEmp = $pdo->prepare("SELECT nombre_empresa, email_admin, fecha_vencimiento FROM empresas WHERE id = ?");
                $stmtEmp->execute([$registro['empresa_id']]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $emp = null;
            }
        }

        $enviado = enviarCorreoConfirmacionPago(
            $emp['email_admin'] ?? $destino,
            $emp['nombre_empresa'] ?? ($registro['cliente_nombre'] ?? 'Cliente'),
            $registro['descripcion'] ?? 'Suscripción',
            '',
            (float) $registro['monto_total'],
            'SPEI',
            $emp['fecha_vencimiento'] ?? null
        );
        error_log("Correo de confirmación SPEI " . ($enviado ? "enviado" : "NO enviado"));

        // Timbrar factura si el cliente la solicitó al generar la CLABE
        if (!empty($registro['requiere_factura'])) {
            $resultadoFactura = timbrarFacturaSuscripcion(
                [
                    'razon_social'    => $registro['razon_social'] ?? null,
                    'rfc'             => $registro['rfc'] ?? null,
                    'email_factura'   => $registro['email_factura'] ?? null,
                    'regimen_fiscal'  => $registro['regimen_fiscal'] ?? null,
                    'cp'              => $registro['cp_factura'] ?? null,
                    'metodo_pago_sat' => $registro['metodo_pago_sat'] ?? null,
                    'uso_cfdi'        => $registro['uso_cfdi'] ?? null,
                ],
                (float) $registro['monto_total'],
                'Suscripción LibertyFin - ' . ($registro['descripcion'] ?? '')
            );
            error_log("Timbrado de factura SPEI: " . json_encode($resultadoFactura));

            try {
                $chk = $pdo->query("SHOW COLUMNS FROM clabes_spei LIKE 'factura_uuid'");
                if ($chk->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE clabes_spei ADD COLUMN factura_uuid VARCHAR(50) DEFAULT NULL, ADD COLUMN factura_folio VARCHAR(20) DEFAULT NULL, ADD COLUMN factura_error TEXT DEFAULT NULL");
                }
                $stmtF = $pdo->prepare("UPDATE clabes_spei SET factura_uuid = :uuid, factura_folio = :folio, factura_error = :error WHERE id = :id");
                $stmtF->execute([
                    ':uuid' => $resultadoFactura['uuid'] ?? null,
                    ':folio' => $resultadoFactura['folio'] ?? null,
                    ':error' => $resultadoFactura['error'] ?? null,
                    ':id' => $registro['id'],
                ]);
            } catch (PDOException $e) {
                error_log("No se pudo guardar el resultado de la factura SPEI: " . $e->getMessage());
            }
        }
    }

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