<?php
session_start();
header('Content-Type: application/json');

// Configuración de la base de datos
$db_config = [
    'host' => 'libertyfin.com.mx',
    'user' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'database' => $_SESSION['empresa_db'] ?? 'juanc141_ventas'  // Usar la DB de la empresa
];

// Verificar que la sesión tenga la empresa
if (empty($_SESSION['empresa_db'])) {
    echo json_encode([
        'success' => false, 
        'error' => 'No se ha seleccionado una empresa'
    ]);
    exit();
}

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

// Función para guardar log en BD (usando la empresa actual)
function guardarLogEnBD($pdo, $datos) {
    if (!$pdo) return false;
    
    try {
        // Verificar si la tabla existe en la empresa
        $sql_check = "SHOW TABLES LIKE 'pagos_generadas'";
        $stmt_check = $pdo->query($sql_check);
        if ($stmt_check->rowCount() == 0) {
            // Crear la tabla si no existe
            $sql_create = "CREATE TABLE IF NOT EXISTS pagos_generadas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fecha DATETIME DEFAULT NOW(),
                monto DECIMAL(10,2),
                descripcion TEXT,
                request_data TEXT,
                response_data TEXT,
                status VARCHAR(20),
                url_generada TEXT,
                reference VARCHAR(50),
                id_generado VARCHAR(20),
                http_code INT,
                error_message TEXT,
                ip_usuario VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $pdo->exec($sql_create);
        }
        
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

// Obtener datos del POST (JSON o FormData)
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($content_type, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        // Si viene como FormData
        $input['monto'] = $_POST['monto'] ?? 0;
        $input['descripcion'] = $_POST['descripcion'] ?? 'Pago en caja';
    }
}

$monto = floatval($input['monto'] ?? 0);
$descripcion = trim($input['descripcion'] ?? 'Pago en caja');

// Obtener datos del cliente
$ip_usuario = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

error_log("=== NUEVA PETICIÓN DE PAGO ===");
error_log("Monto recibido: " . $monto);
error_log("Descripción recibida: " . $descripcion);
error_log("Empresa DB: " . $_SESSION['empresa_db']);

// Validar que el monto sea válido
if ($monto <= 0) {
    $response = ['success' => false, 'error' => 'Monto no válido: ' . $monto];
    
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

// OBTENER DATOS DE LA EMPRESA DESDE LA BASE DE DATOS
$empresa_data = [];
if ($pdo) {
    try {
        // Obtener configuración de la empresa
        $sql_empresa = "SELECT nombre, rfc, direccion, telefono, email, color_primario, color_secundario, logo, moneda 
                        FROM sistema_config LIMIT 1";
        $stmt_empresa = $pdo->query($sql_empresa);
        $empresa_data = $stmt_empresa->fetch();
        
        if (!$empresa_data) {
            // Si no hay configuración, usar datos de la sesión
            $empresa_data = [
                'nombre' => $_SESSION['empresa_nombre'] ?? 'Mi Empresa',
                'rfc' => $_SESSION['empresa_rfc'] ?? '',
                'direccion' => $_SESSION['empresa_direccion'] ?? '',
                'telefono' => $_SESSION['empresa_telefono'] ?? '',
                'email' => $_SESSION['empresa_email'] ?? '',
                'moneda' => 'MXN'
            ];
        }
    } catch (PDOException $e) {
        error_log("Error al obtener datos de la empresa: " . $e->getMessage());
        $empresa_data = [
            'nombre' => $_SESSION['empresa_nombre'] ?? 'Mi Empresa',
            'moneda' => 'MXN'
        ];
    }
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

// Fecha de expiración (1 día)
$fecha_expiracion = date('Y-m-d', strtotime('+1 day'));

// OBTENER CREDENCIALES DE LA EMPRESA (desde la tabla empresas en la BD principal)
$credenciales_pago = obtenerCredencialesPago($empresa_data['rfc'] ?? '');

// Construir el array de datos para Pagadetodo
$data = [
    "User" => $credenciales_pago['user'] ?? "g4u8Hl60l2",
    "Password" => $credenciales_pago['password'] ?? "43(1q-@0OX",
    "IntegrationID" => "124",
    "BusinessID" => "000060",
    "PaymentTypes" => "41",
    "Id" => $id_formateado,
    "Description" => substr($descripcion, 0, 40),
    "Amount" => $monto_centavos,
    "Reference" => $reference_formateado,
    "ExpirationDate" => $fecha_expiracion
];

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

// VERIFICAR CÓDIGO DE ÉXITO
if (isset($clean['code']) && $clean['code'] === 'success') {
    error_log("ÉXITO: URL generada: " . ($clean['url'] ?? 'No URL'));
    
    $response_array = [
        'success' => true,
        'url' => $clean['url'] ?? '',
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id' => $id_formateado,
        'amount' => $monto,
        'description' => $descripcion,
        'empresa' => $empresa_data['nombre'] ?? 'Mi Empresa'
    ];
    
    guardarLogEnBD($pdo, [
        'monto' => $monto,
        'descripcion' => $descripcion,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'success',
        'url_generada' => $clean['url'] ?? '',
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id_generado' => $id_formateado,
        'http_code' => $httpCode,
        'ip_usuario' => $ip_usuario,
        'user_agent' => $user_agent
    ]);
    
    echo json_encode($response_array);
} 
elseif (isset($clean['url']) && !empty($clean['url'])) {
    // Si tiene URL pero no code success, asumimos éxito
    error_log("ÉXITO (por URL): " . $clean['url']);
    
    $response_array = [
        'success' => true,
        'url' => $clean['url'],
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id' => $id_formateado,
        'amount' => $monto,
        'description' => $descripcion,
        'empresa' => $empresa_data['nombre'] ?? 'Mi Empresa'
    ];
    
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
    $mensaje_error = $clean['message'] ?? 'Error desconocido';
    error_log("Error: " . $mensaje_error);
    
    $response_array = [
        'success' => false,
        'error' => 'Error al generar pago: ' . $mensaje_error,
        'code' => $clean['code'] ?? null,
        'response' => $clean
    ];
    
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

error_log("=== FIN DE PETICIÓN ===\n");

// ========== FUNCIÓN PARA OBTENER CREDENCIALES DE PAGO ==========
function obtenerCredencialesPago($rfc_empresa) {
    // Por defecto, usar credenciales de Liberty Finanzas
    $credenciales = [
        'user' => 'g4u8Hl60l2',
        'password' => '43(1q-@0OX'
    ];
    
    // Si necesitas credenciales específicas por empresa, puedes configurarlas aquí
    // Por ejemplo, cargar desde la base de datos principal
    
    return $credenciales;
}
?>