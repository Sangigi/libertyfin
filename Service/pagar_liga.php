<?php
/**
 * pagar_liga.php
 *
 * Pagadetodo -> EMISOR
 * Webhook que Pagadetodo invoca cuando un cliente paga la liga generada en
 * GenerarLigaPagoSuscripcion.php (pago con tarjeta en el iframe de checkout).
 *
 * ANTES: este archivo solo guardaba el JSON crudo en `pagos_liga` y
 * respondía "aprobado" al gateway, sin actualizar nada más (así lo decía
 * su propio comentario: "SOLO RESPUESTA, SIN ACTUALIZAR OTRAS TABLAS").
 * Por eso el cliente pagaba y no pasaba nada: ni se activaba el plan, ni se
 * mandaba correo, ni se generaba la factura aunque hubiera llenado los
 * datos fiscales en el checkout.
 *
 * AHORA: además de seguir guardando el log crudo en pagos_liga (por
 * compatibilidad con lo que ya hubiera dependiendo de esa tabla), cuando
 * response == "approved":
 *   1. Busca la liga original en domiciliacion_ligas por reference.
 *   2. Activa/renueva el plan de la empresa.
 *   3. Marca la liga como pagada.
 *   4. Envía el correo de confirmación de pago.
 *   5. Si el cliente pidió factura, la timbra.
 *
 * IMPORTANTE: revisa en tu panel de Pagadetodo cuál es la URL de
 * notificación (webhook) realmente configurada para esta liga. En el repo
 * también existe Service/EntregarPagoLineaToken.php, que ya traía la
 * lógica de activación de plan (se reutiliza aquí vía el helper) pero
 * tampoco enviaba correo ni facturaba. Si tu URL registrada en Pagadetodo
 * es esa en vez de esta, dime y te paso el mismo parche ahí.
 */

// Config de conexión propia de este endpoint (igual que antes, sin tocar
// las credenciales según se pidió).
$host = 'libertyfin.com.mx';
$dbname = 'juanc141_ventas';
$username = 'juanc141_alexis';
$password = 'Alexis1997';

require_once __DIR__ . '/helpers_pagos_suscripcion.php';

// Recibir JSON
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Guardar log completo (igual que antes)
$log_entry = date("Y-m-d H:i:s") . "\n" . $input . "\n" . str_repeat("-", 50) . "\n";
file_put_contents(__DIR__ . "/log_pagar_liga.txt", $log_entry, FILE_APPEND);

// Validar JSON
if (!$data) {
    http_response_code(400);
    echo json_encode([
        "autorizacion" => "",
        "mensaje" => "JSON inválido",
        "transaccion" => "",
        "fecha" => ""
    ]);
    exit;
}

// Conectar a la base de datos
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    file_put_contents(__DIR__ . "/log_error_bd.txt", date("Y-m-d H:i:s") . " Error conexión: " . $e->getMessage() . "\n", FILE_APPEND);

    // Aún así respondemos al webhook (igual que antes)
    if (isset($data['response']) && $data['response'] == "approved") {
        echo json_encode([
            "autorizacion" => $data['auth'] ?? "",
            "mensaje" => "Pago aprobado (sin registro en BD)",
            "transaccion" => $data['foliocpagos'] ?? "",
            "fecha" => date("Y-m-d H:i:s")
        ]);
    } else {
        echo json_encode([
            "autorizacion" => "",
            "mensaje" => "Pago no aprobado",
            "transaccion" => "",
            "fecha" => date("Y-m-d H:i:s")
        ]);
    }
    exit;
}

// Extraer todos los campos del JSON (igual que antes)
$reference = $data['reference'] ?? null;
$response = $data['response'] ?? null;
$foliocpagos = $data['foliocpagos'] ?? null;
$auth = $data['auth'] ?? null;
$cd_response = $data['cd_response'] ?? null;
$cd_error = $data['cd_error'] ?? null;
$nb_error = $data['nb_error'] ?? null;
$time = $data['time'] ?? null;
$date = $data['date'] ?? null;
$nb_company = $data['nb_company'] ?? null;
$nb_merchant = $data['nb_merchant'] ?? null;
$cc_type = $data['cc_type'] ?? null;
$tp_operation = $data['tp_operation'] ?? null;
$cc_name = $data['cc_name'] ?? null;
$cc_number = $data['cc_number'] ?? null;
$cc_expmonth = $data['cc_expmonth'] ?? null;
$cc_expyear = $data['cc_expyear'] ?? null;
$amount = isset($data['amount']) ? floatval($data['amount']) : null;
$emv_key_date = $data['emv_key_date'] ?? null;
$id_url = $data['id_url'] ?? null;
$email = $data['email'] ?? null;
$payment_type = $data['payment_type'] ?? null;
$promocion = $data['promocion'] ?? null;
$number_tkn = $data['number_tkn'] ?? null;
$cc_mask = $data['cc_mask'] ?? null;

$raw_response = json_encode($data, JSON_UNESCAPED_UNICODE);

// Guardar el log crudo en pagos_liga (igual que antes)
try {
    $sql = "INSERT INTO pagos_liga (
        reference, response, foliocpagos, auth, cd_response, cd_error, nb_error,
        time, date, nb_company, nb_merchant, cc_type, tp_operation, cc_name,
        cc_number, cc_expmonth, cc_expyear, amount, emv_key_date, id_url,
        email, payment_type, promocion, number_tkn, cc_mask, raw_response
    ) VALUES (
        :reference, :response, :foliocpagos, :auth, :cd_response, :cd_error, :nb_error,
        :time, :date, :nb_company, :nb_merchant, :cc_type, :tp_operation, :cc_name,
        :cc_number, :cc_expmonth, :cc_expyear, :amount, :emv_key_date, :id_url,
        :email, :payment_type, :promocion, :number_tkn, :cc_mask, :raw_response
    )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':reference' => $reference,
        ':response' => $response,
        ':foliocpagos' => $foliocpagos,
        ':auth' => $auth,
        ':cd_response' => $cd_response,
        ':cd_error' => $cd_error,
        ':nb_error' => $nb_error,
        ':time' => $time,
        ':date' => $date,
        ':nb_company' => $nb_company,
        ':nb_merchant' => $nb_merchant,
        ':cc_type' => $cc_type,
        ':tp_operation' => $tp_operation,
        ':cc_name' => $cc_name,
        ':cc_number' => $cc_number,
        ':cc_expmonth' => $cc_expmonth,
        ':cc_expyear' => $cc_expyear,
        ':amount' => $amount,
        ':emv_key_date' => $emv_key_date,
        ':id_url' => $id_url,
        ':email' => $email,
        ':payment_type' => $payment_type,
        ':promocion' => $promocion,
        ':number_tkn' => $number_tkn,
        ':cc_mask' => $cc_mask,
        ':raw_response' => $raw_response
    ]);

    $pago_id = $pdo->lastInsertId();

    file_put_contents(__DIR__ . "/log_pagos_exitosos.txt",
        date("Y-m-d H:i:s") . " Pago guardado ID: $pago_id - Folio: $foliocpagos - Response: $response\n",
        FILE_APPEND
    );
} catch (PDOException $e) {
    file_put_contents(__DIR__ . "/log_error_bd.txt",
        date("Y-m-d H:i:s") . " Error al guardar: " . $e->getMessage() . "\n",
        FILE_APPEND
    );
}

// ============================================================
// NUEVO: si el pago fue aprobado, completar el flujo real
// ============================================================
if (isset($data['response']) && $data['response'] == "approved" && !empty($reference)) {
    try {
        pagosuscripcion_asegurar_columnas_fiscales($pdo, 'domiciliacion_ligas');

        $stmtLiga = $pdo->prepare("SELECT * FROM domiciliacion_ligas WHERE reference = :ref OR reference_emisor = :ref LIMIT 1");
        $stmtLiga->execute([':ref' => $reference]);
        $liga = $stmtLiga->fetch(PDO::FETCH_ASSOC);

        if (!$liga) {
            file_put_contents(__DIR__ . "/log_error_bd.txt",
                date("Y-m-d H:i:s") . " No se encontró domiciliacion_ligas para reference=$reference\n",
                FILE_APPEND
            );
        } elseif ($liga['status'] === 'pagado') {
            // Idempotencia: Pagadetodo puede reintentar la notificación.
            file_put_contents(__DIR__ . "/log_pagos_exitosos.txt",
                date("Y-m-d H:i:s") . " Reference $reference ya estaba marcada como pagada, se ignora duplicado.\n",
                FILE_APPEND
            );
        } else {
            $empresa_id = $liga['empresa_id'];
            $plan = $liga['plan'];
            $periodo = $liga['periodo'];
            $monto = $amount ?: $liga['monto'];

            $stmtEmp = $pdo->prepare("SELECT nombre_empresa, nombre_contacto, email_admin FROM empresas WHERE id = ?");
            $stmtEmp->execute([$empresa_id]);
            $empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];

            $pdo->beginTransaction();

            pagosuscripcion_activar_plan($pdo, $empresa_id, $plan, $periodo);

            $stmtUpd = $pdo->prepare("UPDATE domiciliacion_ligas SET status = 'pagado', updated_at = NOW() WHERE reference = :ref OR reference_emisor = :ref");
            $stmtUpd->execute([':ref' => $reference]);

            $pdo->commit();

            $emailDestino = $email ?: ($empresa['email_admin'] ?? null);
            pagosuscripcion_enviar_correo_confirmacion(
                $emailDestino,
                $empresa['nombre_contacto'] ?? null,
                $empresa['nombre_empresa'] ?? null,
                $plan,
                $periodo,
                $monto,
                $reference,
                'Tarjeta'
            );

            $fiscal = [
                'facturar' => $liga['facturar'] ?? 'no',
                'razon_social' => $liga['razon_social'] ?? null,
                'rfc' => $liga['rfc'] ?? null,
                'email_factura' => $liga['email_factura'] ?? null,
                'regimen_fiscal' => $liga['regimen_fiscal'] ?? null,
                'cp' => $liga['cp_fiscal'] ?? null,
                'metodo_pago' => $liga['metodo_pago_cfdi'] ?? null,
                'uso_cfdi' => $liga['uso_cfdi'] ?? null,
            ];
            pagosuscripcion_facturar($pdo, $fiscal, $monto, "Suscripcion {$plan} - {$periodo}", $reference, 'domiciliacion_ligas');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        file_put_contents(__DIR__ . "/log_error_bd.txt",
            date("Y-m-d H:i:s") . " Error completando flujo de pago aprobado ($reference): " . $e->getMessage() . "\n",
            FILE_APPEND
        );
    }
}

// Responder al webhook (igual formato que antes)
if (isset($data['response']) && $data['response'] == "approved") {
    echo json_encode([
        "autorizacion" => $auth,
        "mensaje" => "Pago aprobado",
        "transaccion" => $foliocpagos,
        "fecha" => date("Y-m-d H:i:s"),
        "id_registro" => $pago_id ?? null
    ]);
} else {
    echo json_encode([
        "autorizacion" => "",
        "mensaje" => "Pago no aprobado",
        "transaccion" => "",
        "fecha" => date("Y-m-d H:i:s"),
        "id_registro" => $pago_id ?? null
    ]);
}