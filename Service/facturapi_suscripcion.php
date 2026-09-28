<?php
/**
 * facturapi_suscripcion.php
 *
 * Helpers de Facturapi para suscripciones de LibertyFin:
 *   1. asegurarOrganizacionFacturapi() -> crea la organización del cliente (empresa)
 *                                         SOLO si es premium y no existe.
 *   2. timbrarFacturaSuscripcion()     -> timbra el CFDI de LibertyFin (emisor)
 *                                         hacia la empresa que paga (receptor),
 *                                         usando la organización MAESTRA de LibertyFin.
 */

/* =============================================================================
 * LOG
 * ========================================================================== */
if (!function_exists('facturapiSuscripcionLog')) {
    function facturapiSuscripcionLog($mensaje, $tipo = 'INFO') {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        $archivo = $logDir . "/facturapi_" . date('Y-m-d') . ".log";
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($archivo, "[$timestamp] [$tipo] $mensaje" . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/* =============================================================================
 * LIMPIAR RFC
 * ========================================================================== */
function limpiarRFCSuscripcion($rfc) {
    return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $rfc));
}

/* =============================================================================
 * ASEGURAR ORGANIZACIÓN EN FACTURAPI
 * -----------------------------------------------------------------------------
 * Crea la organización del cliente (empresa) en Facturapi SOLO si:
 *   - El plan es 'premium'
 *   - La empresa aún no tiene facturapi_organization_id
 *
 * IMPORTANTE: esta organización es la que usará la EMPRESA CLIENTE para
 * facturar a SUS propios clientes (facturar_venta.php). NO confundir con
 * la organización MAESTRA de LibertyFin que usa timbrarFacturaSuscripcion().
 *
 * @param PDO    $pdo
 * @param int    $empresa_id
 * @param string $plan             Plan contratado ('premium', etc.)
 * @param string $nombre_empresa   Nombre comercial de la empresa
 *
 * @return array ['success' => bool, 'id' => ?string, 'creada' => bool, 'message' => string]
 * ========================================================================== */
function asegurarOrganizacionFacturapi($pdo, $empresa_id, $plan, $nombre_empresa) {
    $resultado = [
        'success' => false,
        'id'      => null,
        'creada'  => false,
        'message' => '',
    ];

    // Solo aplica para premium
    if (strtolower((string) $plan) !== 'premium') {
        $resultado['message'] = "Plan no es premium, no se crea organización.";
        return $resultado;
    }

    if ($empresa_id <= 0) {
        $resultado['message'] = "empresa_id inválido.";
        return $resultado;
    }

    if (!$pdo) {
        $resultado['message'] = "Sin conexión a BD.";
        return $resultado;
    }

    try {
        // 1. ¿Ya existe?
        $stmt = $pdo->prepare("SELECT facturapi_organization_id FROM empresas WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $empresa_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['facturapi_organization_id'])) {
            $resultado['success'] = true;
            $resultado['id']      = $row['facturapi_organization_id'];
            $resultado['creada']  = false;
            $resultado['message'] = "La empresa ya tiene organización Facturapi.";
            facturapiSuscripcionLog("ℹ️ Empresa $empresa_id ya tiene organización Facturapi: {$row['facturapi_organization_id']}", 'INFO');
            return $resultado;
        }

        // 2. Verificar API key MAESTRA
        $apiKey = function_exists('facturapiSuscripcionesConfig')
            ? facturapiSuscripcionesConfig('api_key')
            : (getenv('FACTURAPI_API_KEY') ?: '');

        if (empty($apiKey)) {
            throw new Exception("Clave API de Facturapi no configurada.");
        }

        // 3. Cargar SDK
        $autoload_path = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload_path)) {
            throw new Exception("SDK Facturapi no instalado. Ejecuta 'composer require facturapi/facturapi-php'");
        }
        require_once $autoload_path;

        // 4. Instanciar SDK con llave MAESTRA
        $facturapi = new \Facturapi\Facturapi($apiKey);

        // 5. Nombre por defecto si viene vacío
        if (empty($nombre_empresa)) {
            $nombre_empresa = "Empresa $empresa_id";
        }

        facturapiSuscripcionLog("=== CREANDO ORGANIZACIÓN EN FACTURAPI ===", 'INFO');
        facturapiSuscripcionLog("Plan: $plan | Nombre: $nombre_empresa | Empresa ID: $empresa_id", 'INFO');

        // 6. Crear organización (solo nombre, compatible v1 y v2)
        $organizacion = $facturapi->Organizations->create(['name' => $nombre_empresa]);

        if (!$organizacion || !isset($organizacion->id)) {
            throw new Exception("Facturapi respondió sin ID de organización.");
        }

        $facturapi_id = $organizacion->id;

        // 7. Guardar ID en BD
        $stmtUpd = $pdo->prepare("UPDATE empresas SET facturapi_organization_id = :id WHERE id = :empresa");
        $stmtUpd->execute([':id' => $facturapi_id, ':empresa' => $empresa_id]);

        $resultado['success'] = true;
        $resultado['id']      = $facturapi_id;
        $resultado['creada']  = true;

        if ($stmtUpd->rowCount() > 0) {
            $resultado['message'] = "Organización creada y guardada.";
            facturapiSuscripcionLog("✓ Organización creada y guardada. ID: $facturapi_id (empresa $empresa_id)", 'INFO');
        } else {
            $resultado['message'] = "Organización creada, pero no se actualizó la BD (rowCount=0).";
            facturapiSuscripcionLog("⚠️ Organización creada pero no guardada (rowCount=0) para empresa $empresa_id", 'WARNING');
        }

        return $resultado;

    } catch (Exception $e) {
        $resultado['success'] = false;
        $resultado['message'] = $e->getMessage();
        facturapiSuscripcionLog("✗ Error al crear organización Facturapi: " . $e->getMessage() . " (empresa $empresa_id)", 'ERROR');
        return $resultado;
    }
}

/* =============================================================================
 * TIMBRAR FACTURA DE SUSCRIPCIÓN
 * -----------------------------------------------------------------------------
 * (Tu función original, sin cambios)
 * ========================================================================== */
function timbrarFacturaSuscripcion($datosFiscales, $monto, $descripcionPlan) {
    try {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new Exception('No se encontró vendor/autoload.php (librería Facturapi no instalada)');
        }
        require_once $autoload;
        require_once __DIR__ . '/../config.php';

        $rfc_limpio = limpiarRFCSuscripcion($datosFiscales['rfc'] ?? '');
        if (strlen($rfc_limpio) < 12) {
            throw new Exception('RFC del cliente inválido: ' . ($datosFiscales['rfc'] ?? ''));
        }
        if (empty($datosFiscales['razon_social']) || empty($datosFiscales['regimen_fiscal']) || empty($datosFiscales['cp'])) {
            throw new Exception('Faltan datos fiscales obligatorios (razón social, régimen fiscal o CP)');
        }

        // Llave MAESTRA (sk_user_...) -- esta solo sirve para ADMINISTRAR
        // organizaciones (listarlas, obtener su llave). NUNCA se puede
        // usar directo para timbrar: Facturapi la rechaza con
        // "api_key_invalid". Por eso, unas líneas abajo, se intercambia
        // por la llave propia de la organización antes de timbrar.
        $apiKey = facturapiSuscripcionesConfig('api_key');
        $organizationId = facturapiSuscripcionesConfig('organization_id');

        if (empty($apiKey)) {
            throw new Exception('No hay API Key de Facturapi configurada para suscripciones');
        }
        if (empty($organizationId)) {
            throw new Exception('No hay organization_id de Facturapi configurado para suscripciones (FACTURAPI_SUSCRIPCIONES_ORG_ID)');
        }

        // --- Intercambio: de la llave maestra a la llave de LA ORGANIZACIÓN ---
        // Esta es la llave con la que sí se puede timbrar (mismo patrón
        // que ya usa Service/facturar_venta.php para las organizaciones
        // de cada empresa cliente).
        //
        // NOTA: getTestApiKey() genera facturas de PRUEBA (no válidas ante
        // el SAT). Para timbrar de verdad, esta línea debe cambiar a
        // getLiveApiKey($organizationId) -- y la organización necesita
        // tener ya cargados sus certificados CSD reales en Facturapi.
        $facturapiMaster = new Facturapi\Facturapi($apiKey);
        $orgApiKeyObj = $facturapiMaster->Organizations->getTestApiKey($organizationId);
        $orgApiKey = is_object($orgApiKeyObj)
            ? ($orgApiKeyObj->key ?? $orgApiKeyObj->api_key ?? $orgApiKeyObj->secret ?? null)
            : $orgApiKeyObj;

        if (empty($orgApiKey)) {
            throw new Exception('No se pudo obtener la API key de la organización de LibertyFin (organization_id: ' . $organizationId . ')');
        }

        // A partir de aquí, $facturapi ya usa la llave de la ORGANIZACIÓN,
        // no la maestra -- con esta sí se puede timbrar.
        $facturapi = new Facturapi\Facturapi($orgApiKey);

        $metodoPago = $datosFiscales['metodo_pago_sat'] ?? 'PUE';
        $usoCfdi = $datosFiscales['uso_cfdi'] ?? 'G03';

        $invoiceData = [
            'customer' => [
                'legal_name' => $datosFiscales['razon_social'],
                'email' => $datosFiscales['email_factura'] ?? null,
                'tax_id' => $rfc_limpio,
                'tax_system' => $datosFiscales['regimen_fiscal'],
                'address' => [
                    'zip' => $datosFiscales['cp'],
                ],
            ],
            'items' => [
                [
                    'quantity' => 1,
                    'product' => [
                        'description' => $descripcionPlan,
                        'product_key' => '81112501', // Licencias de derechos de uso de programas de informática (Software)
                        'price' => (float) $monto,
                    ],
                ],
            ],
            'payment_form' => ($metodoPago === 'PPD') ? '31' : '28',
            'use' => $usoCfdi,
        ];

        error_log("Facturapi (suscripción) request: " . json_encode($invoiceData));

        $invoice = $facturapi->Invoices->create($invoiceData);

        $uuid = $invoice->uuid ?? $invoice->id ?? null;
        $folio = $invoice->folio_number ?? $invoice->folio ?? null;

        if (!$uuid) {
            throw new Exception('No se obtuvo UUID de la factura. Respuesta: ' . json_encode($invoice));
        }

        $emailSent = false;
        if (!empty($datosFiscales['email_factura'])) {
            try {
                $emailResponse = $facturapi->Invoices->send_by_email($invoice->id, $datosFiscales['email_factura']);
                $emailSent = isset($emailResponse->ok) && $emailResponse->ok === true;
            } catch (Exception $e) {
                error_log("Error enviando factura de suscripción por correo: " . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'uuid' => $uuid,
            'folio' => $folio,
            'email_sent' => $emailSent,
            'error' => null,
        ];

    } catch (Facturapi\Exceptions\Facturapi_Exception $e) {
        error_log("Facturapi_Exception timbrando suscripción: " . $e->getMessage());
        return ['success' => false, 'uuid' => null, 'folio' => null, 'email_sent' => false, 'error' => 'Facturapi: ' . $e->getMessage()];
    } catch (Exception $e) {
        error_log("Error timbrando factura de suscripción: " . $e->getMessage());
        return ['success' => false, 'uuid' => null, 'folio' => null, 'email_sent' => false, 'error' => $e->getMessage()];
    }
}