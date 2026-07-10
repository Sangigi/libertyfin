<?php
session_start();
header('Content-Type: application/json');

// Configuración de la base de datos
$db_config = [
    'host' => 'libertyfin.com.mx',
    'user' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'database' => 'juanc141_ventas'
];

// Función para conectar a la base de datos
function getDBConnection($config) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Error de conexión a BD: " . $e->getMessage());
        return null;
    }
}

// Función para guardar log en BD
function guardarLogEnBD($pdo, $datos) {
    if (!$pdo) return false;
    
    try {
        $sql = "INSERT INTO pagos_generadas (
                    fecha, monto, descripcion, request_data, response_data, 
                    status, url_generada, reference, id_generado, http_code, 
                    error_message, ip_usuario, user_agent
                ) VALUES (
                    NOW(), :monto, :descripcion, :request_data, :response_data,
                    :status, :url_generada, :reference, :id_generado, :http_code,
                    :error_message, :ip_usuario, :user_agent
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':monto' => $datos['monto'] ?? null,
            ':descripcion' => $datos['descripcion'] ?? null,
            ':request_data' => $datos['request_data'] ?? null,
            ':response_data' => $datos['response_data'] ?? null,
            ':status' => $datos['status'] ?? null,
            ':url_generada' => $datos['url_generada'] ?? null,
            ':reference' => $datos['reference'] ?? null,
            ':id_generado' => $datos['id_generado'] ?? null,
            ':http_code' => $datos['http_code'] ?? null,
            ':error_message' => $datos['error_message'] ?? null,
            ':ip_usuario' => $datos['ip_usuario'] ?? null,
            ':user_agent' => $datos['user_agent'] ?? null
        ]);
        
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error al guardar log en BD: " . $e->getMessage());
        return false;
    }
}

// Conectar a la base de datos
$pdo = getDBConnection($db_config);

// Obtener datos del POST
$input = json_decode(file_get_contents('php://input'), true);
$monto = $input['monto'] ?? 0;
$descripcion = $input['descripcion'] ?? 'Pago en caja';

// Convertir a float
$monto = floatval($monto);

// Obtener datos del cliente
$ip_usuario = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// Log para depuración
error_log("=== NUEVA PETICIÓN DE PAGO ===");
error_log("Monto recibido: " . $monto);
error_log("Descripción recibida: " . $descripcion);

// Validar que el monto sea válido
if ($monto <= 0) {
    $response = ['success' => false, 'error' => 'Monto no válido: ' . $monto];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($input),
        'response_data' => json_encode($response),
        'status' => 'error',
        'error_message' => 'Monto no válido: ' . $monto,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response);
    exit();
}

// Verificar rango en pesos
if ($monto < 50 || $monto > 15000) {
    $response = [
        'success' => false, 
        'error' => 'El monto debe estar entre $50.00 y $15,000.00 MXN'
    ];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($input),
        'response_data' => json_encode($response),
        'status' => 'error',
        'error_message' => 'Monto fuera de rango',
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response);
    exit();
}

// Generar ID único
$timestamp = time();
$random = rand(100, 999);
$ultimo_id = intval(substr($timestamp, -6)) . $random;

// Generar ID con formato de 9 dígitos
$id_formateado = str_pad($ultimo_id, 9, '0', STR_PAD_LEFT);
$reference_formateado = str_pad($ultimo_id, 15, '0', STR_PAD_LEFT);

// Configuración de Pagadetodo
$url = "https://pagadetodo.mx/Pagadetodo/Service/GenerarLigaIndi";

// Convertir a centavos
$monto_centavos = intval($monto * 100);

// Fecha de expiración
$fecha_expiracion = date('Y-m-d', strtotime('+1 day'));

$data = [
    "User" => "g4u8Hl60l2",
    "Password" => "43(1q-@0OX",
    "IntegrationID" => "124",
    "BusinessID" => "000060",
    "PaymentTypes" => "41",
    "Id" => $id_formateado,
    "Description" => substr($descripcion, 0, 40),
    "Amount" => $monto_centavos,
    "Reference" => $reference_formateado,
    "ExpirationDate" => $fecha_expiracion
];

// $data = [
//     "User" => "p9E5Vdu5Ya",
//     "Password" => "Ak63MKo#1/",
//     "IntegrationID" => "124",
//     "BusinessID" => "000060",
//     "PaymentTypes" => "401",
//     "Id" => $id_formateado,
//     "Description" => substr($descripcion, 0, 40),
//     "Amount" => $monto_centavos,
//     "Reference" => $reference_formateado,
//     "ExpirationDate" => $fecha_expiracion
// ];

error_log("Fecha de expiración: " . $fecha_expiracion);
error_log("Datos enviados a Pagadetodo: " . json_encode($data));

// Realizar la petición CURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

error_log("Código HTTP: " . $httpCode);
error_log("Respuesta de Pagadetodo: " . $response);

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    error_log("Error CURL: " . $error_msg);
    $response_array = ['success' => false, 'error' => 'Error CURL: ' . $error_msg];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'Error CURL: ' . $error_msg,
        'id_generado' => $id_formateado,
        'reference' => $reference_formateado,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
    curl_close($ch);
    exit();
}

curl_close($ch);

// Decodificar respuesta
$result = json_decode($response, true);

if ($result === null) {
    $response_array = [
        'success' => false, 
        'error' => 'Respuesta no válida del servidor',
        'raw_response' => $response
    ];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'Respuesta no válida del servidor',
        'id_generado' => $id_formateado,
        'reference' => $reference_formateado,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
    exit();
}

// Limpiar espacios en las claves
$clean = [];
foreach ($result as $key => $value) {
    $clean[trim($key)] = $value;
}

// VERIFICAR CORRECTAMENTE EL CÓDIGO DE ÉXITO
// Pagadetodo devuelve "code":"success" cuando todo está bien
if (isset($clean['code']) && $clean['code'] === 'success') {
    error_log("ÉXITO: URL generada: " . $clean['url']);
    
    $response_array = [
        'success' => true,
        'url' => $clean['url'],
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id' => $id_formateado,
        'amount' => $monto,
        'description' => $descripcion
    ];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'success',
        'url_generada' => $clean['url'],
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id_generado' => $id_formateado,
        'http_code' => $httpCode,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
} 
// Verificar si hay error (código numérico)
elseif (isset($clean['code']) && is_numeric($clean['code']) && $clean['code'] != '0') {
    $mensaje_error = $clean['message'] ?? 'Error código ' . $clean['code'];
    error_log("Error de Pagadetodo: " . $mensaje_error);
    
    $response_array = [
        'success' => false,
        'error' => 'Error de Pagadetodo: ' . $mensaje_error,
        'code' => $clean['code'] ?? null,
        'response' => $clean
    ];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => $mensaje_error,
        'id_generado' => $id_formateado,
        'reference' => $reference_formateado,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
}
// Si no hay URL pero tampoco hay error claro
elseif (!isset($clean['url']) || empty($clean['url'])) {
    error_log("No se recibió URL de pago. Respuesta: " . json_encode($clean));
    
    $response_array = [
        'success' => false, 
        'error' => 'No se recibió URL de pago',
        'response' => $clean,
        'http_code' => $httpCode
    ];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'No se recibió URL de pago',
        'id_generado' => $id_formateado,
        'reference' => $reference_formateado,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
}
// Si hay URL pero no estamos seguros del código
elseif (isset($clean['url']) && !empty($clean['url'])) {
    error_log("ÉXITO (por URL): " . $clean['url']);
    
    $response_array = [
        'success' => true,
        'url' => $clean['url'],
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id' => $id_formateado,
        'amount' => $monto,
        'description' => $descripcion
    ];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'success',
        'url_generada' => $clean['url'],
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id_generado' => $id_formateado,
        'http_code' => $httpCode,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
}
else {
    error_log("Caso no contemplado: " . json_encode($clean));
    
    $response_array = [
        'success' => false,
        'error' => 'Respuesta no reconocida',
        'response' => $clean
    ];
    
    // Guardar en BD
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'Respuesta no reconocida',
        'id_generado' => $id_formateado,
        'reference' => $reference_formateado,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
}

error_log("=== FIN DE PETICIÓN ===\n");
?>