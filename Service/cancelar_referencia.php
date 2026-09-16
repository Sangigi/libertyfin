<?php
/**
 * Service/cancela_pago.php
 * -----------------------------------------------------------------------------
 * Endpoint que Cobroscontarjeta.com (CCT) LLAMA cuando el PDV requiere cancelar
 * un pago (usualmente por error al registrar la transacción).
 *
 * Doc base: IntegracionesReferencias V1.4 — pág. 15-17
 *
 * Flujo:
 *   CCT → DELETE|POST https://tu-dominio.com/Service/cancela_pago.php
 *   Body JSON: { referencia, fecha, monto, transaccion, autorizacion }
 *   Tu sistema → responde JSON { codigo, mensaje }
 *
 * Códigos de respuesta (pág. 17):
 *   0   → Cancelación exitosa (incluso si ya estaba cancelada antes)
 *   60  → Cancelación fuera de periodo (después del mismo día del pago)
 *
 * Nota importante:
 *   - Solo se puede cancelar un único pago por referencia.
 *   - Una vez cancelado, el cliente puede volver a intentar pagar la referencia.
 *   - Si la cancelación ya se hizo antes, también se responde con código 0.
 * -----------------------------------------------------------------------------
 */

// 1) Buffer de salida
ob_start();

// 2) Desactivar display_errors
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

/* =============================================================================
 * HELPERS
 * ========================================================================== */

/**
 * Responde SIEMPRE con los 2 campos requeridos por CCT (pág. 16).
 */
function cct_responder(int $codigo, string $mensaje): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    $payload = [
        'codigo'  => (int)    $codigo,
        'mensaje' => (string) $mensaje,
    ];

    error_log('[CCT CancelaPago] RESPONDIENDO: ' .
              json_encode($payload, JSON_UNESCAPED_UNICODE));

    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ob_end_flush();
    exit;
}

function normalizar_referencia(string $ref): string
{
    return preg_replace('/\D+/', '', $ref) ?? '';
}

/**
 * Extrae solo la fecha (yyyy-MM-dd) de un string ISO o datetime.
 * Devuelve '' si no puede parsear.
 */
function extraer_fecha(string $fecha): string
{
    if ($fecha === '') return '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $fecha, $m)) {
        return $m[1];
    }
    $ts = strtotime($fecha);
    return $ts ? date('Y-m-d', $ts) : '';
}

/* =============================================================================
 * 1) LEER BODY (acepta JSON, form-urlencoded y $_POST)
 * ========================================================================== */
$rawBody = file_get_contents('php://input') ?: '';
$input   = [];

error_log('[CCT CancelaPago] METODO: ' . ($_SERVER['REQUEST_METHOD'] ?? ''));
error_log('[CCT CancelaPago] RAW BODY: ' . $rawBody);

// Intentar JSON
$json = json_decode($rawBody, true);
if (is_array($json)) {
    $input = $json;
} else {
    // Fallback: form-urlencoded
    parse_str($rawBody, $formData);
    if (is_array($formData) && !empty($formData)) {
        $input = $formData;
    }
}

// Considerar también $_POST y $_GET (por si CCT manda los parámetros en la URL)
if (empty($input) && !empty($_POST)) $input = $_POST;
if (empty($input) && !empty($_GET))  $input = $_GET;

error_log('[CCT CancelaPago] INPUT parseado: ' . json_encode($input));

/* =============================================================================
 * 2) VALIDAR CAMPOS REQUERIDOS (pág. 16)
 * ========================================================================== */
$referenciaRaw = $input['referencia']   ?? $input['reference']   ?? '';
$fechaRaw      = $input['fecha']        ?? '';
$montoRaw      = $input['monto']        ?? '';
$transaccion   = (string) ($input['transaccion']  ?? '');
$autorizacion  = (string) ($input['autorizacion'] ?? '');

if ($referenciaRaw === '') {
    cct_responder(0, 'Cancelación exitosa.'); // Ser tolerantes: si no hay referencia, devolvemos 0
}

$referencia = normalizar_referencia((string) $referenciaRaw);
if ($referencia === '' || strlen($referencia) > 30) {
    cct_responder(0, 'Cancelación exitosa.');
}

/* =============================================================================
 * 3) BUSCAR REFERENCIA EN BD
 * ========================================================================== */
try {
    if (!function_exists('getDBConnection')) {
        throw new RuntimeException('getDBConnection() no está definida.');
    }

    $conn = getDBConnection();
    if (!$conn || !($conn instanceof PDO)) {
        throw new RuntimeException('getDBConnection() no devolvió un PDO válido.');
    }

    // NOTA: :ref1 y :ref2 evitan SQLSTATE[HY093]
    $stmt = $conn->prepare(
        'SELECT id, reference_cct, reference_emisor, folio_cct,
                monto, estado, fecha_pago,
                autorizacion_cct, transaccion_cct
           FROM referencias_pago
          WHERE reference_cct    = :ref1
             OR reference_emisor = :ref2
          LIMIT 1'
    );
    $stmt->execute([
        ':ref1' => $referencia,
        ':ref2' => $referencia,
    ]);
    $ref = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log('[CCT CancelaPago] ¿Encontrada?: ' . ($ref ? 'SÍ (id=' . $ref['id'] . ')' : 'NO'));

    /* =====================================================================
     * 4) RESPUESTAS SEGÚN EL ESTADO
     * ===================================================================== */

    // 4.1) No encontrada: se responde 0 por idempotencia (evita errores)
    if (!$ref) {
        cct_responder(0, 'Cancelación exitosa.');
    }

    // 4.2) Ya cancelada previamente → código 0 (pág. 17)
    if ($ref['estado'] === 'cancelada') {
        cct_responder(0, 'Cancelación exitosa.');
    }

    // 4.3) Nunca estuvo pagada (estado pendiente): nada que cancelar
    if ($ref['estado'] === 'pendiente') {
        cct_responder(0, 'Cancelación exitosa.');
    }

    // 4.4) Expirada: no hay pago que cancelar
    if ($ref['estado'] === 'expirada') {
        cct_responder(0, 'Cancelación exitosa.');
    }

    // 4.5) Está pagada: validar que sea el mismo día
    if ($ref['estado'] === 'pagada') {
        $fechaPago = extraer_fecha((string) ($ref['fecha_pago'] ?? ''));
        $hoy       = date('Y-m-d');

        // El documento dice: "se podrá llamar dentro del mismo día en que se dio la Autorización"
        if ($fechaPago !== '' && $fechaPago !== $hoy) {
            error_log("[CCT CancelaPago] Fuera de periodo: fecha_pago={$fechaPago}, hoy={$hoy}");
            cct_responder(
                60,
                'Cancelación fuera de periodo: solo se permite el mismo día del pago.'
            );
        }

        // Validar opcionalmente autorización si viene
        if ($autorizacion !== '' && !empty($ref['autorizacion_cct'])
            && $autorizacion !== $ref['autorizacion_cct']) {
            error_log("[CCT CancelaPago] Autorización no coincide: recibida={$autorizacion}, en BD={$ref['autorizacion_cct']}");
            // No bloqueamos por esto, solo lo dejamos en log.
            // Si quieres ser más estricto, cambia esto por cct_responder(60, '...');
        }

        // --- 4.6) Cancelar el pago ---
        $upd = $conn->prepare(
            'UPDATE referencias_pago
                SET estado             = "cancelada",
                    fecha_cancelacion  = NOW(),
                    updated_at         = NOW()
              WHERE id = :id
                AND estado = "pagada"'
        );
        $upd->execute([':id' => $ref['id']]);

        if ($upd->rowCount() === 0) {
            // Race condition: alguien lo canceló entre SELECT y UPDATE
            error_log('[CCT CancelaPago] UPDATE no afectó filas (posible cancelación concurrente) id=' . $ref['id']);
        } else {
            error_log('[CCT CancelaPago] Cancelación OK id=' . $ref['id'] .
                      ' transaccion=' . $transaccion .
                      ' autorizacion=' . $autorizacion);
        }

        // 4.7) Respuesta exitosa
        cct_responder(0, 'Cancelación exitosa.');
    }

    // Fallback (estado desconocido)
    cct_responder(0, 'Cancelación exitosa.');

} catch (Throwable $e) {
    error_log('[CCT CancelaPago] EXCEPCION: ' . $e->getMessage() .
              ' | Archivo: ' . $e->getFile() . ':' . $e->getLine());

    // ⚠️ MODO DEBUG: devolvemos el mensaje real. Cambiar a genérico en producción.
    cct_responder(
        0, // Aun en error, respondemos 0 para no bloquear a CCT (idempotencia)
        'DEBUG >> ' . $e->getMessage() . ' | ' . basename($e->getFile()) . ':' . $e->getLine()
    );
}