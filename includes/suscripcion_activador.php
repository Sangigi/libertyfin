<?php
/**
 * includes/suscripcion_activador.php
 *
 * Lógica compartida entre los dos webhooks de confirmación de pago de
 * suscripción (tarjeta: Service/EntregarPagoLineaToken.php, y SPEI:
 * Service/pago_clabe.php), para que ambos medios de pago activen la
 * suscripción exactamente igual y generen la misma factura de LibertyFin
 * cuando el cliente la haya pedido.
 */

/**
 * Crea en clabes_spei las columnas de plan/periodo/facturación si faltan.
 * La llaman TANTO generar_clabe.php (al crear la CLABE) COMO pago_clabe.php
 * (el webhook que confirma el pago) -- así, aunque el webhook reciba la
 * notificación de una CLABE generada antes de este cambio, la columna ya
 * existe y no truena el SELECT.
 */
function mcp_asegurar_columnas_clabes_spei(PDO $pdo): void
{
    $columnas_necesarias = [
        'empresa_id'             => "ALTER TABLE clabes_spei ADD COLUMN empresa_id INT(11) DEFAULT NULL AFTER id, ADD KEY idx_empresa_id (empresa_id)",
        'plan'                   => "ALTER TABLE clabes_spei ADD COLUMN plan VARCHAR(20) DEFAULT NULL",
        'periodo'                => "ALTER TABLE clabes_spei ADD COLUMN periodo VARCHAR(10) DEFAULT NULL",
        'tipo_servicio'          => "ALTER TABLE clabes_spei ADD COLUMN tipo_servicio VARCHAR(30) DEFAULT NULL",
        'facturar'               => "ALTER TABLE clabes_spei ADD COLUMN facturar VARCHAR(3) DEFAULT 'no'",
        'factura_razon_social'   => "ALTER TABLE clabes_spei ADD COLUMN factura_razon_social VARCHAR(200) DEFAULT NULL",
        'factura_rfc'            => "ALTER TABLE clabes_spei ADD COLUMN factura_rfc VARCHAR(20) DEFAULT NULL",
        'factura_email'          => "ALTER TABLE clabes_spei ADD COLUMN factura_email VARCHAR(160) DEFAULT NULL",
        'factura_regimen_fiscal' => "ALTER TABLE clabes_spei ADD COLUMN factura_regimen_fiscal VARCHAR(10) DEFAULT NULL",
        'factura_cp'             => "ALTER TABLE clabes_spei ADD COLUMN factura_cp VARCHAR(5) DEFAULT NULL",
        'factura_estado'         => "ALTER TABLE clabes_spei ADD COLUMN factura_estado VARCHAR(100) DEFAULT NULL",
        'factura_ciudad'         => "ALTER TABLE clabes_spei ADD COLUMN factura_ciudad VARCHAR(100) DEFAULT NULL",
        'factura_metodo_pago'    => "ALTER TABLE clabes_spei ADD COLUMN factura_metodo_pago VARCHAR(3) DEFAULT NULL",
        'factura_uso_cfdi'       => "ALTER TABLE clabes_spei ADD COLUMN factura_uso_cfdi VARCHAR(3) DEFAULT NULL",
    ];

    $columnas_actuales = [];
    try {
        $stmt_check = $pdo->query("SHOW COLUMNS FROM clabes_spei");
        foreach ($stmt_check->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columnas_actuales[] = $col['Field'];
        }
    } catch (PDOException $e) {
        error_log("mcp_asegurar_columnas_clabes_spei: no se pudo leer columnas: " . $e->getMessage());
        return;
    }

    foreach ($columnas_necesarias as $nombre => $alterSql) {
        if (!in_array($nombre, $columnas_actuales, true)) {
            try {
                $pdo->exec($alterSql);
                error_log("✅ Columna $nombre agregada a clabes_spei");
            } catch (PDOException $alterError) {
                error_log("⚠️ No se pudo agregar columna $nombre: " . $alterError->getMessage());
            }
        }
    }
}

/**
 * Crea en domiciliacion_ligas las columnas de facturación si faltan (el
 * pago con tarjeta ya guardaba plan/periodo/empresa_id desde antes; solo
 * faltaban los datos de "¿Requieres factura?" del checkout).
 */
function mcp_asegurar_columnas_domiciliacion_ligas(PDO $pdo): void
{
    $columnas_necesarias = [
        'facturar'               => "ALTER TABLE domiciliacion_ligas ADD COLUMN facturar VARCHAR(3) DEFAULT 'no'",
        'factura_razon_social'   => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_razon_social VARCHAR(200) DEFAULT NULL",
        'factura_rfc'            => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_rfc VARCHAR(20) DEFAULT NULL",
        'factura_email'          => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_email VARCHAR(160) DEFAULT NULL",
        'factura_regimen_fiscal' => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_regimen_fiscal VARCHAR(10) DEFAULT NULL",
        'factura_cp'             => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_cp VARCHAR(5) DEFAULT NULL",
        'factura_estado'         => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_estado VARCHAR(100) DEFAULT NULL",
        'factura_ciudad'         => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_ciudad VARCHAR(100) DEFAULT NULL",
        'factura_metodo_pago'    => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_metodo_pago VARCHAR(3) DEFAULT NULL",
        'factura_uso_cfdi'       => "ALTER TABLE domiciliacion_ligas ADD COLUMN factura_uso_cfdi VARCHAR(3) DEFAULT NULL",
    ];

    $columnas_actuales = [];
    try {
        $stmt_check = $pdo->query("SHOW COLUMNS FROM domiciliacion_ligas");
        foreach ($stmt_check->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columnas_actuales[] = $col['Field'];
        }
    } catch (PDOException $e) {
        error_log("mcp_asegurar_columnas_domiciliacion_ligas: no se pudo leer columnas: " . $e->getMessage());
        return;
    }

    foreach ($columnas_necesarias as $nombre => $alterSql) {
        if (!in_array($nombre, $columnas_actuales, true)) {
            try {
                $pdo->exec($alterSql);
                error_log("✅ Columna $nombre agregada a domiciliacion_ligas");
            } catch (PDOException $alterError) {
                error_log("⚠️ No se pudo agregar columna $nombre: " . $alterError->getMessage());
            }
        }
    }
}

/**
 * Calcula la nueva fecha de vencimiento de una empresa y actualiza su plan.
 * Misma regla que ya usaba EntregarPagoLineaToken.php: si el servicio
 * todavía no vencía, el nuevo período se SUMA a la fecha de vencimiento
 * actual (no se desperdicia lo que ya tenía pagado); si ya venció, la
 * vigencia nueva se cuenta desde hoy.
 *
 * @return array{fecha_vencimiento_anterior: ?string, fecha_vencimiento_nueva: ?string, tipo_periodo: string}
 */
function mcp_activar_suscripcion(PDO $pdo, int $empresa_id, string $plan, string $periodo): array
{
    $esAnual = stripos($periodo, 'anual') !== false;
    $intervalo = $esAnual ? 'INTERVAL 1 YEAR' : 'INTERVAL 1 MONTH';
    $tipo_periodo = $esAnual ? 'ANUAL' : 'MENSUAL';

    $stmt = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
    $stmt->execute([$empresa_id]);
    $actual = $stmt->fetch(PDO::FETCH_ASSOC);
    $fecha_anterior = $actual['fecha_vencimiento'] ?? null;

    $usarNow = true;
    if ($fecha_anterior) {
        $fechaVenc = new DateTime($fecha_anterior);
        if ($fechaVenc > new DateTime()) {
            $usarNow = false;
        }
    }

    if ($usarNow) {
        $pdo->prepare("
            UPDATE empresas SET
                plan = :plan,
                fecha_actualizacion = NOW(),
                fecha_vencimiento = DATE_ADD(NOW(), $intervalo),
                activo = 1
            WHERE id = :empresa_id
        ")->execute([':plan' => $plan, ':empresa_id' => $empresa_id]);
    } else {
        $pdo->prepare("
            UPDATE empresas SET
                plan = :plan,
                fecha_actualizacion = NOW(),
                fecha_vencimiento = DATE_ADD(:fecha_base, $intervalo),
                activo = 1
            WHERE id = :empresa_id
        ")->execute([':plan' => $plan, ':empresa_id' => $empresa_id, ':fecha_base' => $fecha_anterior]);
    }

    $stmt = $pdo->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
    $stmt->execute([$empresa_id]);
    $nueva = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'fecha_vencimiento_anterior' => $fecha_anterior,
        'fecha_vencimiento_nueva' => $nueva['fecha_vencimiento'] ?? null,
        'tipo_periodo' => $tipo_periodo,
    ];
}

/**
 * Genera (si corresponde) el CFDI de la suscripción pagada, con LibertyFin
 * como emisor y la empresa que pagó como receptor.
 *
 * Requiere la variable de entorno FACTURAPI_LIBERTYFIN_ORGANIZATION_ID
 * (el organization_id propio de LibertyFin en Facturapi -- ver
 * Service/facturapi_listar_organizaciones.php para encontrarlo). Mientras
 * esa variable no exista, esta función no hace nada y lo deja anotado en
 * el log -- no rompe la activación de la suscripción, que ya ocurrió antes
 * de llamar a esta función.
 *
 * @param array $datosFactura Debe traer: facturar ('si'/'no'), razon_social,
 *        rfc, email, regimen_fiscal, cp, estado, ciudad, metodo_pago, uso_cfdi.
 * @return array{generada: bool, motivo: ?string, uuid: ?string, folio: ?string}
 */
function mcp_generar_factura_suscripcion(array $datosFactura, float $monto, string $descripcion): array
{
    $resultado = ['generada' => false, 'motivo' => null, 'uuid' => null, 'folio' => null];

    if (($datosFactura['facturar'] ?? 'no') !== 'si') {
        $resultado['motivo'] = 'El cliente no pidió factura para este pago.';
        return $resultado;
    }

    $organization_id = env('FACTURAPI_LIBERTYFIN_ORGANIZATION_ID');
    $api_key = env('FACTURAPI_API_KEY');

    if (empty($organization_id) || empty($api_key)) {
        $resultado['motivo'] = 'Falta configurar FACTURAPI_LIBERTYFIN_ORGANIZATION_ID (o FACTURAPI_API_KEY) en el .env -- la solicitud de factura queda guardada, pero el CFDI no se genera todavía.';
        error_log('mcp_generar_factura_suscripcion: ' . $resultado['motivo']);
        return $resultado;
    }

    $camposObligatorios = ['razon_social', 'rfc', 'email', 'regimen_fiscal', 'cp'];
    foreach ($camposObligatorios as $campo) {
        if (empty($datosFactura[$campo])) {
            $resultado['motivo'] = "Falta el campo '$campo' para poder facturar.";
            error_log('mcp_generar_factura_suscripcion: ' . $resultado['motivo']);
            return $resultado;
        }
    }

    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $facturapiMaster = new \Facturapi\Facturapi($api_key);
        $test_api_key_obj = $facturapiMaster->Organizations->getTestApiKey($organization_id);
        $test_api_key = is_object($test_api_key_obj)
            ? ($test_api_key_obj->key ?? $test_api_key_obj->api_key ?? $test_api_key_obj->secret ?? null)
            : $test_api_key_obj;

        if (empty($test_api_key)) {
            throw new Exception('No se pudo obtener la API Key de la organización de LibertyFin.');
        }

        $facturapi = new \Facturapi\Facturapi($test_api_key);

        $rfcLimpio = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $datosFactura['rfc']));

        // Clave genérica de servicios de tecnología/software del catálogo del
        // SAT (c_ClaveProdServ). Ajusta esta clave si tu contador prefiere
        // otra para la suscripción de LibertyFin.
        $invoiceData = [
            'customer' => [
                'legal_name' => $datosFactura['razon_social'],
                'email' => $datosFactura['email'],
                'tax_id' => $rfcLimpio,
                'tax_system' => $datosFactura['regimen_fiscal'],
                'address' => [
                    'zip' => $datosFactura['cp'],
                    'state' => $datosFactura['estado'] ?? '',
                    'city' => $datosFactura['ciudad'] ?? '',
                ],
            ],
            'items' => [[
                'quantity' => 1,
                'product' => [
                    'description' => $descripcion,
                    'product_key' => '81112501',
                    'price' => $monto,
                ],
            ]],
            'payment_form' => (($datosFactura['metodo_pago'] ?? 'PUE') === 'PPD') ? '31' : '28',
            'use' => $datosFactura['uso_cfdi'] ?? 'G03',
        ];

        $invoice = $facturapi->Invoices->create($invoiceData);

        $resultado['generada'] = !empty($invoice->uuid ?? $invoice->id ?? null);
        $resultado['uuid'] = $invoice->uuid ?? $invoice->id ?? null;
        $resultado['folio'] = $invoice->folio_number ?? $invoice->folio ?? null;

        if (!empty($datosFactura['email'])) {
            try {
                $facturapi->Invoices->send_by_email($invoice->id, $datosFactura['email']);
            } catch (Exception $e) {
                error_log('mcp_generar_factura_suscripcion: factura creada pero no se pudo enviar por correo: ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        $resultado['motivo'] = 'Error al generar la factura: ' . $e->getMessage();
        error_log('mcp_generar_factura_suscripcion: ' . $resultado['motivo']);
    }

    return $resultado;
}
