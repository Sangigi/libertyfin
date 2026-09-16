<?php
/**
 * Service/pago_referencia.php
 * -----------------------------------------------------------------------------
 * Endpoint que Cobroscontarjeta.com (CCT) LLAMA por HTTP POST cuando un cliente
 * ha pagado la referencia en una tienda y CCT solicita la autorización del pago.
 *
 * Doc base: IntegracionesReferencias V1.4 — pág. 12-14
 * -----------------------------------------------------------------------------
 */

ob_start();

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

error_log(sprintf(
    '[CCT PagoReferencia] === BOOT === file=%s | mtime=%s | md5=%s | pid=%d',
    __FILE__,
    date('Y-m-d H:i:s', filemtime(__FILE__)),
    substr(md5_file(__FILE__), 0, 8),
    getmypid()
));

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/facturapi_suscripcion.php';

/* =============================================================================
 * HELPERS
 * ========================================================================== */

function cct_responder(
    int    $codigo,
    string $autorizacion = '',
    string $mensaje      = '',
    string $transaccion  = '',
    string $fecha        = ''
): void {
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    if ($fecha === '') {
        $fecha = date('Y-m-d');
    }

    $payload = [
        'codigo'           => (int)    $codigo,
        'autorizacion'     => (string) $autorizacion,
        'mensaje'          => (string) $mensaje,
        'transaccion'      => (string) $transaccion,
        'fecha'            => (string) $fecha,
        'notificacion_sms' => '',
        'mensaje_sms'      => '',
        'mensaje_ticket'   => '',
    ];

    error_log('[CCT PagoReferencia] RESPONDIENDO: ' .
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

function generar_autorizacion(): string
{
    return str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
}

function detectar_plan_desde_descripcion(string $descripcion): string
{
    $d = mb_strtolower($descripcion, 'UTF-8');

    if (str_contains($d, 'básico') || str_contains($d, 'basico')) return 'basico';
    if (str_contains($d, 'profesional'))                         return 'profesional';
    if (str_contains($d, 'plus') || str_contains($d, 'premium')) return 'premium';
    if (str_contains($d, 'empresarial'))                         return 'empresarial';

    return 'empresarial';
}

function detectar_plazo_desde_descripcion(string $descripcion): string
{
    return str_contains(mb_strtolower($descripcion, 'UTF-8'), 'anual') ? 'anual' : 'mensual';
}

/** Toma el primer valor NO vacío. */
function pick_first(...$vals)
{
    foreach ($vals as $v) {
        if ($v !== null && $v !== '' && $v !== '0') return $v;
        // OJO: '0' es válido para algunos campos (ej. régimen '605' no, pero
        // por seguridad no descartamos '0' para campos que no lo usan)
    }
    foreach ($vals as $v) {
        if ($v !== null && $v !== '') return $v;
    }
    return null;
}

/* =============================================================================
 * 1) LEER BODY
 * ========================================================================== */
$rawBody = file_get_contents('php://input') ?: '';
$input   = [];

error_log('[CCT PagoReferencia] RAW BODY: ' . $rawBody);

$json = json_decode($rawBody, true);
if (is_array($json)) {
    $input = $json;
} else {
    parse_str($rawBody, $formData);
    if (is_array($formData) && !empty($formData)) {
        $input = $formData;
    }
}

if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}

error_log('[CCT PagoReferencia] INPUT parseado: ' . json_encode($input));

/* =============================================================================
 * 2) VALIDAR CAMPOS REQUERIDOS
 * ========================================================================== */
$referenciaRaw = $input['referencia'] ?? $input['reference'] ?? '';
$fechaRaw      = $input['fecha']      ?? '';
$montoRaw      = $input['monto']      ?? '';
$transaccion   = (string) ($input['transaccion'] ?? '');

if ($referenciaRaw === '' || $montoRaw === '' || $transaccion === '') {
    cct_responder(15, '', 'Referencia con error de formato: faltan campos requeridos.', $transaccion);
}

$referencia = normalizar_referencia((string) $referenciaRaw);
if ($referencia === '' || strlen($referencia) > 30) {
    cct_responder(15, '', 'Referencia con error de formato.', $transaccion);
}

$montoCentavos = (int) preg_replace('/\D+/', '', (string) $montoRaw);
if ($montoCentavos <= 0) {
    cct_responder(30, '', 'Monto inválido.', $transaccion);
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

    // SELECT que cubre TODOS los posibles campos de CP y de datos fiscales
    // que existen en referencias_pago según el schema.
    $stmt = $conn->prepare(
        'SELECT id, empresa_id, plan, plazo, tipo_servicio,
                reference_cct, reference_emisor, folio_cct,
                monto, estado, fecha_expiracion,
                fecha_pago, transaccion_cct, autorizacion_cct,
                descripcion, customer_email, customer_name,
                requiere_factura,
                rfc, razon_social, email_factura, regimen_fiscal,
                cp_factura, cp_fiscal, factura_cp,
                metodo_pago_sat, uso_cfdi,
                factura_rfc, factura_razon_social, factura_email,
                factura_regimen_fiscal, factura_metodo_pago, factura_uso_cfdi
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

    error_log('[CCT PagoReferencia] ¿Encontrada?: ' .
              ($ref ? 'SÍ (id=' . $ref['id'] . ')' : 'NO'));

    /* =====================================================================
     * 4) VALIDACIONES
     * ===================================================================== */
    if (!$ref) {
        cct_responder(40, '', 'Adquirente inválido: referencia no reconocida.', $transaccion);
    }

    error_log('[CCT PagoReferencia] Datos fiscales en BD: ' . json_encode([
        'requiere_factura'  => $ref['requiere_factura']  ?? null,
        'rfc'               => $ref['rfc']               ?? null,
        'razon_social'      => $ref['razon_social']      ?? null,
        'regimen_fiscal'    => $ref['regimen_fiscal']    ?? null,
        'cp_factura'        => $ref['cp_factura']        ?? null,
        'cp_fiscal'         => $ref['cp_fiscal']         ?? null,
        'factura_cp'        => $ref['factura_cp']        ?? null,
        'email_factura'     => $ref['email_factura']     ?? null,
        'metodo_pago_sat'   => $ref['metodo_pago_sat']   ?? null,
        'uso_cfdi'          => $ref['uso_cfdi']          ?? null,
    ], JSON_UNESCAPED_UNICODE));

    if ($ref['estado'] === 'pagada') {
        cct_responder(
            13,
            (string) ($ref['autorizacion_cct'] ?? ''),
            'Referencia sin adeudo: ya fue pagada previamente.',
            $transaccion,
            !empty($ref['fecha_pago'])
                ? date('Y-m-d', strtotime($ref['fecha_pago']))
                : date('Y-m-d')
        );
    }

    if (in_array($ref['estado'], ['cancelada', 'expirada'], true)) {
        cct_responder(14, '', 'Referencia fuera de vigencia.', $transaccion);
    }

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

            cct_responder(14, '', 'Referencia fuera de vigencia.', $transaccion);
        }
    }

    $montoEsperado = (int) round(((float) $ref['monto']) * 100);
    if ($montoCentavos !== $montoEsperado) {
        error_log("[CCT PagoReferencia] Monto no coincide: recibido={$montoCentavos}, esperado={$montoEsperado}");
        cct_responder(30, '', 'Monto inválido: no coincide con el monto de la referencia.', $transaccion);
    }

    /* =====================================================================
     * 5) AUTORIZAR PAGO
     * ===================================================================== */
    $autorizacion = generar_autorizacion();

    $fechaPago = date('Y-m-d');
    if (!empty($fechaRaw) && preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $fechaRaw, $m)) {
        $fechaPago = $m[1];
    }

    $upd = $conn->prepare(
        'UPDATE referencias_pago
            SET estado           = "pagada",
                fecha_pago       = NOW(),
                autorizacion_cct = :autorizacion,
                transaccion_cct  = :transaccion,
                updated_at       = NOW()
          WHERE id = :id
            AND estado = "pendiente"'
    );
    $upd->execute([
        ':autorizacion' => $autorizacion,
        ':transaccion'  => $transaccion,
        ':id'           => $ref['id'],
    ]);

    if ($upd->rowCount() === 0) {
        error_log('[CCT PagoReferencia] UPDATE no afectó filas (doble autorización) id=' . $ref['id']);
        $stmt2 = $conn->prepare(
            'SELECT autorizacion_cct, fecha_pago FROM referencias_pago WHERE id = :id LIMIT 1'
        );
        $stmt2->execute([':id' => $ref['id']]);
        $refActual = $stmt2->fetch(PDO::FETCH_ASSOC);

        cct_responder(
            13,
            (string) ($refActual['autorizacion_cct'] ?? ''),
            'Referencia sin adeudo: ya fue pagada previamente.',
            $transaccion,
            !empty($refActual['fecha_pago'])
                ? date('Y-m-d', strtotime($refActual['fecha_pago']))
                : $fechaPago
        );
    }

    /* =====================================================================
     * 6) ACTIVAR SUSCRIPCIÓN
     * ===================================================================== */
    if (!empty($ref['empresa_id'])) {
        try {
            $pdoMain = getDBConnection();

            // 6.1) Plan y plazo
            $descripcionRef = (string) ($ref['descripcion'] ?? '');
            $plan_a_usar    = $ref['plan'] ?: detectar_plan_desde_descripcion($descripcionRef);

            if ($plan_a_usar === 'plus') {
                $plan_a_usar = 'premium';
            }

            $plazo     = $ref['plazo'] ?: detectar_plazo_desde_descripcion($descripcionRef);
            $intervalo = ($plazo === 'anual') ? 'INTERVAL 1 YEAR' : 'INTERVAL 1 MONTH';

            // 6.2) Fecha base
            $stmtFecha = $pdoMain->prepare(
                'SELECT fecha_vencimiento FROM empresas WHERE id = :empresa_id'
            );
            $stmtFecha->execute([':empresa_id' => $ref['empresa_id']]);
            $empresaActual = $stmtFecha->fetch(PDO::FETCH_ASSOC);

            $fechaBase = 'NOW()';
            if ($empresaActual && !empty($empresaActual['fecha_vencimiento'])) {
                $fechaVencimiento = new DateTime($empresaActual['fecha_vencimiento']);
                $hoy = new DateTime();
                if ($fechaVencimiento > $hoy) {
                    $fechaBase = $fechaVencimiento->format('Y-m-d H:i:s');
                }
            }

            // 6.3) UPDATE empresas
            if ($fechaBase === 'NOW()') {
                $sqlUpdate = "UPDATE empresas SET
                                plan                = :plan,
                                fecha_actualizacion = NOW(),
                                fecha_vencimiento   = DATE_ADD(NOW(), $intervalo),
                                activo              = 1
                              WHERE id = :empresa_id";
                $stmtUpd = $pdoMain->prepare($sqlUpdate);
                $stmtUpd->execute([
                    ':plan'       => $plan_a_usar,
                    ':empresa_id' => $ref['empresa_id'],
                ]);
            } else {
                $sqlUpdate = "UPDATE empresas SET
                                plan                = :plan,
                                fecha_actualizacion = NOW(),
                                fecha_vencimiento   = DATE_ADD(:fecha_base, $intervalo),
                                activo              = 1
                              WHERE id = :empresa_id";
                $stmtUpd = $pdoMain->prepare($sqlUpdate);
                $stmtUpd->execute([
                    ':plan'       => $plan_a_usar,
                    ':empresa_id' => $ref['empresa_id'],
                    ':fecha_base' => $fechaBase,
                ]);
            }

            error_log("[CCT PagoReferencia] Empresa {$ref['empresa_id']} activada con plan: $plan_a_usar ($plazo)");

            /* =================================================================
             * 6.4) EMPRESA + CORREO
             *      NOTA: empresas NO tiene columnas fiscales en este schema.
             *      Solo traemos nombre, email y vencimiento.
             * ================================================================= */
            $emp = null;
            $stmtEmp = $pdoMain->prepare(
                'SELECT nombre_empresa, email_admin, fecha_vencimiento
                   FROM empresas WHERE id = ?'
            );
            $stmtEmp->execute([$ref['empresa_id']]);
            $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: null;

            if (function_exists('enviarCorreoConfirmacionPago')) {
                $destinoCorreo = $emp['email_admin']
                              ?? $ref['customer_email']
                              ?? null;

                if (!empty($destinoCorreo)) {
                    enviarCorreoConfirmacionPago(
                        $destinoCorreo,
                        $emp['nombre_empresa'] ?? ($ref['customer_name'] ?? 'Cliente'),
                        $ref['descripcion']    ?? 'Suscripción',
                        '',
                        (float) $ref['monto'],
                        'REFERENCIA EN EFECTIVO',
                        $emp['fecha_vencimiento'] ?? null
                    );
                    error_log("[CCT PagoReferencia] Correo enviado a $destinoCorreo");
                } else {
                    error_log('[CCT PagoReferencia] Sin correo destino — no se envía notificación');
                }
            } else {
                error_log('[CCT PagoReferencia] enviarCorreoConfirmacionPago() no existe — omitiendo correo');
            }

            /* =================================================================
             * 6.5) TIMBRAR FACTURA
             *      Fallback de CP entre las 3 columnas de referencias_pago.
             * ================================================================= */
            error_log('[CCT PagoReferencia] >>> ENTRA A 6.5 (facturación). requiere_factura=' .
                      var_export($ref['requiere_factura'] ?? null, true));

            if (!empty($ref['requiere_factura'])) {
                try {
                    if (!function_exists('timbrarFacturaSuscripcion')) {
                        error_log('[CCT PagoReferencia] timbrarFacturaSuscripcion() no disponible — se omite factura');
                    } else {
                        // Fallback múltiple: primero bloque sin prefijo,
                        // luego bloque factura_*, ambos en referencias_pago.
                        $datosFiscales = [
                            'razon_social'    => pick_first($ref['razon_social'] ?? null, $ref['factura_razon_social'] ?? null),
                            'rfc'             => pick_first($ref['rfc'] ?? null, $ref['factura_rfc'] ?? null),
                            'email_factura'   => pick_first($ref['email_factura'] ?? null, $ref['factura_email'] ?? null, $ref['customer_email'] ?? null),
                            'regimen_fiscal'  => pick_first($ref['regimen_fiscal'] ?? null, $ref['factura_regimen_fiscal'] ?? null),
                            'cp'              => pick_first($ref['cp_factura'] ?? null, $ref['cp_fiscal'] ?? null, $ref['factura_cp'] ?? null),
                            'metodo_pago_sat' => pick_first($ref['metodo_pago_sat'] ?? null, $ref['factura_metodo_pago'] ?? null, 'PUE'),
                            'uso_cfdi'        => pick_first($ref['uso_cfdi'] ?? null, $ref['factura_uso_cfdi'] ?? null, 'G03'),
                        ];

                        error_log('[CCT PagoReferencia] Datos fiscales resueltos: ' .
                                  json_encode($datosFiscales, JSON_UNESCAPED_UNICODE));

                        if (empty($datosFiscales['cp'])) {
                            $msg = 'No se puede timbrar: no hay CP en referencias_pago (cp_factura, cp_fiscal ni factura_cp tienen valor).';
                            error_log('[CCT PagoReferencia] ' . $msg);

                            $pdoMain->prepare("UPDATE referencias_pago SET factura_error = :err WHERE id = :id")
                                    ->execute([':err' => $msg, ':id' => $ref['id']]);
                        } else {
                            $resultadoFactura = timbrarFacturaSuscripcion(
                                $datosFiscales,
                                (float) $ref['monto'],
                                'Suscripción LibertyFin - ' . ($ref['descripcion'] ?? '')
                            );

                            error_log('[CCT PagoReferencia] Timbrado factura: ' .
                                      json_encode($resultadoFactura, JSON_UNESCAPED_UNICODE));

                            $stmtF = $pdoMain->prepare(
                                "UPDATE referencias_pago
                                    SET factura_uuid  = :uuid,
                                        factura_folio = :folio,
                                        factura_error = :error
                                  WHERE id = :id"
                            );
                            $stmtF->execute([
                                ':uuid'  => $resultadoFactura['uuid']  ?? null,
                                ':folio' => $resultadoFactura['folio'] ?? null,
                                ':error' => $resultadoFactura['error'] ?? null,
                                ':id'    => $ref['id'],
                            ]);

                            // Auto-reparación: si timbró OK pero cp_factura estaba NULL,
                            // lo persistimos desde donde lo hayamos tomado.
                            if (!empty($resultadoFactura['uuid']) && empty($ref['cp_factura']) && !empty($datosFiscales['cp'])) {
                                try {
                                    $pdoMain->prepare(
                                        "UPDATE referencias_pago
                                            SET cp_factura = :cp
                                          WHERE id = :id
                                            AND (cp_factura IS NULL OR cp_factura = '')"
                                    )->execute([':cp' => $datosFiscales['cp'], ':id' => $ref['id']]);
                                } catch (Throwable $e) {
                                    error_log('[CCT PagoReferencia] No se pudo actualizar cp_factura: ' . $e->getMessage());
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[CCT PagoReferencia] Error al timbrar factura: ' . $e->getMessage());
                }
            } else {
                error_log('[CCT PagoReferencia] requiere_factura vacío/0 — se omite timbrado');
            }

        } catch (Throwable $e) {
            error_log('[CCT PagoReferencia] Error activando suscripción: ' . $e->getMessage());
        }
    } else {
        error_log('[CCT PagoReferencia] Referencia sin empresa_id — no se activa suscripción');
    }

    /* =====================================================================
     * 7) RESPUESTA EXITOSA
     * ===================================================================== */
    cct_responder(
        0,
        $autorizacion,
        'Operación exitosa',
        $transaccion,
        $fechaPago
    );

} catch (Throwable $e) {
    error_log('[CCT PagoReferencia] EXCEPCION: ' . $e->getMessage() .
              ' | Archivo: ' . $e->getFile() . ':' . $e->getLine());

    cct_responder(
        50,
        '',
        'DEBUG >> ' . $e->getMessage() . ' | ' . basename($e->getFile()) . ':' . $e->getLine(),
        $transaccion
    );
}