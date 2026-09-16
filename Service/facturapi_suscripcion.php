<?php
/**
 * facturapi_suscripcion.php
 *
 * Timbra el CFDI de LibertyFin (emisor) hacia la empresa que paga su
 * suscripción (receptor), usando la organización maestra de Facturapi
 * de LibertyFin (NO la organización individual de cada empresa cliente,
 * esa es para que ELLAS facturen a SUS clientes en facturar_venta.php).
 */

function limpiarRFCSuscripcion($rfc) {
    return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $rfc));
}

/**
 * @param array  $datosFiscales ['razon_social','rfc','email_factura','regimen_fiscal','cp','metodo_pago_sat','uso_cfdi']
 * @param float  $monto         Monto total cobrado (MXN, con IVA incluido si aplica)
 * @param string $descripcionPlan Ej: "Suscripción LibertyFin - Plan Profesional - Mensual"
 *
 * @return array ['success' => bool, 'uuid' => ?string, 'folio' => ?string, 'error' => ?string]
 */
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