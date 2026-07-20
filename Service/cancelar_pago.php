<?php
// Service/cancela_pago.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE');

error_reporting(E_ALL);
ini_set('display_errors', 0); // En producción NUNCA mostrar errores al cliente
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Capturar todos los errores fatales
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json');
        echo json_encode([
            'codigo'  => 50,
            'mensaje' => 'Error de sistema'
        ]);
        error_log("ERROR FATAL: " . $error['message'] . " en " . $error['file'] . " línea " . $error['line']);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Función para registrar logs de depuración (no interfiere con el negocio)
function debugLog($mensaje, $datos = null)
{
    try {
        $logFile = __DIR__ . '/../logs/debug_cancelacion.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $contenido = date('Y-m-d H:i:s') . " - " . $mensaje;
        if ($datos !== null) {
            $contenido .= " - " . json_encode($datos, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($logFile, $contenido . PHP_EOL, FILE_APPEND);
    } catch (\Throwable $e) {
        // Ignorar errores de log, nunca deben romper el flujo
    }
}

try {
    $configPath   = __DIR__ . '/../config.php';
    $databasePath = __DIR__ . '/../config/database.php';

    if (!file_exists($configPath)) {
        throw new Exception('config.php no encontrado en: ' . $configPath);
    }
    if (!file_exists($databasePath)) {
        throw new Exception('database.php no encontrado en: ' . $databasePath);
    }

    require_once $configPath;
    require_once $databasePath;

    if (!function_exists('getDBConnection')) {
        throw new Exception('Función getDBConnection no definida');
    }

    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('Error al conectar a la base de datos');
    }

    // ============================================================
    // LEER Y VALIDAR INPUT
    // ============================================================
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('No se recibieron datos');
    }

    $input = json_decode($rawInput, true);
    if ($input === null) {
        throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
    }

    // Según manual: si la clabe tiene formato inválido, código 40 (Adquiriente inválido / Operación no encontrada)
    if (!isset($input['clabe']) || empty(trim($input['clabe']))) {
        $response = ['codigo' => 40, 'mensaje' => 'Operacion SPEI no encontrada'];
        echo json_encode($response);
        logSpeiTransaction($pdo, 'cancelacion', null, null, $input, $response, 40);
        exit;
    }

    $clabe        = trim($input['clabe']);
    $transaccion  = isset($input['transaccion']) ? trim($input['transaccion']) : '';
    $autorizacion = isset($input['autorizacion']) ? trim($input['autorizacion']) : '';
    $referencia   = isset($input['referencia']) ? trim($input['referencia']) : '';
    $fecha        = isset($input['fecha']) ? trim($input['fecha']) : '';
    $monto        = isset($input['monto']) ? (float) $input['monto'] : 0;

    debugLog("Datos procesados", compact('clabe', 'transaccion', 'autorizacion', 'referencia', 'fecha', 'monto'));

    if (!preg_match('/^\d{18}$/', $clabe)) {
        $response = ['codigo' => 40, 'mensaje' => 'Operacion SPEI no encontrada'];
        echo json_encode($response);
        logSpeiTransaction($pdo, 'cancelacion', $clabe, null, $input, $response, 40);
        exit;
    }

    // ============================================================
    // BUSCAR CLABE
    // ============================================================
    $stmt = $pdo->prepare("SELECT id, account, estado, fecha_pago, monto_pendiente FROM clabes_spei WHERE clabe = ?");
    $stmt->execute([$clabe]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        $response = ['codigo' => 40, 'mensaje' => 'Operacion SPEI no encontrada'];
        logSpeiTransaction($pdo, 'cancelacion', $clabe, null, $input, $response, 40);
        echo json_encode($response);
        exit;
    }

    $clabeId = (int) $registro['id'];
    $account = $registro['account'];
    debugLog("CLABE encontrada", ['id' => $clabeId, 'account' => $account]);

    // ============================================================
    // BUSCAR EL PAGO ESPECÍFICO (confirmado O ya cancelado)
    //
    // IMPORTANTE: todos los identificadores que vengan en la petición
    // (transaccion, autorizacion, referencia) deben coincidir con el
    // MISMO registro. No se busca "por separado" campo por campo,
    // porque eso permite cancelar un pago distinto si, por ejemplo,
    // la autorización coincide pero la transacción es incorrecta.
    //
    // Si no se manda ninguno de los 3 identificadores, se rechaza:
    // no hay forma segura de saber qué pago cancelar.
    // ============================================================
    if (empty($transaccion) && empty($autorizacion) && empty($referencia)) {
        $response = ['codigo' => 40, 'mensaje' => 'Operacion SPEI no encontrada'];
        logSpeiTransaction($pdo, 'cancelacion', $clabe, $account, $input, $response, 40);
        echo json_encode($response);
        exit;
    }

    $condiciones = [];
    $params      = [$clabeId];

    if (!empty($transaccion)) {
        $condiciones[] = 'transaccion = ?';
        $params[]      = $transaccion;
    }
    if (!empty($autorizacion)) {
        $condiciones[] = 'numero_autorizacion = ?';
        $params[]      = $autorizacion;
    }
    if (!empty($referencia)) {
        $condiciones[] = 'referencia = ?';
        $params[]      = $referencia;
    }
    $condicionSql = implode(' AND ', $condiciones);

    $pago        = null;
    $yaCancelado = false;

    // Buscamos primero un confirmado que haga match EXACTO en todos los campos enviados
    $stmt = $pdo->prepare("
        SELECT id, monto_recibido, fecha_recibido, transaccion, numero_autorizacion, referencia, estado
        FROM pagos_spei_recibidos
        WHERE clabe_id = ? AND {$condicionSql} AND estado = 'confirmado'
        ORDER BY fecha_recibido DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute($params);
    $pago = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pago) {
        debugLog("Pago confirmado encontrado (match exacto)", $pago);
    } else {
        // Si no hay confirmado, revisamos si ese MISMO match ya fue cancelado antes
        // (para responder código 0 por idempotencia, no 40)
        $stmt = $pdo->prepare("
            SELECT id, monto_recibido, fecha_recibido, transaccion, numero_autorizacion, referencia, estado
            FROM pagos_spei_recibidos
            WHERE clabe_id = ? AND {$condicionSql} AND estado = 'cancelado'
            ORDER BY fecha_recibido DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute($params);
        $pagoCancelado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pagoCancelado) {
            $pago        = $pagoCancelado;
            $yaCancelado = true;
            debugLog("Pago ya estaba cancelado previamente (match exacto)", $pagoCancelado);
        }
    }

    // Si ya estaba cancelado, el manual exige responder código 0 igualmente (idempotencia)
    if ($pago && $yaCancelado) {
        $response = ['codigo' => 0, 'mensaje' => 'Cancelación exitosa'];
        logSpeiTransaction($pdo, 'cancelacion', $clabe, $account, $input, $response, 0);
        echo json_encode($response);
        exit;
    }

    if (!$pago) {
        debugLog("No se encontró ningún pago confirmado ni cancelado con los criterios proporcionados");
        $response = ['codigo' => 40, 'mensaje' => 'Operacion SPEI no encontrada'];
        logSpeiTransaction($pdo, 'cancelacion', $clabe, $account, $input, $response, 40);
        echo json_encode($response);
        exit;
    }

    debugLog("Pago seleccionado para cancelación", $pago);

    // ============================================================
    // VERIFICACIÓN DE MONTO (solo si viene monto en la petición)
    // ============================================================
    $montoBD = (float) $pago['monto_recibido'];
    if ($monto > 0 && abs($montoBD - $monto) > 0.01) {
        debugLog("Monto no coincide", ['monto_bd' => $montoBD, 'monto_request' => $monto]);
        $response = ['codigo' => 40, 'mensaje' => 'Operacion SPEI no encontrada'];
        logSpeiTransaction($pdo, 'cancelacion', $clabe, $account, $input, $response, 40);
        echo json_encode($response);
        exit;
    }

    // ============================================================
    // VERIFICACIÓN DE FECHA (mismo día, comparando en zona horaria de México)
    //
    // El campo "fecha" que manda Cobroscontarjeta.com NO se usa para
    // esta validación: puede venir vacío, mal formado, o como fecha
    // sentinela (ej. "0001-01-01T00:00:00"). Lo que realmente importa
    // según el manual es si la cancelación se solicita "dentro del
    // mismo día en que se dio la Autorización de Pago", es decir,
    // HOY (fecha real del servidor) vs. fecha_recibido del pago.
    // ============================================================
    date_default_timezone_set('America/Mexico_City');

    try {
        $hoy           = new DateTime('now', new DateTimeZone('America/Mexico_City'));
        $fechaRecibido = new DateTime($pago['fecha_recibido'], new DateTimeZone('America/Mexico_City'));

        if ($hoy->format('Y-m-d') !== $fechaRecibido->format('Y-m-d')) {
            debugLog("Error: Cancelación fuera del día del pago", [
                'hoy'      => $hoy->format('Y-m-d H:i:s'),
                'recibido' => $fechaRecibido->format('Y-m-d H:i:s'),
            ]);
            $response = ['codigo' => 60, 'mensaje' => 'Cancelación fuera de periodo'];
            logSpeiTransaction($pdo, 'cancelacion', $clabe, $account, $input, $response, 60);
            echo json_encode($response);
            exit;
        }

        debugLog("Validación de fecha exitosa - mismo día", [
            'hoy'      => $hoy->format('Y-m-d H:i:s'),
            'recibido' => $fechaRecibido->format('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        // Esto solo puede fallar si fecha_recibido en la BD está corrupta,
        // ya no depende de lo que mande el cliente en "fecha".
        error_log("Error al parsear fecha_recibido: " . $e->getMessage());
        debugLog("Error al parsear fecha_recibido", $e->getMessage());
        $response = ['codigo' => 50, 'mensaje' => 'Error de sistema'];
        logSpeiTransaction($pdo, 'cancelacion', $clabe, $account, $input, $response, 50);
        echo json_encode($response);
        exit;
    }

    // ============================================================
    // PROCESAR CANCELACIÓN (siempre soft-cancel, jamás DELETE)
    // ============================================================
    try {
        $pdo->beginTransaction();

        // 1. Marcar pago como cancelado (se conserva para auditoría/conciliación bancaria)
        $stmt = $pdo->prepare("
            UPDATE pagos_spei_recibidos
            SET estado = 'cancelado', fecha_confirmacion = fecha_confirmacion
            WHERE id = ?
        ");
        $stmt->execute([$pago['id']]);
        debugLog("Pago marcado como cancelado", ['id' => $pago['id']]);

        // 2. Recalcular el monto pendiente de la CLABE
        $montoACancelar = $monto > 0 ? $monto : $montoBD;
        $nuevoMonto     = (float) ($registro['monto_pendiente'] ?? 0) + $montoACancelar;

        debugLog("Montos calculados", [
            'monto_a_cancelar'        => $montoACancelar,
            'monto_pendiente_actual'  => $registro['monto_pendiente'] ?? 0,
            'nuevo_monto_pendiente'   => $nuevoMonto,
        ]);

        // 3. Reabrir la CLABE para que pueda recibir un nuevo intento de pago
        $stmt = $pdo->prepare("
            UPDATE clabes_spei
            SET estado = 'vigente',
                monto_pendiente = ?,
                numero_autorizacion = NULL,
                fecha_cancelacion = NOW(),
                fecha_pago = NULL
            WHERE id = ?
        ");
        $stmt->execute([$nuevoMonto, $clabeId]);
        debugLog("CLABE actualizada");

        // 4. Registrar el monto cancelado si la columna existe (compatibilidad hacia atrás)
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM pagos_spei_recibidos LIKE 'monto_cancelado'");
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    UPDATE pagos_spei_recibidos
                    SET monto_cancelado = ?, fecha_cancelacion = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$montoACancelar, $pago['id']]);
                debugLog("Monto cancelado registrado");
            }
        } catch (\Throwable $e) {
            debugLog("Columna monto_cancelado no existe, continuando...");
        }

        $pdo->commit();

        $response = ['codigo' => 0, 'mensaje' => 'Cancelación exitosa'];
        debugLog("Cancelación exitosa", $response);
        logSpeiTransaction($pdo, 'cancelacion', $clabe, $account, $input, $response, 0);
        echo json_encode($response);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (\Throwable $e) {
    error_log("ERROR en cancela_pago: " . $e->getMessage());
    error_log("Archivo: " . $e->getFile() . " Línea: " . $e->getLine());
    error_log("Trace: " . $e->getTraceAsString());

    header('Content-Type: application/json');
    // Código y mensaje SIEMPRE dentro del catálogo del manual (nunca "61 - Cancelación Fallida")
    echo json_encode([
        'codigo'  => 50,
        'mensaje' => 'Error de sistema'
    ]);
}