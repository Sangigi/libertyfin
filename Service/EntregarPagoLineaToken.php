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

try {
    // ============================================================
    // BUSCAR POR LA REFERENCIA EXACTA
    // ============================================================
    $stmt = $pdo->prepare("SELECT empresa_id, plan, periodo FROM domiciliacion_ligas WHERE reference = :reference LIMIT 1");
    $stmt->execute([':reference' => $reference]);
    $liga = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($liga) {
        $empresa_id = $liga['empresa_id'];
        $plan_encontrado = $liga['plan'];
        $periodo_encontrado = $liga['periodo'];
        escribirLog("LIGA ENCONTRADA! empresa_id: $empresa_id, plan: $plan_encontrado, periodo: $periodo_encontrado", 'INFO');
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
    $stmt = $pdo->prepare("SELECT id, nombre_empresa, fecha_vencimiento FROM empresas WHERE id = ?");
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

try {
    $pdo->beginTransaction();

    // 1. Registrar pago
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
        // ============================================================
        // NUEVA LÓGICA: Calcular vigencia a partir de fecha_vencimiento
        // ============================================================
        $plan_a_usar = $plan_encontrado ?? 'empresarial';
        
        // Determinar la duración según el PERIODO
        if ($periodo_encontrado && strpos(strtolower($periodo_encontrado), 'anual') !== false) {
            $intervalo = "INTERVAL 1 YEAR";
            $tipo_periodo = "ANUAL";
            escribirLog("Periodo ANUAL detectado: duración 1 año", 'INFO');
        } else {
            $intervalo = "INTERVAL 1 MONTH";
            $tipo_periodo = "MENSUAL";
            escribirLog("Periodo MENSUAL detectado: duración 1 mes", 'INFO');
        }
        
        // Obtener la fecha_vencimiento actual para calcular la nueva
        $stmtFecha = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = :empresa_id");
        $stmtFecha->execute([':empresa_id' => $empresa_id]);
        $empresaActual = $stmtFecha->fetch(PDO::FETCH_ASSOC);
        
        $fechaBase = 'NOW()';
        $fechaBaseStr = 'NOW()';
        
        if ($empresaActual && $empresaActual['fecha_vencimiento']) {
            $fechaVencimiento = new DateTime($empresaActual['fecha_vencimiento']);
            $hoy = new DateTime();
            
            // Verificar si la fecha de vencimiento es futura
            if ($fechaVencimiento > $hoy) {
                // Si es futura, sumamos el período a la fecha de vencimiento actual
                $fechaBase = $fechaVencimiento->format('Y-m-d H:i:s');
                $fechaBaseStr = $fechaVencimiento->format('Y-m-d H:i:s');
                escribirLog("Renovación: sumando período ($tipo_periodo) a fecha_vencimiento actual: $fechaBaseStr", 'INFO');
            } else {
                // Si ya expiró, usar fecha actual
                $fechaBase = 'NOW()';
                $fechaBaseStr = 'NOW()';
                escribirLog("Servicio expirado (fecha_vencimiento: {$empresaActual['fecha_vencimiento']}), usando fecha actual para nueva vigencia", 'INFO');
            }
        } else {
            escribirLog("Sin fecha de vencimiento previa, usando NOW()", 'INFO');
        }
        
        // Construir y ejecutar la consulta de actualización
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
        
        // Verificar la nueva fecha de vencimiento
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

    $pdo->commit();
    escribirLog("Transacción OK", 'INFO');
    
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