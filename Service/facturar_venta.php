<?php
// facturar_venta.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
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

// ------------------------------------------------------------
// VERIFICAR AUTENTICACIÓN
// ------------------------------------------------------------
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// ------------------------------------------------------------
// RECIBIR DATOS
// ------------------------------------------------------------
$venta_id = $_POST['venta_id'] ?? 0;
$cliente_nombre = trim($_POST['cliente_nombre'] ?? '');
$cliente_rfc = trim($_POST['cliente_rfc'] ?? '');
$cliente_email = trim($_POST['cliente_email'] ?? '');
$cliente_regimen = trim($_POST['cliente_regimen'] ?? '');
$cliente_zip = trim($_POST['cliente_zip'] ?? '');
$cliente_estado = trim($_POST['cliente_estado'] ?? '');
$cliente_ciudad = trim($_POST['cliente_ciudad'] ?? '');
$metodo_pago = $_POST['metodo_pago'] ?? 'PUE';
$uso_cfdi = $_POST['uso_cfdi'] ?? 'G01';

if (!$venta_id || !$cliente_nombre || !$cliente_rfc || !$cliente_email || !$cliente_regimen || !$cliente_zip) {
    echo json_encode(['success' => false, 'message' => 'Faltan datos obligatorios']);
    exit;
}

$cliente_rfc_limpio = limpiarRFC($cliente_rfc);
if (strlen($cliente_rfc_limpio) < 12) {
    echo json_encode(['success' => false, 'message' => 'RFC del cliente inválido (mínimo 12 caracteres)']);
    exit;
}

// ------------------------------------------------------------
// BLOQUE PRINCIPAL
// ------------------------------------------------------------
try {
    // 1. Conexión a base de datos principal
    $conn_main = getDBConnection();

    $sql_empresa = "SELECT plan, facturapi_organization_id, timbres_totales, timbres_disponibles 
                    FROM empresas WHERE id = :empresa_id";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([':empresa_id' => $_SESSION['empresa_id']]);
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
        throw new Exception('No se encontró la API Key maestra de Facturapi en las variables de entorno');
    }

    $facturapi_org = new Facturapi($api_key);
    try {
        $test_api_key_obj = $facturapi_org->Organizations->getTestApiKey($organization_id);
        error_log('Estructura de test_api_key_obj: ' . print_r($test_api_key_obj, true));
        
        if (is_object($test_api_key_obj)) {
            if (isset($test_api_key_obj->key)) {
                $test_api_key = $test_api_key_obj->key;
            } elseif (isset($test_api_key_obj->api_key)) {
                $test_api_key = $test_api_key_obj->api_key;
            } elseif (isset($test_api_key_obj->secret)) {
                $test_api_key = $test_api_key_obj->secret;
            } else {
                $json = json_encode($test_api_key_obj);
                throw new Exception('No se encontró una propiedad "key", "api_key" o "secret" en el objeto. Contenido: ' . $json);
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

    // 3. Conexión a base de datos de la empresa
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    $sql_empresa = "SELECT rfc FROM sistema_config LIMIT 1";
    $stmt_empresa = $conn->query($sql_empresa);
    $empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);
    $rfc_emisor_limpio = limpiarRFC($empresa['rfc'] ?? '');
    if (strlen($rfc_emisor_limpio) < 12) {
        throw new Exception('RFC de la empresa no configurado o inválido');
    }

    // Obtener productos
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

    // 4. Construir items
    $items = [];
    $claves_por_descripcion = [
        'computadora' => '43211503',
        'laptop' => '43211503',
        'monitor' => '43211702',
        'mouse' => '43211901',
        'teclado' => '43211901',
        'servicio' => '81141501',
        'soporte' => '81141501',
        'accesorio' => '43211800',
        'impresora' => '43211903',
        'disco' => '43211904',
        'memoria' => '43211905'
    ];

    foreach ($detalles as $row) {
        $codigo = $row['producto_codigo'] ?? '';
        $nombre = $row['producto_nombre'];
        $precio = (float)$row['precio_unitario'];
        $cantidad = (int)$row['cantidad'];
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
            'product' => [
                'description' => $nombre,
                'product_key' => $product_key,
                'price' => $precio
            ]
        ];
        if ($descuento > 0) {
            $item['discount'] = $descuento;
        }
        $items[] = $item;
    }

    // 5. Crear factura usando la librería
    $facturapi = new Facturapi($test_api_key);

    $invoiceData = [
        'customer' => [
            'legal_name' => $cliente_nombre,
            'email' => $cliente_email,
            'tax_id' => $cliente_rfc_limpio,
            'tax_system' => $cliente_regimen,
            'address' => [
                'zip' => $cliente_zip,
                'state' => $cliente_estado,
                'city' => $cliente_ciudad
            ]
        ],
        'items' => $items,
        'payment_form' => ($metodo_pago === 'PPD') ? '31' : '28',
        'use' => $uso_cfdi
    ];

    error_log("Facturapi request for venta $venta_id: " . json_encode($invoiceData));

    // Llamada a la API
    $invoice = $facturapi->Invoices->create($invoiceData);

    // El objeto $invoice usa 'id', no '_id'
    $uuid = $invoice->uuid ?? $invoice->id ?? null;
    $folio = $invoice->folio_number ?? $invoice->folio ?? null;
    $status = $invoice->status ?? null;
    $total = $invoice->total ?? 0;

    $esExito = ($uuid && ($status === 'valid' || $status === 'active' || $status === 'draft'));

    if (!$uuid) {
        throw new Exception('No se obtuvo UUID de la factura. Respuesta: ' . json_encode($invoice));
    }

    // Guardar UUID y folio en la venta
    $updateSql = "UPDATE ventas SET factura_uuid = :uuid, factura_folio = :folio WHERE id = :venta_id";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([
        ':uuid' => $uuid,
        ':folio' => $folio,
        ':venta_id' => $venta_id
    ]);

    // ================================================================
    // 6. ENVIAR FACTURA POR CORREO usando el método de la librería
    // ================================================================
    $emailSent = false;
    $emailError = null;
    if (!empty($cliente_email)) {
        try {
            // Usamos el método send_by_email de la librería
            // El segundo parámetro puede ser un string o un array de emails
            $emailResponse = $facturapi->Invoices->send_by_email($invoice->id, $cliente_email);

            // Verificar si la respuesta indica éxito
            if (isset($emailResponse->ok) && $emailResponse->ok === true) {
                $emailSent = true;
            } else {
                $errorMsg = $emailResponse->message ?? 'Error desconocido al enviar el correo';
                throw new Exception($errorMsg);
            }
        } catch (Facturapi_Exception $e) {
            $emailError = 'Facturapi Exception: ' . $e->getMessage();
            error_log("Error al enviar factura por email: " . $emailError);
        } catch (Exception $e) {
            $emailError = $e->getMessage();
            error_log("Error al enviar factura por email: " . $emailError);
        }
    } else {
        $emailError = "El cliente no tiene correo electrónico registrado.";
    }

    // Mensaje final
    if ($esExito) {
        $mensaje = "Factura creada y timbrada exitosamente. Estado: " . $status;
    } else {
        $mensaje = "Factura creada en estado: " . ($status ?? 'desconocido') . ". No se timbró automáticamente.";
    }

    if ($emailSent) {
        $mensaje .= " La factura fue enviada por correo a $cliente_email.";
    } elseif ($emailError) {
        $mensaje .= " No se pudo enviar la factura por correo: $emailError.";
    }

    echo json_encode([
        'success' => true,
        'uuid' => $uuid,
        'folio' => $folio,
        'status' => $status,
        'total' => $total,
        'message' => $mensaje,
        'email_sent' => $emailSent
    ]);

} catch (Facturapi_Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de Facturapi: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}