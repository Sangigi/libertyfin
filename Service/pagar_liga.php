<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuración de la base de datos - CAMBIA ESTOS VALORES
$host = 'libertyfin.com.mx';
$dbname = 'juanc141_ventas'; // Cambia por el nombre de tu BD
$username = 'juanc141_alexis';   // Cambia por tu usuario
$password = 'Alexis1997';  // Cambia por tu contraseña

// Recibir JSON
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Guardar log completo
$log_entry = date("Y-m-d H:i:s") . "\n" . $input . "\n" . str_repeat("-", 50) . "\n";
file_put_contents("log_pagar_liga.txt", $log_entry, FILE_APPEND);

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
    // Si hay error de conexión, solo logueamos y respondemos
    file_put_contents("log_error_bd.txt", date("Y-m-d H:i:s") . " Error conexión: " . $e->getMessage() . "\n", FILE_APPEND);
    
    // Aún así respondemos al webhook
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

// Extraer todos los campos del JSON
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

// Guardar el JSON completo como respaldo
$raw_response = json_encode($data, JSON_UNESCAPED_UNICODE);

try {
    // Insertar todos los datos en la tabla pagos_liga
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
    
    // Log de éxito
    file_put_contents("log_pagos_exitosos.txt", 
        date("Y-m-d H:i:s") . " Pago guardado ID: $pago_id - Folio: $foliocpagos - Response: $response\n", 
        FILE_APPEND
    );
    
} catch (PDOException $e) {
    file_put_contents("log_error_bd.txt", 
        date("Y-m-d H:i:s") . " Error al guardar: " . $e->getMessage() . "\n", 
        FILE_APPEND
    );
}

// Validar estatus y responder (SOLO RESPUESTA, SIN ACTUALIZAR OTRAS TABLAS)
if (isset($data['response']) && $data['response'] == "approved") {

    echo json_encode([
        "autorizacion" => $auth,
        "mensaje" => "Pago aprobado",
        "transaccion" => $foliocpagos,
        "fecha" => date("Y-m-d H:i:s"),
        "id_registro" => $pago_id ?? null
    ]);

} else {

    // Pago rechazado
    echo json_encode([
        "autorizacion" => "",
        "mensaje" => "Pago no aprobado",
        "transaccion" => "",
        "fecha" => date("Y-m-d H:i:s"),
        "id_registro" => $pago_id ?? null
    ]);
}
?>