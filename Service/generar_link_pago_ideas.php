<?php
// generar_link_pago_ideas.php
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Configuración de errores para debug
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Cargar configuración centralizada
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

// ============ FUNCIÓN PARA LOG EN ARCHIVO ============
function escribirLog($mensaje, $tipo = 'INFO') {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $fecha = date('Y-m-d');
    $archivo = $logDir . "/pagos_ideas_$fecha.log";
    $timestamp = date('Y-m-d H:i:s');
    $linea = "[$timestamp] [$tipo] $mensaje" . PHP_EOL;
    file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
}

// ============ FUNCIÓN PARA RESPONDER JSON ============
function responderJSON($success, $data = [], $error = null) {
    $response = ['success' => $success];
    if ($success) {
        $response = array_merge($response, $data);
    } else {
        $response['error'] = $error ?? 'Error desconocido';
        if (!empty($data)) {
            $response['data'] = $data;
        }
    }
    echo json_encode($response);
    exit();
}

// ============ FUNCIÓN PARA GUARDAR LOG EN BD ============
function guardarLogEnBD($pdo, $datos) {
    if (!$pdo) {
        escribirLog("No hay conexión a BD para guardar log", "ERROR");
        return false;
    }
    
    try {
        // Verificar si la tabla existe
        $sql_check = "SHOW TABLES LIKE 'pagos_generadas'";
        $stmt_check = $pdo->query($sql_check);
        if ($stmt_check->rowCount() == 0) {
            // Crear la tabla si no existe
            $sql_create = "CREATE TABLE IF NOT EXISTS pagos_generadas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fecha DATETIME DEFAULT NOW(),
                empresa_id INT NULL,
                monto DECIMAL(10,2),
                descripcion TEXT,
                tipo_servicio VARCHAR(50) NULL,
                plan_seleccionado VARCHAR(50) NULL,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec($sql_create);
            escribirLog("Tabla pagos_generadas creada", "INFO");
        }
        
        // Verificar que las columnas existen
        $columnas_requeridas = ['empresa_id', 'tipo_servicio', 'plan_seleccionado'];
        foreach ($columnas_requeridas as $columna) {
            $sql_check_col = "SHOW COLUMNS FROM pagos_generadas LIKE '$columna'";
            $stmt_check_col = $pdo->prepare($sql_check_col);
            $stmt_check_col->execute();
            if (!$stmt_check_col->fetch(PDO::FETCH_ASSOC)) {
                $tipo = $columna == 'empresa_id' ? 'int(11) NULL' : 'varchar(50) NULL';
                $pdo->exec("ALTER TABLE pagos_generadas ADD COLUMN $columna $tipo AFTER id");
                escribirLog("Columna $columna agregada a pagos_generadas", "INFO");
            }
        }
        
        // Construir la consulta SQL
        $sql = "INSERT INTO pagos_generadas (
                    fecha, 
                    empresa_id, 
                    monto, 
                    descripcion, 
                    tipo_servicio, 
                    plan_seleccionado,
                    request_data, 
                    response_data, 
                    status, 
                    url_generada, 
                    reference, 
                    id_generado, 
                    http_code, 
                    error_message, 
                    ip_usuario, 
                    user_agent
                ) VALUES (
                    NOW(), 
                    :empresa_id, 
                    :monto, 
                    :descripcion, 
                    :tipo_servicio, 
                    :plan_seleccionado,
                    :request_data, 
                    :response_data, 
                    :status, 
                    :url_generada, 
                    :reference, 
                    :id_generado, 
                    :http_code, 
                    :error_message, 
                    :ip_usuario, 
                    :user_agent
                )";
        
        $stmt = $pdo->prepare($sql);
        
        // Preparar los datos
        $empresa_id = isset($datos['empresa_id']) ? intval($datos['empresa_id']) : null;
        $tipo_servicio = isset($datos['tipo_servicio']) ? trim($datos['tipo_servicio']) : 'Pago Caja';
        $plan_seleccionado = isset($datos['plan_seleccionado']) ? trim($datos['plan_seleccionado']) : null;
        
        $stmt->execute([
            ':empresa_id' => $empresa_id,
            ':monto' => isset($datos['monto']) ? floatval($datos['monto']) : null,
            ':descripcion' => isset($datos['descripcion']) ? trim($datos['descripcion']) : null,
            ':tipo_servicio' => $tipo_servicio,
            ':plan_seleccionado' => $plan_seleccionado,
            ':request_data' => isset($datos['request_data']) ? trim($datos['request_data']) : null,
            ':response_data' => isset($datos['response_data']) ? trim($datos['response_data']) : null,
            ':status' => isset($datos['status']) ? trim($datos['status']) : null,
            ':url_generada' => isset($datos['url_generada']) ? trim($datos['url_generada']) : null,
            ':reference' => isset($datos['reference']) ? trim($datos['reference']) : null,
            ':id_generado' => isset($datos['id_generado']) ? trim($datos['id_generado']) : null,
            ':http_code' => isset($datos['http_code']) ? intval($datos['http_code']) : null,
            ':error_message' => isset($datos['error_message']) ? trim($datos['error_message']) : null,
            ':ip_usuario' => isset($datos['ip_usuario']) ? trim($datos['ip_usuario']) : null,
            ':user_agent' => isset($datos['user_agent']) ? trim($datos['user_agent']) : null
        ]);
        
        $id_insertado = $pdo->lastInsertId();
        escribirLog("Log guardado con ID: $id_insertado", "INFO");
        return $id_insertado;
        
    } catch (PDOException $e) {
        escribirLog("Error al guardar log en BD: " . $e->getMessage(), "ERROR");
        return false;
    }
}

// ============ CONEXIÓN A LA BASE DE DATOS ============
try {
    $pdo = getDBConnection();
    escribirLog("Conexión a BD establecida", "INFO");
} catch (Exception $e) {
    escribirLog("Error de conexión a BD: " . $e->getMessage(), "ERROR");
    responderJSON(false, [], 'Error de conexión a la base de datos');
}

// Verificar que la sesión tenga la empresa
if (empty($_SESSION['empresa_db'])) {
    escribirLog("No se ha seleccionado una empresa", "ERROR");
    responderJSON(false, [], 'No se ha seleccionado una empresa');
}

// ============ OBTENER DATOS DEL POST ============
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

$monto = isset($input['monto']) ? floatval($input['monto']) : 0;
$descripcion = isset($input['descripcion']) ? trim($input['descripcion']) : 'Pago en caja';
$plan_seleccionado = isset($input['plan_seleccionado']) ? trim($input['plan_seleccionado']) : null;
$tipo_servicio = 'Pago Caja';

// ============ OBTENER DATOS DE LA SESIÓN ============
$empresa_id = isset($_SESSION['empresa_id']) ? intval($_SESSION['empresa_id']) : null;
$empresa_db = $_SESSION['empresa_db'] ?? null;

escribirLog("=== NUEVA PETICIÓN DE PAGO IDEA ===", "INFO");
escribirLog("Monto: $monto, Descripción: $descripcion, Empresa ID: $empresa_id, DB: $empresa_db", "INFO");

// ============ VALIDACIONES ============
if ($monto <= 0) {
    $response = ['success' => false, 'error' => 'Monto no válido: ' . $monto];
    guardarLogEnBD($pdo, [
        'empresa_id' => $empresa_id,
        'monto' => $monto,
        'descripcion' => $descripcion,
        'tipo_servicio' => $tipo_servicio,
        'plan_seleccionado' => $plan_seleccionado,
        'request_data' => json_encode($input),
        'response_data' => json_encode($response),
        'status' => 'error',
        'error_message' => 'Monto no válido: ' . $monto,
        'ip_usuario' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    responderJSON(false, [], 'Monto no válido: ' . $monto);
}

if ($monto < 50 || $monto > 15000) {
    $response = ['success' => false, 'error' => 'El monto debe estar entre $50.00 y $15,000.00 MXN'];
    guardarLogEnBD($pdo, [
        'empresa_id' => $empresa_id,
        'monto' => $monto,
        'descripcion' => $descripcion,
        'tipo_servicio' => $tipo_servicio,
        'plan_seleccionado' => $plan_seleccionado,
        'request_data' => json_encode($input),
        'response_data' => json_encode($response),
        'status' => 'error',
        'error_message' => 'Monto fuera de rango',
        'ip_usuario' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    responderJSON(false, [], 'El monto debe estar entre $50.00 y $15,000.00 MXN');
}

// ============ OBTENER DATOS DE LA EMPRESA ============
$empresa_data = [];
if ($pdo) {
    try {
        $sql_empresa = "SELECT nombre, rfc, direccion, telefono, email, color_primario, color_secundario, logo, moneda 
                        FROM sistema_config LIMIT 1";
        $stmt_empresa = $pdo->query($sql_empresa);
        $empresa_data = $stmt_empresa->fetch();
        
        if (!$empresa_data) {
            $empresa_data = [
                'nombre' => $_SESSION['empresa_nombre'] ?? 'Mi Empresa',
                'rfc' => $_SESSION['empresa_rfc'] ?? '',
                'direccion' => $_SESSION['empresa_direccion'] ?? '',
                'telefono' => $_SESSION['empresa_telefono'] ?? '',
                'email' => $_SESSION['empresa_email'] ?? '',
                'moneda' => 'MXN'
            ];
        }
        escribirLog("Datos de empresa obtenidos: " . $empresa_data['nombre'], "INFO");
    } catch (PDOException $e) {
        escribirLog("Error al obtener datos de la empresa: " . $e->getMessage(), "ERROR");
        $empresa_data = [
            'nombre' => $_SESSION['empresa_nombre'] ?? 'Mi Empresa',
            'moneda' => 'MXN'
        ];
    }
}

// ============ GENERAR IDS ============
$timestamp = time();
$random = rand(100, 999);
$ultimo_id = intval(substr($timestamp, -6)) . $random;
$id_formateado = str_pad($ultimo_id, 9, '0', STR_PAD_LEFT);
$reference_formateado = str_pad($ultimo_id, 15, '0', STR_PAD_LEFT);
$monto_centavos = intval($monto * 100);
$fecha_expiracion = date('Y-m-d', strtotime('+1 day'));

// ============ CONFIGURACIÓN DE PAGADETODO ============
$speiConfig = speiConfig();
$url = $speiConfig['url_generar_dom'] ?? 'https://pagadetodo.mx/Pagadetodo/Service/GenerarLigaIndi';
$user = $speiConfig['user'] ?? 'p9E5Vdu5Ya';
$password = $speiConfig['password'] ?? 'Ak63MKo#1/';
$integration_id = $speiConfig['integration_id'] ?? '124';
$business_id = $speiConfig['business_id'] ?? '000060';

// ============ CONSTRUIR DATOS PARA PAGADETODO ============
$data = [
    "User" => $user,
    "Password" => $password,
    "IntegrationID" => $integration_id,
    "BusinessID" => $business_id,
    "PaymentTypes" => "41",
    "Id" => $id_formateado,
    "Description" => substr($descripcion, 0, 40),
    "Amount" => (string)$monto_centavos,
    "Reference" => $reference_formateado,
    "ExpirationDate" => $fecha_expiracion
];

escribirLog("Datos enviados a Pagadetodo: " . json_encode($data), "DEBUG");

// ============ REALIZAR PETICIÓN CURL ============
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

escribirLog("Código HTTP: $httpCode", "INFO");
escribirLog("Respuesta de Pagadetodo: " . $response, "DEBUG");

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    escribirLog("Error CURL: $error_msg", "ERROR");
    guardarLogEnBD($pdo, [
        'empresa_id' => $empresa_id,
        'monto' => $monto,
        'descripcion' => $descripcion,
        'tipo_servicio' => $tipo_servicio,
        'plan_seleccionado' => $plan_seleccionado,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'Error CURL: ' . $error_msg,
        'id_generado' => $id_formateado,
        'reference' => $reference_formateado,
        'ip_usuario' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    curl_close($ch);
    responderJSON(false, [], 'Error CURL: ' . $error_msg);
}

curl_close($ch);

// ============ PROCESAR RESPUESTA ============
$result = json_decode($response, true);

if ($result === null) {
    escribirLog("Respuesta no válida del servidor: " . $response, "ERROR");
    guardarLogEnBD($pdo, [
        'empresa_id' => $empresa_id,
        'monto' => $monto,
        'descripcion' => $descripcion,
        'tipo_servicio' => $tipo_servicio,
        'plan_seleccionado' => $plan_seleccionado,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status' => 'error',
        'http_code' => $httpCode,
        'error_message' => 'Respuesta no válida del servidor',
        'id_generado' => $id_formateado,
        'reference' => $reference_formateado,
        'ip_usuario' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    responderJSON(false, [], 'Respuesta no válida del servidor');
}

$clean = [];
foreach ($result as $key => $value) {
    $clean[trim($key)] = $value;
}

// ============ CASO 1: ÉXITO ============
if (isset($clean['url']) && !empty($clean['url'])) {
    escribirLog("ÉXITO: URL generada: " . $clean['url'], "INFO");
    
    $response_data = [
        'url' => $clean['url'],
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id' => $id_formateado,
        'amount' => $monto,
        'description' => $descripcion,
        'empresa' => $empresa_data['nombre'] ?? 'Mi Empresa'
    ];
    
    guardarLogEnBD($pdo, [
        'empresa_id' => $empresa_id,
        'monto' => $monto,
        'descripcion' => $descripcion,
        'tipo_servicio' => $tipo_servicio,
        'plan_seleccionado' => $plan_seleccionado,
        'request_data' => json_encode($data),
        'response_data' => json_encode($clean),
        'status' => 'success',
        'url_generada' => $clean['url'],
        'reference' => $clean['reference'] ?? $reference_formateado,
        'id_generado' => $id_formateado,
        'http_code' => $httpCode,
        'ip_usuario' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    responderJSON(true, $response_data);
}

// ============ CASO 2: ERROR ============
$mensaje_error = $clean['Message'] ?? $clean['message'] ?? $clean['error'] ?? 'Error desconocido al generar el pago';
escribirLog("ERROR: $mensaje_error", "ERROR");

guardarLogEnBD($pdo, [
    'empresa_id' => $empresa_id,
    'monto' => $monto,
    'descripcion' => $descripcion,
    'tipo_servicio' => $tipo_servicio,
    'plan_seleccionado' => $plan_seleccionado,
    'request_data' => json_encode($data),
    'response_data' => json_encode($clean),
    'status' => 'error',
    'http_code' => $httpCode,
    'error_message' => $mensaje_error,
    'id_generado' => $id_formateado,
    'reference' => $reference_formateado,
    'ip_usuario' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
]);

responderJSON(false, ['response' => $clean], $mensaje_error);