<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // Cargar configuración
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../config/database.php';
    
    $speiConfig = speiConfig();
    $pdo = getDBConnection();
    
    // Leer entrada
    $inputRaw = file_get_contents('php://input');
    
    if (empty($inputRaw)) {
        throw new Exception('No se recibieron datos');
    }
    
    $data = json_decode($inputRaw, true);
    
    if ($data === null) {
        throw new Exception('JSON inválido');
    }
    
    // Función para generar Account único
    function generarAccountUnico() {
        $microtime = microtime(true);
        $timestamp = (int) ($microtime * 1000000);
        $random = rand(10000, 99999);
        $account = substr($timestamp . $random, 0, 15);
        if (strlen($account) < 15) {
            $account = str_pad($account, 15, '0');
        }
        return $account;
    }
    
    // Obtener datos del frontend
    $account = $data['Account'] ?? generarAccountUnico();
    $clienteEmail = $data['CustomerEmail'] ?? 'cliente@libertyfin.com.mx';
    $clienteNombre = $data['CustomerName'] ?? 'Cliente Libertyfin';
    $descripcion = $data['Description'] ?? 'Pago Libertyfin';
    $montoTotal = isset($data['MontoTotal']) ? (float) $data['MontoTotal'] : 0;
    $plan = $data['plan'] ?? null;
$plazo = $data['plazo'] ?? null;
$tipoServicio = $data['tipo_servicio'] ?? 'Suscripcion';
    
    // ========== OBTENER EMPRESA_ID ==========
    // Opción 1: Desde la sesión (si la API es llamada desde el sistema)
    $empresaId = null;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Prioridad: 1. Desde el payload, 2. Desde sesión, 3. Desde usuario
    if (isset($data['empresa_id']) && !empty($data['empresa_id'])) {
        $empresaId = (int) $data['empresa_id'];
        error_log("📌 empresa_id desde payload: " . $empresaId);
    } elseif (isset($_SESSION['empresa_id']) && !empty($_SESSION['empresa_id'])) {
        $empresaId = (int) $_SESSION['empresa_id'];
        error_log("📌 empresa_id desde sesión: " . $empresaId);
    } else {
        // Intentar obtener desde la base de datos usando el email del cliente
        try {
            $sql_empresa = "SELECT id FROM empresas WHERE email_contacto = ? OR id = (SELECT empresa_id FROM usuarios WHERE email = ? LIMIT 1) LIMIT 1";
            $stmt_empresa = $pdo->prepare($sql_empresa);
            $stmt_empresa->execute([$clienteEmail, $clienteEmail]);
            $empresa = $stmt_empresa->fetch();
            if ($empresa) {
                $empresaId = (int) $empresa['id'];
                error_log("📌 empresa_id obtenido desde BD: " . $empresaId);
            }
        } catch (PDOException $e) {
            error_log("⚠️ No se pudo obtener empresa_id desde BD: " . $e->getMessage());
        }
    }
    
    // Si no se pudo obtener empresa_id, usar un valor por defecto
    if (empty($empresaId)) {
        $empresaId = 1; // Empresa por defecto
        error_log("⚠️ Usando empresa_id por defecto: " . $empresaId);
    }
    
    // Si no se pasó MontoTotal pero se pasó 'monto' en el payload, usarlo
    if ($montoTotal <= 0 && isset($data['monto'])) {
        $montoTotal = (float) $data['monto'];
    }
    
    // Si aún no hay monto, intentar obtenerlo de la descripción
    if ($montoTotal <= 0 && preg_match('/Monto: \$([\d,.]+)/', $descripcion, $matches)) {
        $montoTotal = (float) str_replace(',', '', $matches[1]);
    }
    
    $montoTotalCentavos = (int) round($montoTotal);
    $productos = $data['Productos'] ?? null;
    
    // IMPORTANTE: Usar la descripción del frontend o generar una con el monto
    $descripcionFinal = $data['Description'] ?? "Pago Libertyfin - Monto: $" . number_format($montoTotal, 2);
    
    $payload = [
        'User' => $speiConfig['user_sanbox'],
        'Password' => $speiConfig['password_sanbox'],
        'IntegrationID' => $speiConfig['integration_id'],
        'BusinessID' => $speiConfig['business_id'],
        'Description' => $descripcionFinal,
        'Account' => $account,
        'CustomerEmail' => $clienteEmail,
        'CustomerName' => $clienteNombre,
        'ExpirationDate' => date('Y-m-d', strtotime('+1 day'))
    ];
    
    // Si hay monto, agregarlo al payload
    if ($montoTotal > 0) {
        $payload['MontoTotal'] = $montoTotal;
    }
    
    error_log("📦 SPEI Payload - Account: " . $account . " - Monto: " . $montoTotal . " - EmpresaID: " . $empresaId);
    
    // Llamar a la API
    $ch = curl_init($speiConfig['url_generar']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('Error de conexión: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        throw new Exception('Error HTTP ' . $httpCode . ': ' . $response);
    }
    
    $apiResponse = json_decode($response, true);
    
    if (!$apiResponse || !isset($apiResponse['Clabe']) || empty($apiResponse['Clabe'])) {
        $errorMsg = $apiResponse['Error'] ?? $apiResponse['Message'] ?? 'No se recibió CLABE válida';
        throw new Exception('Error de Cobroscontarjeta: ' . $errorMsg);
    }
    
    // INICIAR TRANSACCIÓN
    $pdo->beginTransaction();
    
    // Verificar si la columna empresa_id existe
    try {
        $stmt_check = $pdo->query("SHOW COLUMNS FROM clabes_spei LIKE 'empresa_id'");
        $col_exists = $stmt_check->rowCount() > 0;
    } catch (PDOException $e) {
        // Si la tabla no tiene la columna, intentar agregarla
        try {
            $pdo->exec("ALTER TABLE clabes_spei ADD COLUMN empresa_id INT(11) DEFAULT NULL AFTER id, ADD KEY idx_empresa_id (empresa_id)");
            error_log("✅ Columna empresa_id agregada a clabes_spei");
            $col_exists = true;
        } catch (PDOException $alterError) {
            error_log("⚠️ No se pudo agregar columna empresa_id: " . $alterError->getMessage());
            $col_exists = false;
        }
    }

    // Asegurar columnas de facturación (auto-creación, mismo patrón que empresa_id arriba)
    $columnasFacturacion = [
        'requiere_factura' => "ALTER TABLE clabes_spei ADD COLUMN requiere_factura TINYINT(1) DEFAULT 0",
        'razon_social'     => "ALTER TABLE clabes_spei ADD COLUMN razon_social VARCHAR(255) DEFAULT NULL",
        'rfc'              => "ALTER TABLE clabes_spei ADD COLUMN rfc VARCHAR(20) DEFAULT NULL",
        'email_factura'    => "ALTER TABLE clabes_spei ADD COLUMN email_factura VARCHAR(150) DEFAULT NULL",
        'regimen_fiscal'   => "ALTER TABLE clabes_spei ADD COLUMN regimen_fiscal VARCHAR(10) DEFAULT NULL",
        'cp_factura'       => "ALTER TABLE clabes_spei ADD COLUMN cp_factura VARCHAR(10) DEFAULT NULL",
        'metodo_pago_sat'  => "ALTER TABLE clabes_spei ADD COLUMN metodo_pago_sat VARCHAR(10) DEFAULT NULL",
        'uso_cfdi'         => "ALTER TABLE clabes_spei ADD COLUMN uso_cfdi VARCHAR(10) DEFAULT NULL",
    ];
    foreach ($columnasFacturacion as $col => $sqlAlter) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM clabes_spei LIKE " . $pdo->quote($col));
            if ($chk->rowCount() === 0) {
                $pdo->exec($sqlAlter);
            }
        } catch (PDOException $e) {
            error_log("No se pudo verificar/crear columna $col en clabes_spei: " . $e->getMessage());
        }
    }

    $requiereFactura = !empty($data['requiere_factura']) ? 1 : 0;
    $facturacion = [
        'razon_social'    => $data['razon_social'] ?? null,
        'rfc'             => $data['rfc'] ?? null,
        'email_factura'   => $data['email_factura'] ?? null,
        'regimen_fiscal'  => $data['regimen_fiscal'] ?? null,
        'cp_factura'      => $data['cp'] ?? null,
        'metodo_pago_sat' => $data['metodo_pago_sat'] ?? null,
        'uso_cfdi'        => $data['uso_cfdi'] ?? null,
    ];
    
    // Expirar CLABEs anteriores del mismo cliente
    $stmt = $pdo->prepare("
        UPDATE clabes_spei 
        SET estado = 'expirada' 
        WHERE cliente_email = ? AND estado = 'vigente'
    ");
    $stmt->execute([$clienteEmail]);
    
    // Construir la consulta INSERT con o sin empresa_id
// Construir la consulta INSERT con o sin empresa_id
if ($col_exists) {
    $sql = "
        INSERT INTO clabes_spei (
            empresa_id, account, clabe, cliente_email, cliente_nombre, descripcion,
            monto_total, monto_pendiente, fecha_expiracion, estado, 
            folio, productos_json,
            requiere_factura, razon_social, rfc, email_factura, regimen_fiscal, cp_factura, metodo_pago_sat, uso_cfdi,
            facturar, factura_razon_social, factura_rfc, factura_email, factura_regimen_fiscal, factura_cp, factura_metodo_pago, factura_uso_cfdi
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'vigente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $params = [
        $empresaId,
        $account,
        $apiResponse['Clabe'],
        $clienteEmail,
        $clienteNombre,
        $descripcionFinal,
        $montoTotalCentavos,
        $montoTotalCentavos,
        date('Y-m-d H:i:s', strtotime('+1 day')),
        $apiResponse['Folio'] ?? null,
        $productos ? json_encode($productos, JSON_UNESCAPED_UNICODE) : null,
        $requiereFactura,
        $facturacion['razon_social'],
        $facturacion['rfc'],
        $facturacion['email_factura'],
        $facturacion['regimen_fiscal'],
        $facturacion['cp_factura'],
        $facturacion['metodo_pago_sat'],
        $facturacion['uso_cfdi'],
        // Duplicado en las columnas "factura_*" legacy para que quien las lea
        // también vea el dato correcto
        $requiereFactura ? 'si' : 'no',
        $facturacion['razon_social'],
        $facturacion['rfc'],
        $facturacion['email_factura'],
        $facturacion['regimen_fiscal'],
        $facturacion['cp_factura'],
        $facturacion['metodo_pago_sat'],
        $facturacion['uso_cfdi'],
    ];
} else {
        $sql = "
            INSERT INTO clabes_spei (
                account, clabe, cliente_email, cliente_nombre, descripcion,
                monto_total, monto_pendiente, fecha_expiracion, estado, 
                folio, productos_json,
                requiere_factura, razon_social, rfc, email_factura, regimen_fiscal, cp_factura, metodo_pago_sat, uso_cfdi
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'vigente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $params = [
            $account,
            $apiResponse['Clabe'],
            $clienteEmail,
            $clienteNombre,
            $descripcionFinal,
            $montoTotalCentavos,
            $montoTotalCentavos,
            date('Y-m-d H:i:s', strtotime('+1 day')),
            $apiResponse['Folio'] ?? null,
            $productos ? json_encode($productos, JSON_UNESCAPED_UNICODE) : null,
            $requiereFactura,
            $facturacion['razon_social'],
            $facturacion['rfc'],
            $facturacion['email_factura'],
            $facturacion['regimen_fiscal'],
            $facturacion['cp_factura'],
            $facturacion['metodo_pago_sat'],
            $facturacion['uso_cfdi'],
        ];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $id = $pdo->lastInsertId();
    $pdo->commit();
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'clabe' => $apiResponse['Clabe'],
        'account' => $account,
        'message' => $apiResponse['Message'] ?? 'Exitosa',
        'folio' => $apiResponse['Folio'] ?? null,
        'id' => $id,
        'fecha_expiracion' => date('Y-m-d H:i:s', strtotime('+1 day')),
        'reutilizada' => false,
        'nueva' => true,
        'monto_total' => $montoTotal,
        'monto_total_centavos' => $montoTotalCentavos,
        'descripcion' => $descripcionFinal,
        'empresa_id' => $empresaId
    ]);
    
} catch (Exception $e) {
    // Rollback en caso de error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("❌ Error en generar_clabe: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}