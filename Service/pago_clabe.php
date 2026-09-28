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
    $montoRecibido = round((float) $input['monto'] / 100, 2);

    $transaccion = $input['transaccion'] ?? null;
    $fecha = $input['fecha'] ?? date('Y-m-d\TH:i:s\Z');

    $inputParaLog = $input;
    $inputParaLog['monto'] = $montoRecibido;

    // 👇 Agregamos tipo_servicio al SELECT
    $stmt = $pdo->prepare("
        SELECT id, account, estado, monto_pendiente, monto_total,
               cliente_email, cliente_nombre, descripcion, empresa_id,
               requiere_factura, razon_social, rfc, email_factura, regimen_fiscal, cp_factura, metodo_pago_sat, uso_cfdi,
               tipo_servicio
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

    // IDEMPOTENCIA
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

    // 👇 Bandera: ¿es un pago de tipo "Pago en Caja"?
    $esPagoEnCaja = (isset($registro['tipo_servicio']) && $registro['tipo_servicio'] === 'Pago en Caja');

    // ============================================================
    // Si es "Pago en Caja" NO hacemos nada más: ni suscripción,
    // ni correo, ni notificaciones, ni factura. Solo respondemos.
    // ============================================================
    if ($esPagoEnCaja) {
        $response = [
            'codigo' => 0,
            'mensaje' => 'Operación exitosa',
            'autorizacion' => $numeroAutorizacion,
            'transaccion' => $transaccion,
            'fecha' => date('Y-m-d', strtotime($fecha))
        ];
        logSpeiTransaction($pdo, 'pago', $clabe, $registro['account'], $inputParaLog, $response, 0);
        echo json_encode($response);
        exit;
    }

    // ============================================================
    // Activar la suscripción (solo para pagos que NO son en caja)
    // ============================================================
    $emp = null;
    $plan_a_usar = 'empresarial';
    $esAnualClabe = false;

    if ($nuevoEstado === 'pagada' && !empty($registro['empresa_id'])) {
        try {
            $descripcionClabe = $registro['descripcion'] ?? '';
            if (stripos($descripcionClabe, 'Básico') !== false || stripos($descripcionClabe, 'Basico') !== false) $plan_a_usar = 'basico';
            elseif (stripos($descripcionClabe, 'Profesional') !== false) $plan_a_usar = 'starer';
            elseif (stripos($descripcionClabe, 'Plus') !== false) $plan_a_usar = 'premium';

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

    // ============================================================
    // Registrar el pago exitoso en pagos_suscripciones
    // ============================================================
    if ($nuevoEstado === 'pagada' && !empty($registro['empresa_id'])) {
        try {
            $periodoPago = $esAnualClabe ? 'anual' : 'mensual';

            $stmtPagoSus = $pdo->prepare("
                INSERT INTO pagos_suscripciones
                    (empresa_id, monto, fecha_pago, referencia, tipo_pago, plan, periodo, status, foliocpagos, auth, raw_response, correo_enviado)
                VALUES
                    (:empresa_id, :monto, NOW(), :referencia, 'transferencia', :plan, :periodo, 'completado', :foliocpagos, :auth, :raw_response, 0)
            ");

            $stmtPagoSus->execute([
                ':empresa_id'   => $registro['empresa_id'],
                ':monto'        => $montoRecibido,
                ':referencia'   => $referencia,
                ':plan'         => $plan_a_usar,
                ':periodo'      => $periodoPago,
                ':foliocpagos'  => $transaccion,
                ':auth'         => $numeroAutorizacion,
                ':raw_response' => json_encode([
                    'clabe'        => $clabe,
                    'transaccion'  => $transaccion,
                    'monto'        => $montoRecibido,
                    'autorizacion' => $numeroAutorizacion,
                    'fecha'        => $fecha,
                    'input'        => $inputParaLog,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            error_log("Pago registrado en pagos_suscripciones para empresa {$registro['empresa_id']}");
        } catch (PDOException $e) {
            error_log("Error registrando pago en pagos_suscripciones: " . $e->getMessage());
        }
    }

    // ============================================================
    // NOTIFICAR A TODOS LOS ADMINISTRADORES
    // ============================================================
    if ($nuevoEstado === 'pagada' && !empty($registro['empresa_id'])) {
        try {
            $stmtAdmins = $pdo->prepare("
                SELECT id 
                FROM usuarios 
                WHERE rol_usuario = 'administrador' 
                  AND activo = 1
            ");
            $stmtAdmins->execute();
            $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

            if ($admins) {
                $nombreEmpresaNotif = $registro['cliente_nombre'] ?? 'N/A';

                $tituloNotif  = "Nuevo pago recibido (SPEI)";
                $mensajeNotif = sprintf(
                    "Se recibió un pago SPEI aprobado de $%s MXN para la empresa \"%s\" (ID %d). Referencia: %s | Autorización: %s",
                    number_format($montoRecibido, 2),
                    $nombreEmpresaNotif,
                    (int) $registro['empresa_id'],
                    $referencia,
                    $numeroAutorizacion
                );

                $sqlNotif = "INSERT INTO notificaciones 
                    (usuario_id, titulo, mensaje, tipo, leida, created_at)
                    VALUES 
                    (:usuario_id, :titulo, :mensaje, :tipo, 0, NOW())";
                $stmtNotif = $pdo->prepare($sqlNotif);

                foreach ($admins as $adm) {
                    $stmtNotif->execute([
                        ':usuario_id' => $adm['id'],
                        ':titulo'     => $tituloNotif,
                        ':mensaje'    => $mensajeNotif,
                        ':tipo'       => 'success',
                    ]);
                }

                error_log("Notificaciones SPEI enviadas a " . count($admins) . " administrador(es)");
            } else {
                error_log("No hay usuarios administradores activos para notificar (SPEI)");
            }
        } catch (PDOException $e) {
            error_log("Error al registrar notificaciones a administradores (SPEI): " . $e->getMessage());
        }
    }

    // ============================================================
    // Enviar correo de confirmación
    // ============================================================
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

        if ($enviado && !empty($registro['empresa_id'])) {
            try {
                $stmtUpdCorreo = $pdo->prepare("
                    UPDATE pagos_suscripciones 
                    SET correo_enviado = 1 
                    WHERE empresa_id = ? AND referencia = ? 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtUpdCorreo->execute([$registro['empresa_id'], $referencia]);
            } catch (PDOException $e) {
                error_log("No se pudo actualizar correo_enviado: " . $e->getMessage());
            }
        }

        // Crear organización Facturapi (solo premium)
        if (!empty($registro['empresa_id'])) {
            try {
                $resOrg = asegurarOrganizacionFacturapi(
                    $pdo,
                    (int) $registro['empresa_id'],
                    $plan_a_usar,
                    $emp['nombre_empresa'] ?? ($registro['cliente_nombre'] ?? '')
                );

                if ($resOrg['success']) {
                    if ($resOrg['creada']) {
                        error_log("Organización Facturapi creada para empresa {$registro['empresa_id']}. ID: {$resOrg['id']}");
                    } else {
                        error_log("Empresa {$registro['empresa_id']} ya tenía organización Facturapi: {$resOrg['id']}");
                    }
                } else {
                    error_log("No se pudo asegurar organización Facturapi (empresa {$registro['empresa_id']}): {$resOrg['message']}");
                }
            } catch (Exception $e) {
                error_log("Error inesperado asegurando organización Facturapi (SPEI): " . $e->getMessage());
            }
        }

        // Timbrar factura si el cliente la solicitó
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