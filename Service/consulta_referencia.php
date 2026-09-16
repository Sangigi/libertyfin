<?php
/**
 * Service/consulta_referencia.php
 * -----------------------------------------------------------------------------
 * Endpoint que Cobroscontarjeta.com (CCT) LLAMA por HTTP GET para validar
 * una referencia cuando el cliente la presenta en una tienda (OXXO, etc.).
 *
 * Doc base: IntegracionesReferencias V1.4 — pág. 10-12
 *
 * Flujo:
 *   CCT → GET https://tu-dominio.com/Service/consulta_referencia.php?r=REFERENCIA
 *   Tu sistema → responde JSON { codigo, mensaje, monto, referencia, transaccion, parcial }
 *
 * Códigos de respuesta (pág. 12):
 *   0   → Operación exitosa (se autoriza el pago en tienda)
 *   13  → Referencia sin adeudo (ya pagada)
 *   14  → Referencia fuera de vigencia
 *   15  → Referencia con error de formato
 *   40  → Adquirente inválido (no se reconoce la referencia)
 *   50  → Error de sistema
 * -----------------------------------------------------------------------------
 */

// 1) Buffer de salida: evita que warnings/notices rompan el JSON
ob_start();

// 2) Desactivar display_errors (los logs van al archivo, no a la salida)
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

/* =============================================================================
 * HELPERS
 * ========================================================================== */

/**
 * Responde SIEMPRE con los 6 campos requeridos por CCT (pág. 11).
 */
function cct_responder(
    int    $codigo,
    string $mensaje,
    string $monto       = '0',
    string $referencia  = '',
    string $transaccion = ''
): void {
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    $payload = [
        'codigo'      => (int)    $codigo,
        'mensaje'     => (string) $mensaje,
        'monto'       => (string) $monto,
        'referencia'  => (string) $referencia,
        'transaccion' => (string) $transaccion,
        'parcial'     => true,   // booleano, siempre true (pág. 11)
    ];

    error_log('[CCT ConsultaReferencia] RESPONDIENDO: ' .
              json_encode($payload, JSON_UNESCAPED_UNICODE));

    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ob_end_flush();
    exit;
}

/** Normaliza la referencia: solo dígitos, sin espacios ni guiones. */
function normalizar_referencia(string $ref): string
{
    return preg_replace('/\D+/', '', $ref) ?? '';
}

/* =============================================================================
 * 1) LEER PARÁMETRO ?r=REFERENCIA
 * ========================================================================== */
$referenciaRaw = $_GET['r'] ?? $_GET['referencia'] ?? $_REQUEST['r'] ?? '';

error_log('[CCT ConsultaReferencia] REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? ''));
error_log('[CCT ConsultaReferencia] GET: ' . json_encode($_GET));

if ($referenciaRaw === '') {
    cct_responder(15, 'Referencia con error de formato: parametro ?r= requerido.');
}

$referencia = normalizar_referencia((string) $referenciaRaw);

if ($referencia === '' || strlen($referencia) > 30) {
    cct_responder(15, 'Referencia con error de formato.', '0', $referencia, '');
}

/* =============================================================================
 * 2) BUSCAR EN BD
 * ========================================================================== */
try {
    // --- 2.1) Verificar que exista la función ---
    if (!function_exists('getDBConnection')) {
        throw new RuntimeException(
            'La función getDBConnection() no está definida. Revisa config/database.php'
        );
    }

    $conn = getDBConnection();

    // --- 2.2) Verificar que devuelva un PDO válido ---
    if (!$conn || !($conn instanceof PDO)) {
        throw new RuntimeException(
            'getDBConnection() no devolvió un objeto PDO válido. Recibido: ' . gettype($conn)
        );
    }

    // --- 2.3) Verificar la base de datos actual (para debug) ---
    $dbActual = $conn->query('SELECT DATABASE()')->fetchColumn();
    error_log('[CCT ConsultaReferencia] DB actual: ' . $dbActual);

    // --- 2.4) Verificar que exista la tabla ---
    $tablaExiste = $conn->query("SHOW TABLES LIKE 'referencias_pago'")->fetch();
    if (!$tablaExiste) {
        throw new RuntimeException(
            'La tabla referencias_pago no existe en la BD: ' . $dbActual
        );
    }

    // --- 2.5) Ejecutar la consulta ---
    // ⚠️ IMPORTANTE: usamos :ref1 y :ref2 porque PDO NO permite reutilizar
    // el mismo named placeholder. Reutilizar :ref produce SQLSTATE[HY093].
    $stmt = $conn->prepare(
        'SELECT id, reference_cct, reference_emisor, folio_cct,
                monto, estado, fecha_expiracion,
                fecha_pago, transaccion_cct, autorizacion_cct
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

    error_log('[CCT ConsultaReferencia] ¿Encontrada?: ' .
              ($ref ? 'SÍ (id=' . $ref['id'] . ')' : 'NO'));

    /* =====================================================================
     * 3) RESPUESTAS SEGÚN EL ESTADO
     * ===================================================================== */

    // 3.1) No encontrada
    if (!$ref) {
        cct_responder(
            40,
            'Adquirente invalido: referencia no reconocida.',
            '0',
            $referencia,
            ''
        );
    }

    // 3.2) Ya pagada
    if ($ref['estado'] === 'pagada') {
        cct_responder(
            13,
            'Referencia sin adeudo: ya fue pagada.',
            '0',
            (string) $ref['reference_cct'],
            (string) ($ref['transaccion_cct'] ?? '')
        );
    }

    // 3.3) Cancelada o expirada
    if (in_array($ref['estado'], ['cancelada', 'expirada'], true)) {
        cct_responder(
            14,
            'Referencia fuera de vigencia.',
            '0',
            (string) $ref['reference_cct'],
            (string) ($ref['folio_cct'] ?? '')
        );
    }

    // 3.4) Validar fecha de expiración (por si el EVENT no la ha marcado aún)
    if (!empty($ref['fecha_expiracion'])) {
        $hoy   = new DateTime('today');
        $vence = new DateTime($ref['fecha_expiracion']);
        if ($vence < $hoy) {
            $upd = $conn->prepare(
                'UPDATE referencias_pago
                    SET estado = "expirada", updated_at = NOW()
                  WHERE id = :id AND estado = "pendiente"'
            );
            $upd->execute([':id' => $ref['id']]);

            cct_responder(
                14,
                'Referencia fuera de vigencia.',
                '0',
                (string) $ref['reference_cct'],
                (string) ($ref['folio_cct'] ?? '')
            );
        }
    }

    // 3.5) OK → autorizar pago en tienda
    $montoCentavos = (int) round(((float) $ref['monto']) * 100);

    cct_responder(
        0,
        'Operacion exitosa',
        (string) $montoCentavos,                    // 599.00 → "59900"
        (string) $ref['reference_cct'],
        (string) ($ref['folio_cct'] ?? '')
    );

} catch (Throwable $e) {
    // ⚠️ MODO DEBUG: devolvemos el mensaje real de la excepción.
    // Cuando ya funcione en producción, cambia el cct_responder por:
    //   cct_responder(50, 'Error de sistema.', '0', $referencia, '');
    error_log('[CCT ConsultaReferencia] EXCEPCION: ' . $e->getMessage() .
              ' | Archivo: ' . $e->getFile() . ':' . $e->getLine());

    cct_responder(
        50,
        'DEBUG >> ' . $e->getMessage() .
        ' | ' . basename($e->getFile()) . ':' . $e->getLine(),
        '0',
        $referencia,
        ''
    );
}