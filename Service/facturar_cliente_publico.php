<?php
// Service/facturar_cliente_publico.php
// Facturación pública vía token QR (sin login)

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_errors.log');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../env_loader.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Facturapi\Facturapi;
use Facturapi\Exceptions\Facturapi_Exception;

// ------------------------------------------------------------
// FUNCIONES AUXILIARES
// ------------------------------------------------------------
function limpiarRFC($rfc) {
    return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $rfc));
}

function esClaveSATValida($clave) {
    return preg_match('/^[0-9]{8}$/', $clave);
}

function extraerMensajeLegibleFacturapi($mensaje) {
    if (empty($mensaje)) {
        return 'Error desconocido al facturar.';
    }

    $inicio = strpos($mensaje, '{');
    if ($inicio === false) {
        $limpio = preg_replace('/^Error de Facturapi:\s*/i', '', $mensaje);
        $limpio = preg_replace('/^Error:\s*/i', '', $limpio);
        return trim($limpio);
    }

    $jsonString = substr($mensaje, $inicio);
    $data = json_decode($jsonString, true);

    if (!is_array($data)) {
        return trim($mensaje);
    }

    $mensajes = [];
    if (!empty($data['errors']) && is_array($data['errors'])) {
        foreach ($data['errors'] as $err) {
            if (!empty($err['message'])) {
                $mensajes[] = $err['message'];
            }
        }
    }

    if (empty($mensajes) && !empty($data['message'])) {
        $mensajes[] = $data['message'];
    }

    if (empty($mensajes)) {
        return trim($mensaje);
    }

    $mensajes = array_values(array_unique($mensajes));
    return implode("\n\n", $mensajes);
}

// ------------------------------------------------------------
// VALIDAR TOKEN (en lugar de sesión)
// ------------------------------------------------------------
$token    = $_POST['token'] ?? '';
$venta_id = (int)($_POST['venta_id'] ?? 0);

if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token inválido']);
    exit;
}

if ($venta_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Venta inválida']);
    exit;
}

$empresa_id = (int)($_POST['empresa_id'] ?? 0);
$empresa_db = trim($_POST['empresa_db'] ?? '');

if ($empresa_id <= 0 || empty($empresa_db)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Datos de empresa faltantes']);
    exit;
}

// ------------------------------------------------------------
// VALIDAR QUE EL TOKEN PERTENECE A LA VENTA
// ------------------------------------------------------------
try {
    $conn = getEmpresaDBConnection($empresa_db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

$stmt_v = $conn->prepare("
    SELECT id, factura_token, factura_token_expira, factura_uuid
    FROM ventas
    WHERE id = :venta_id AND factura_token = :token
    LIMIT 1
");
$stmt_v->execute([':venta_id' => $venta_id, ':token' => $token]);
$venta_token = $stmt_v->fetch(PDO::FETCH_ASSOC);

if (!$venta_token) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token no válido para esta venta']);
    exit;
}

if (!empty($venta_token['factura_token_expira'])
    && strtotime($venta_token['factura_token_expira']) < time()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'El token ha expirado']);
    exit;
}

if (!empty($venta_token['factura_uuid'])) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Esta venta ya fue facturada previamente',
        'uuid'    => $venta_token['factura_uuid']
    ]);
    exit;
}

// ------------------------------------------------------------
// RECIBIR DATOS DEL FORMULARIO PÚBLICO
// ------------------------------------------------------------
$cliente_nombre  = trim($_POST['cliente_nombre'] ?? '');
$cliente_rfc     = trim($_POST['cliente_rfc'] ?? '');
$cliente_email   = trim($_POST['cliente_email'] ?? '');
$cliente_regimen = trim($_POST['cliente_regimen'] ?? '');
$cliente_zip     = trim($_POST['cliente_zip'] ?? '');
$cliente_estado  = trim($_POST['cliente_estado'] ?? '');
$cliente_ciudad  = trim($_POST['cliente_ciudad'] ?? '');
$metodo_pago     = $_POST['metodo_pago'] ?? 'PUE';
$uso_cfdi        = $_POST['uso_cfdi'] ?? 'G01';

if (!$cliente_nombre || !$cliente_rfc || !$cliente_email || !$cliente_regimen || !$cliente_zip) {
    echo json_encode(['success' => false, 'message' => 'Faltan datos obligatorios']);
    exit;
}

$cliente_rfc_limpio = limpiarRFC($cliente_rfc);
if (strlen($cliente_rfc_limpio) < 12) {
    echo json_encode(['success' => false, 'message' => 'RFC del cliente inválido (mínimo 12 caracteres)']);
    exit;
}

// ------------------------------------------------------------
// BLOQUE PRINCIPAL (misma lógica que facturar_venta.php)
// ------------------------------------------------------------
try {
    // 1. Conexión a base de datos principal
    $conn_main = getDBConnection();

    $sql_empresa = "SELECT plan, facturapi_organization_id, timbres_totales, timbres_disponibles 
                    FROM empresas WHERE id = :empresa_id";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([':empresa_id' => $empresa_id]);
    $empresa_data = $stmt_empresa->fetch(PDO::FETCH_ASSOC);
    $stmt_empresa->closeCursor();
    $conn_main = null;

    if (!$empresa_data || empty($empresa_data['facturapi_organization_id'])) {
        throw new Exception('La empresa no tiene una organización de Facturapi configurada');
    }
    $organization_id = $empresa_data['facturapi_organization_id'];

    // 2. Obtener API Key de prueba
    $api_key = env('FACTURAPI_API_KEY');
    if (empty($api_key)) {
        throw new Exception('No se encontró la API Key maestra de Facturapi');
    }

    $facturapi_org = new Facturapi($api_key);
    try {
        $test_api_key_obj = $facturapi_org->Organizations->getTestApiKey($organization_id);

        if (is_object($test_api_key_obj)) {
            if (isset($test_api_key_obj->key)) {
                $test_api_key = $test_api_key_obj->key;
            } elseif (isset($test_api_key_obj->api_key)) {
                $test_api_key = $test_api_key_obj->api_key;
            } elseif (isset($test_api_key_obj->secret)) {
                $test_api_key = $test_api_key_obj->secret;
            } else {
                throw new Exception('No se encontró "key", "api_key" o "secret" en el objeto');
            }
        } else {
            $test_api_key = $test_api_key_obj;
        }

        if (empty($test_api_key)) {
            throw new Exception('La API Key de prueba extraída está vacía');
        }
    } catch (Exception $e) {
        throw new Exception('No se pudo obtener la API Key de prueba: ' . $e->getMessage());
    }

    // 3. RFC del emisor
    $sql_empresa = "SELECT rfc FROM sistema_config LIMIT 1";
    $stmt_empresa = $conn->query($sql_empresa);
    $empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);
    $rfc_emisor_limpio = limpiarRFC($empresa['rfc'] ?? '');
    if (strlen($rfc_emisor_limpio) < 12) {
        throw new Exception('RFC de la empresa no configurado o inválido');
    }

    // 4. Productos
    $sql = "SELECT vd.cantidad, vd.precio_unitario, vd.descuento,
                   p.nombre as producto_nombre, p.codigo as producto_codigo
            FROM venta_detalles vd
            JOIN productos p ON vd.producto_id = p.id
            WHERE vd.venta_id = :venta_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':venta_id' => $venta_id]);
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($detalles)) {
        throw new Exception('La venta no tiene productos');
    }

    // 5. Construir items
    $items = [];
    $claves_por_descripcion = [
        'computadora' => '43211503',
        'laptop'      => '43211503',
        'monitor'     => '43211702',
        'mouse'       => '43211901',
        'teclado'     => '43211901',
        'servicio'    => '81141501',
        'soporte'     => '81141501',
        'accesorio'   => '43211800',
        'impresora'   => '43211903',
        'disco'       => '43211904',
        'memoria'     => '43211905'
    ];

    foreach ($detalles as $row) {
        $codigo    = $row['producto_codigo'] ?? '';
        $nombre    = $row['producto_nombre'];
        $precio    = (float)$row['precio_unitario'];
        $cantidad  = (int)$row['cantidad'];
        $descuento = (float)$row['descuento'];

        if (esClaveSATValida($codigo)) {
            $product_key = $codigo;
        } else {
            $product_key = '43211503';
            $nombre_lower = strtolower($nombre);
            foreach ($claves_por_descripcion as $palabra => $clave) {
                if (strpos($nombre_lower, $palabra) !== false) {
                    $product_key = $clave;
                    break;
                }
            }
        }

        $item = [
            'quantity' => $cantidad,
            'product'  => [
                'description' => $nombre,
                'product_key' => $product_key,
                'price'       => $precio
            ]
        ];
        if ($descuento > 0) {
            $item['discount'] = $descuento;
        }
        $items[] = $item;
    }

    // 6. Crear factura
    $facturapi = new Facturapi($test_api_key);

    $invoiceData = [
        'customer' => [
            'legal_name' => $cliente_nombre,
            'email'      => $cliente_email,
            'tax_id'     => $cliente_rfc_limpio,
            'tax_system' => $cliente_regimen,
            'address'    => [
                'zip'   => $cliente_zip,
                'state' => $cliente_estado,
                'city'  => $cliente_ciudad
            ]
        ],
        'items'        => $items,
        'payment_form' => ($metodo_pago === 'PPD') ? '31' : '28',
        'use'          => $uso_cfdi
    ];

    error_log("Facturapi PUBLIC request for venta $venta_id: " . json_encode($invoiceData));

    $invoice = $facturapi->Invoices->create($invoiceData);

    $uuid   = $invoice->uuid ?? $invoice->id ?? null;
    $folio  = $invoice->folio_number ?? $invoice->folio ?? null;
    $status = $invoice->status ?? null;
    $total  = $invoice->total ?? 0;

    if (!$uuid) {
        throw new Exception('No se obtuvo UUID de la factura. Respuesta: ' . json_encode($invoice));
    }

    // 7. Guardar UUID/folio y anular token
    $updateSql = "UPDATE ventas 
                  SET factura_uuid = :uuid, 
                      factura_folio = :folio,
                      factura_token = NULL,
                      factura_token_expira = NULL
                  WHERE id = :venta_id";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([
        ':uuid'     => $uuid,
        ':folio'    => $folio,
        ':venta_id' => $venta_id
    ]);

    // 8. Enviar factura por correo
    $emailSent  = false;
    $emailError = null;
    if (!empty($cliente_email)) {
        try {
            $emailResponse = $facturapi->Invoices->send_by_email($invoice->id, $cliente_email);

            if (isset($emailResponse->ok) && $emailResponse->ok === true) {
                $emailSent = true;
            } else {
                throw new Exception($emailResponse->message ?? 'Error desconocido al enviar el correo');
            }
        } catch (Facturapi_Exception $e) {
            $emailError = 'Facturapi Exception: ' . $e->getMessage();
            error_log("Error al enviar factura por email (público): " . $emailError);
        } catch (Exception $e) {
            $emailError = $e->getMessage();
            error_log("Error al enviar factura por email (público): " . $emailError);
        }
    }

    $esExito = ($uuid && ($status === 'valid' || $status === 'active' || $status === 'draft'));
    if ($esExito) {
        $mensaje = "Factura creada y timbrada exitosamente. Estado: " . $status;
    } else {
        $mensaje = "Factura creada en estado: " . ($status ?? 'desconocido');
    }

    if ($emailSent) {
        $mensaje .= " La factura fue enviada por correo a $cliente_email.";
    } elseif ($emailError) {
        $mensaje .= " No se pudo enviar la factura por correo: $emailError.";
    }

    echo json_encode([
        'success'    => true,
        'uuid'       => $uuid,
        'folio'      => $folio,
        'status'     => $status,
        'total'      => $total,
        'message'    => $mensaje,
        'email_sent' => $emailSent
    ]);

} catch (Facturapi_Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => extraerMensajeLegibleFacturapi($e->getMessage()),
        'file'    => $e->getFile(),
        'line'    => $e->getLine()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => extraerMensajeLegibleFacturapi($e->getMessage()),
        'file'    => $e->getFile(),
        'line'    => $e->getLine()
    ]);
}