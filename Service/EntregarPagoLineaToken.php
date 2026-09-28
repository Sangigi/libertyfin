<?php
/**
 * entregar_pago_liga_token.php
 *
 * Pagadetodo -> EMISOR
 * Endpoint que Pagadetodo invoca cuando un cliente paga la liga.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/facturapi_suscripcion.php';

function escribirLog($mensaje, $tipo = 'INFO') {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $archivo = $logDir . "/entregar_pago_" . date('Y-m-d') . ".log";
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($archivo, "[$timestamp] [$tipo] $mensaje" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => '01', 'message' => 'Método no permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

escribirLog("=== NOTIFICACIÓN RECIBIDA ===", 'INFO');
escribirLog("Datos: " . $raw, 'DEBUG');

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['code' => '01', 'message' => 'JSON inválido.']);
    exit;
}

// Extraer campos
$reference = $data['reference'] ?? '';
$response = $data['response'] ?? '';
$foliocpagos = $data['foliocpagos'] ?? null;
$auth = $data['auth'] ?? '';
$cdResponse = $data['cd_response'] ?? '';
$cdError = $data['cd_error'] ?? '';
$nbError = $data['nb_error'] ?? '';
$time = $data['time'] ?? '';
$date = $data['date'] ?? '';
$nbCompany = $data['nb_company'] ?? '';
$nbMerchant = $data['nb_merchant'] ?? '';
$ccType = $data['cc_type'] ?? '';
$tpOperation = $data['tp_operation'] ?? '';
$ccName = $data['cc_name'] ?? '';
$ccNumber = $data['cc_number'] ?? '';
$ccExpMonth = $data['cc_expmonth'] ?? '';
$ccExpYear = $data['cc_expyear'] ?? '';
$amount = $data['amount'] ?? null;
$email = $data['email'] ?? '';
$paymentType = $data['payment_type'] ?? '';
$numberTkn = $data['number_tkn'] ?? '';
$ccMask = $data['cc_mask'] ?? '';

escribirLog("Reference: $reference", 'INFO');
escribirLog("Respuesta: $response", 'INFO');

if ($reference === '') {
    http_response_code(400);
    echo json_encode(['code' => '21', 'message' => 'Referencia obligatoria.']);
    exit;
}

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception("Error de conexión a BD");
    }
} catch (Exception $e) {
    escribirLog("Error conexión: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['code' => '99', 'message' => 'Error de conexión.']);
    exit;
}

$empresa_id = 0;
$plan_encontrado = null;
$periodo_encontrado = null;
$tipo_servicio = null;
$liga = null;

try {
    // ============================================================
    // BUSCAR POR LA REFERENCIA EXACTA
    // ============================================================
    $stmt = $pdo->prepare("SELECT empresa_id, plan, periodo, requiere_factura, razon_social, rfc, email_factura, regimen_fiscal, cp, metodo_pago_sat, uso_cfdi, tipo_servicio FROM domiciliacion_ligas WHERE reference = :reference LIMIT 1");
    $stmt->execute([':reference' => $reference]);
    $liga = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($liga) {
        $empresa_id = $liga['empresa_id'];
        $plan_encontrado = $liga['plan'];
        $periodo_encontrado = $liga['periodo'];
        $tipo_servicio = $liga['tipo_servicio'] ?? null;
        escribirLog("LIGA ENCONTRADA! empresa_id: $empresa_id, plan: $plan_encontrado, periodo: $periodo_encontrado, tipo_servicio: $tipo_servicio", 'INFO');
    } else {
        escribirLog("NO se encontró liga con reference: $reference", 'WARNING');
        
        // Extraer ID de empresa de la referencia (posición 7-15)
        if (strlen($reference) >= 15) {
            $empresa_id = (int)substr($reference, 6, 9);
            escribirLog("ID empresa extraído: $empresa_id", 'INFO');
        }
        
        if ($empresa_id <= 0 && !empty($email)) {
            $stmt = $pdo->prepare("SELECT id FROM empresas WHERE email = :email OR email_admin = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($emp) {
                $empresa_id = $emp['id'];
                escribirLog("Empresa por email: $empresa_id", 'INFO');
            }
        }
        
        // ============================================================
        // REFUERZO: Si no se encontró la liga por reference, buscar
        // el tipo_servicio de la liga más reciente de esa empresa
        // para detectar correctamente si es "pago en caja"
        // ============================================================
        if ($empresa_id > 0) {
            try {
                $stmtAlt = $pdo->prepare("
                    SELECT plan, periodo, requiere_factura, razon_social, rfc, 
                           email_factura, regimen_fiscal, cp, metodo_pago_sat, uso_cfdi, 
                           tipo_servicio 
                    FROM domiciliacion_ligas 
                    WHERE empresa_id = :empresa_id 
                      AND tipo_servicio LIKE '%pago en caja%'
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
                $stmtAlt->execute([':empresa_id' => $empresa_id]);
                $ligaAlt = $stmtAlt->fetch(PDO::FETCH_ASSOC);
                
                if ($ligaAlt) {
                    $liga = $ligaAlt;
                    $tipo_servicio = $ligaAlt['tipo_servicio'];
                    if (!$plan_encontrado) $plan_encontrado = $ligaAlt['plan'];
                    if (!$periodo_encontrado) $periodo_encontrado = $ligaAlt['periodo'];
                    escribirLog("Liga de PAGO EN CAJA encontrada por empresa_id: $empresa_id, tipo_servicio: $tipo_servicio", 'INFO');
                } else {
                    escribirLog("No se encontró liga de pago en caja para empresa_id: $empresa_id", 'INFO');
                }
            } catch (PDOException $e) {
                escribirLog("Error buscando liga alternativa por empresa: " . $e->getMessage(), 'ERROR');
            }
        }
    }
} catch (PDOException $e) {
    escribirLog("Error buscando liga: " . $e->getMessage(), 'ERROR');
}

if ($empresa_id <= 0) {
    http_response_code(404);
    echo json_encode(['code' => '99', 'message' => 'Empresa no encontrada.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, nombre_empresa, fecha_vencimiento, email_admin FROM empresas WHERE id = ?");
    $stmt->execute([$empresa_id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['code' => '99', 'message' => 'Empresa no existe.']);
        exit;
    }
    escribirLog("Empresa: " . $empresa['nombre_empresa'], 'INFO');
} catch (PDOException $e) {
    escribirLog("Error verificando empresa: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['code' => '99', 'message' => 'Error interno.']);
    exit;
}

// ============================================================
// DETERMINAR SI ES PAGO EN CAJA
// ============================================================
$esPagoEnCaja = ($tipo_servicio !== null && stripos($tipo_servicio, 'pago en caja') !== false);

if ($esPagoEnCaja) {
    escribirLog("TIPO DE SERVICIO: PAGO EN CAJA detectado. Solo se registrará el pago (sin pagos_suscripciones, sin correos, sin notificaciones, sin factura).", 'INFO');
} else {
    escribirLog("TIPO DE SERVICIO: FLUJO NORMAL (no es pago en caja). tipo_servicio=" . ($tipo_servicio ?? 'NULL'), 'INFO');
}

try {
    $pdo->beginTransaction();

    // 1. Registrar pago (SIEMPRE se ejecuta)
    $fecha_pago = null;
    if ($date !== '') {
        $d = DateTime::createFromFormat('d/m/Y', $date);
        if ($d) $fecha_pago = $d->format('Y-m-d');
    }
    
    $sql = "INSERT INTO domiciliacion_pagos
        (reference, response, foliocpagos, auth, cd_response, cd_error, nb_error,
         fecha_pago, hora_pago, nb_company, nb_merchant, cc_type, tp_operation,
         cc_name, cc_number, cc_expmonth, cc_expyear, amount, email, payment_type,
         cc_mask, raw_payload, created_at)
     VALUES
        (:reference, :response, :foliocpagos, :auth, :cd_response, :cd_error, :nb_error,
         :fecha_pago, :hora_pago, :nb_company, :nb_merchant, :cc_type, :tp_operation,
         :cc_name, :cc_number, :cc_expmonth, :cc_expyear, :amount, :email, :payment_type,
         :cc_mask, :raw_payload, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':reference' => $reference,
        ':response' => $response,
        ':foliocpagos' => $foliocpagos,
        ':auth' => $auth,
        ':cd_response' => $cdResponse,
        ':cd_error' => $cdError,
        ':nb_error' => $nbError,
        ':fecha_pago' => $fecha_pago,
        ':hora_pago' => $time,
        ':nb_company' => $nbCompany,
        ':nb_merchant' => $nbMerchant,
        ':cc_type' => $ccType,
        ':tp_operation' => $tpOperation,
        ':cc_name' => $ccName,
        ':cc_number' => $ccNumber,
        ':cc_expmonth' => $ccExpMonth,
        ':cc_expyear' => $ccExpYear,
        ':amount' => $amount,
        ':email' => $email,
        ':payment_type' => $paymentType,
        ':cc_mask' => $ccMask,
        ':raw_payload' => $raw,
    ]);
    $pagoId = $pdo->lastInsertId();
    escribirLog("Pago registrado ID: $pagoId", 'INFO');

    // ============================================================
    // SI ES PAGO EN CAJA: SOLO REGISTRAR PAGO Y ACTUALIZAR LIGA
    // (NO pagos_suscripciones, NO correos, NO notificaciones, NO factura)
    // ============================================================
    if ($esPagoEnCaja) {
        // Actualizar status de la liga
        $status = 'error';
        if ($response === 'approved') $status = 'approved';
        elseif ($response === 'denied') $status = 'denied';
        
        $stmtLiga = $pdo->prepare("UPDATE domiciliacion_ligas SET status = :status, updated_at = NOW() WHERE reference = :reference");
        $stmtLiga->execute([':status' => $status, ':reference' => $reference]);
        $filas = $stmtLiga->rowCount();
        escribirLog("Liga actualizada status: $status (Filas: $filas)", 'INFO');
        
        $pdo->commit();
        escribirLog("Transacción OK (PAGO EN CAJA - solo registro de pago, sin pagos_suscripciones ni correos)", 'INFO');
        
        http_response_code(200);
        echo json_encode(['code' => '00', 'message' => 'Recibido correctamente.']);
        exit;
    }

    // ============================================================
    // FLUJO NORMAL (NO ES PAGO EN CAJA)
    // ============================================================

    // 2. Si aprobado y token, guardar
    if ($response === 'approved' && !empty($numberTkn)) {
        escribirLog("Guardando token para empresa $empresa_id", 'INFO');
        
        $stmtCheck = $pdo->prepare("SELECT id FROM domiciliacion_tokens WHERE empresa_id = :empresa_id");
        $stmtCheck->execute([':empresa_id' => $empresa_id]);
        $existe = $stmtCheck->fetch();
        
        if ($existe) {
            $sqlTok = "UPDATE domiciliacion_tokens SET 
                number_tkn = :number_tkn, cc_expmonth = :cc_expmonth, cc_expyear = :cc_expyear,
                cc_mask = :cc_mask, reference_origen = :reference_origen, updated_at = NOW()
                WHERE empresa_id = :empresa_id";
        } else {
            $sqlTok = "INSERT INTO domiciliacion_tokens 
                (empresa_id, reference_origen, number_tkn, cc_expmonth, cc_expyear, cc_mask, created_at, updated_at)
                VALUES (:empresa_id, :reference_origen, :number_tkn, :cc_expmonth, :cc_expyear, :cc_mask, NOW(), NOW())";
        }
        
        $stmtTok = $pdo->prepare($sqlTok);
        $stmtTok->execute([
            ':empresa_id' => $empresa_id,
            ':reference_origen' => $reference,
            ':number_tkn' => $numberTkn,
            ':cc_expmonth' => $ccExpMonth,
            ':cc_expyear' => $ccExpYear,
            ':cc_mask' => $ccMask,
        ]);
        escribirLog("Token guardado", 'INFO');

        // 3. Actualizar plan de empresa con la duración correcta
        $plan_a_usar = $plan_encontrado ?? 'empresarial';
        
        if ($periodo_encontrado && strpos(strtolower($periodo_encontrado), 'anual') !== false) {
            $intervalo = "INTERVAL 1 YEAR";
            $tipo_periodo = "ANUAL";
            escribirLog("Periodo ANUAL detectado: duración 1 año", 'INFO');
        } else {
            $intervalo = "INTERVAL 1 MONTH";
            $tipo_periodo = "MENSUAL";
            escribirLog("Periodo MENSUAL detectado: duración 1 mes", 'INFO');
        }
        
        $stmtFecha = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = :empresa_id");
        $stmtFecha->execute([':empresa_id' => $empresa_id]);
        $empresaActual = $stmtFecha->fetch(PDO::FETCH_ASSOC);
        
        $fechaBase = 'NOW()';
        $fechaBaseStr = 'NOW()';
        
        if ($empresaActual && $empresaActual['fecha_vencimiento']) {
            $fechaVencimiento = new DateTime($empresaActual['fecha_vencimiento']);
            $hoy = new DateTime();
            
            if ($fechaVencimiento > $hoy) {
                $fechaBase = $fechaVencimiento->format('Y-m-d H:i:s');
                $fechaBaseStr = $fechaVencimiento->format('Y-m-d H:i:s');
                escribirLog("Renovación: sumando período ($tipo_periodo) a fecha_vencimiento actual: $fechaBaseStr", 'INFO');
            } else {
                $fechaBase = 'NOW()';
                $fechaBaseStr = 'NOW()';
                escribirLog("Servicio expirado (fecha_vencimiento: {$empresaActual['fecha_vencimiento']}), usando fecha actual para nueva vigencia", 'INFO');
            }
        } else {
            escribirLog("Sin fecha de vencimiento previa, usando NOW()", 'INFO');
        }
        
        if ($fechaBase === 'NOW()') {
            $sqlUpdate = "UPDATE empresas SET 
                            plan = :plan, 
                            fecha_actualizacion = NOW(),
                            fecha_vencimiento = DATE_ADD(NOW(), $intervalo), 
                            activo = 1
                          WHERE id = :empresa_id";
            $stmtUpd = $pdo->prepare($sqlUpdate);
            $stmtUpd->execute([
                ':plan' => $plan_a_usar, 
                ':empresa_id' => $empresa_id
            ]);
            escribirLog("Empresa $empresa_id actualizada con plan: $plan_a_usar, nueva fecha de vencimiento calculada desde NOW()", 'INFO');
        } else {
            $sqlUpdate = "UPDATE empresas SET 
                            plan = :plan, 
                            fecha_actualizacion = NOW(),
                            fecha_vencimiento = DATE_ADD(:fecha_base, $intervalo), 
                            activo = 1
                          WHERE id = :empresa_id";
            $stmtUpd = $pdo->prepare($sqlUpdate);
            $stmtUpd->execute([
                ':plan' => $plan_a_usar, 
                ':empresa_id' => $empresa_id,
                ':fecha_base' => $fechaBase
            ]);
            escribirLog("Empresa $empresa_id actualizada con plan: $plan_a_usar, nueva fecha de vencimiento calculada desde: $fechaBaseStr ($tipo_periodo)", 'INFO');
        }
        
        $stmtVerificar = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = :empresa_id");
        $stmtVerificar->execute([':empresa_id' => $empresa_id]);
        $nuevaFecha = $stmtVerificar->fetch(PDO::FETCH_ASSOC);
        if ($nuevaFecha) {
            escribirLog("NUEVA FECHA DE VENCIMIENTO: " . $nuevaFecha['fecha_vencimiento'], 'INFO');
        }
    }

    // 4. Actualizar status de la liga
    $status = 'error';
    if ($response === 'approved') $status = 'approved';
    elseif ($response === 'denied') $status = 'denied';
    
    $stmtLiga = $pdo->prepare("UPDATE domiciliacion_ligas SET status = :status, updated_at = NOW() WHERE reference = :reference");
    $stmtLiga->execute([':status' => $status, ':reference' => $reference]);
    $filas = $stmtLiga->rowCount();
    escribirLog("Liga actualizada status: $status (Filas: $filas)", 'INFO');

    // ============================================================
    // 5. GUARDAR EN pagos_suscripciones
    // ============================================================
    if ($status === 'approved') {
        try {
            $tipoPago = 'tdc';

            $periodoEnum = null;
            if ($periodo_encontrado) {
                $periodoLower = strtolower($periodo_encontrado);
                if (strpos($periodoLower, 'anual') !== false) {
                    $periodoEnum = 'anual';
                } else {
                    $periodoEnum = 'mensual';
                }
            }

            $montoPesos = ((float) $amount);

            $fechaPagoDatetime = date('Y-m-d H:i:s');
            if ($fecha_pago && $time) {
                $fechaPagoDatetime = $fecha_pago . ' ' . $time;
            } elseif ($fecha_pago) {
                $fechaPagoDatetime = $fecha_pago . ' ' . date('H:i:s');
            }

            $sqlPagoSusc = "INSERT INTO pagos_suscripciones 
                (empresa_id, monto, fecha_pago, referencia, tipo_pago, plan, periodo, status, 
                 foliocpagos, auth, cc_mask, raw_response, created_at, correo_enviado)
                VALUES 
                (:empresa_id, :monto, :fecha_pago, :referencia, :tipo_pago, :plan, :periodo, :status,
                 :foliocpagos, :auth, :cc_mask, :raw_response, NOW(), 0)";
            
            $stmtPagoSusc = $pdo->prepare($sqlPagoSusc);
            $stmtPagoSusc->execute([
                ':empresa_id' => $empresa_id,
                ':monto' => $montoPesos,
                ':fecha_pago' => $fechaPagoDatetime,
                ':referencia' => $reference,
                ':tipo_pago' => $tipoPago,
                ':plan' => $plan_encontrado ?? 'empresarial',
                ':periodo' => $periodoEnum,
                ':status' => $status,
                ':foliocpagos' => $foliocpagos,
                ':auth' => $auth,
                ':cc_mask' => $ccMask,
                ':raw_response' => $raw,
            ]);
            $pagoSuscId = $pdo->lastInsertId();
            escribirLog("Pago suscripción registrado ID: $pagoSuscId en pagos_suscripciones (tipo_pago=tdc)", 'INFO');
        } catch (PDOException $e) {
            escribirLog("Error al guardar en pagos_suscripciones: " . $e->getMessage(), 'ERROR');
        }
    }

    // ============================================================
    // 6. NOTIFICAR A TODOS LOS ADMINISTRADORES
    // ============================================================
    if ($status === 'approved') {
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
                $tituloNotif  = "Nuevo pago recibido";
                $montoPesos   = ((float) $amount);
                $mensajeNotif = sprintf(
                    "Se recibió un pago aprobado de $%s MXN para la empresa \"%s\" (ID %d). " .
                    "Plan: %s | Periodo: %s | Referencia: %s | Folio: %s",
                    number_format($montoPesos, 2),
                    $empresa['nombre_empresa'] ?? 'N/A',
                    $empresa_id,
                    $plan_encontrado ?? 'N/A',
                    $periodo_encontrado ?? 'N/A',
                    $reference,
                    $foliocpagos ?? 'N/A'
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

                escribirLog("Notificaciones enviadas a " . count($admins) . " administrador(es)", 'INFO');
            } else {
                escribirLog("No hay usuarios administradores activos para notificar", 'WARNING');
            }
        } catch (PDOException $e) {
            escribirLog("Error al registrar notificaciones a administradores: " . $e->getMessage(), 'ERROR');
        }
    }
    
    $pdo->commit();
    escribirLog("Transacción OK", 'INFO');

    // Enviar correo de confirmación si el pago fue aprobado
    if ($status === 'approved') {
        $emailDestino = $empresa['email_admin'] ?? $email;
        $stmtVig = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
        $stmtVig->execute([$empresa_id]);
        $vig = $stmtVig->fetch(PDO::FETCH_ASSOC);
        $enviado = enviarCorreoConfirmacionPago(
            $emailDestino,
            $empresa['nombre_empresa'],
            $plan_encontrado ?? 'N/A',
            $periodo_encontrado ?? 'N/A',
            ((float) $amount),
            'Tarjeta',
            $vig['fecha_vencimiento'] ?? null
        );
        escribirLog("Correo de confirmación " . ($enviado ? "enviado" : "NO enviado") . " a: $emailDestino", 'INFO');

        if (isset($pagoSuscId) && $pagoSuscId > 0) {
            try {
                $stmtUpdCorreo = $pdo->prepare("UPDATE pagos_suscripciones SET correo_enviado = :enviado WHERE id = :id");
                $stmtUpdCorreo->execute([
                    ':enviado' => $enviado ? 1 : 0,
                    ':id' => $pagoSuscId
                ]);
                escribirLog("Campo correo_enviado actualizado en pagos_suscripciones ID: $pagoSuscId", 'INFO');
            } catch (PDOException $e) {
                escribirLog("Error actualizando correo_enviado: " . $e->getMessage(), 'ERROR');
            }
        }

        // ============================================================
        // CREAR ORGANIZACIÓN EN FACTURAPI (solo si es premium)
        // ============================================================
        try {
            $resOrg = asegurarOrganizacionFacturapi(
                $pdo,
                $empresa_id,
                $plan_encontrado ?? '',
                $empresa['nombre_empresa'] ?? ''
            );

            if ($resOrg['success']) {
                if ($resOrg['creada']) {
                    escribirLog("Organización Facturapi creada para empresa $empresa_id. ID: {$resOrg['id']}", 'INFO');
                } else {
                    escribirLog("Empresa $empresa_id ya tenía organización Facturapi: {$resOrg['id']}", 'INFO');
                }
            } else {
                escribirLog("No se pudo asegurar organización Facturapi: {$resOrg['message']}", 'WARNING');
            }
        } catch (Exception $e) {
            escribirLog("Error inesperado asegurando organización Facturapi: " . $e->getMessage(), 'ERROR');
        }

        // Timbrar factura si el cliente la solicitó al pagar
        if (!empty($liga['requiere_factura'])) {
            $descripcionFactura = "Suscripción LibertyFin - Plan " . ucfirst($plan_encontrado ?? '') . " (" . ($periodo_encontrado ?? '') . ")";
            $resultadoFactura = timbrarFacturaSuscripcion(
                [
                    'razon_social'    => $liga['razon_social'] ?? null,
                    'rfc'             => $liga['rfc'] ?? null,
                    'email_factura'   => $liga['email_factura'] ?? null,
                    'regimen_fiscal'  => $liga['regimen_fiscal'] ?? null,
                    'cp'              => $liga['cp'] ?? null,
                    'metodo_pago_sat' => $liga['metodo_pago_sat'] ?? null,
                    'uso_cfdi'        => $liga['uso_cfdi'] ?? null,
                ],
                ((float) $amount),
                $descripcionFactura
            );
            escribirLog("Timbrado de factura: " . json_encode($resultadoFactura), $resultadoFactura['success'] ? 'INFO' : 'ERROR');

            try {
                $chk = $pdo->query("SHOW COLUMNS FROM domiciliacion_ligas LIKE 'factura_uuid'");
                if ($chk->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE domiciliacion_ligas ADD COLUMN factura_uuid VARCHAR(50) DEFAULT NULL, ADD COLUMN factura_folio VARCHAR(20) DEFAULT NULL, ADD COLUMN factura_error TEXT DEFAULT NULL");
                }
            } catch (PDOException $e) {
                escribirLog("No se pudieron crear columnas de factura en domiciliacion_ligas: " . $e->getMessage(), 'ERROR');
            }

            try {
                $stmtF = $pdo->prepare("UPDATE domiciliacion_ligas SET factura_uuid = :uuid, factura_folio = :folio, factura_error = :error WHERE reference = :reference");
                $stmtF->execute([
                    ':uuid' => $resultadoFactura['uuid'] ?? null,
                    ':folio' => $resultadoFactura['folio'] ?? null,
                    ':error' => $resultadoFactura['error'] ?? null,
                    ':reference' => $reference,
                ]);
            } catch (PDOException $e) {
                escribirLog("No se pudo guardar el resultado de la factura: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    // Respuesta exitosa
    http_response_code(200);
    echo json_encode(['code' => '00', 'message' => 'Recibido correctamente.']);
    
} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    escribirLog("ERROR BD: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['code' => '99', 'message' => 'Error interno.']);
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    escribirLog("ERROR GENERAL: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['code' => '99', 'message' => 'Error interno.']);
    exit;
}
?>