<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

$id_empresa = $_GET['id'] ?? 0;

if (!$id_empresa) {
    echo json_encode(['success' => false, 'message' => 'ID de empresa no proporcionado']);
    exit();
}

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit();
}

$FACTURAPI_SECRET_KEY = "sk_user_FpxnTjRAKbjBQDHiqv5sc9y75WPoQqJTwGCZS5nNKx";

// Obtener información de la empresa
$sql = "SELECT facturapi_organization_id, timbres_totales FROM empresas WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_empresa);
$stmt->execute();
$result = $stmt->get_result();
$empresa = $result->fetch_assoc();

if (!$empresa || empty($empresa['facturapi_organization_id'])) {
    echo json_encode(['success' => false, 'message' => 'Empresa no tiene organización en Facturapi']);
    exit();
}

// Función para contar facturas
function contarFacturasFacturapi($test_api_key) {
    try {
        if (empty($test_api_key)) {
            return 0;
        }

        require_once dirname(__FILE__) . '/../vendor/autoload.php';
        $facturapi = new \Facturapi\Facturapi($test_api_key);

        $total_facturas = 0;
        $page = 1;

        while (true) {
            $response = $facturapi->Invoices->all([
                'page' => $page,
                'limit' => 100
            ]);

            if (!is_object($response) || !property_exists($response, 'data') || !is_array($response->data)) {
                break;
            }

            $facturas = $response->data;

            if (count($facturas) === 0) {
                break;
            }

            foreach ($facturas as $invoice) {
                if (is_object($invoice)) {
                    $status = strtolower($invoice->status ?? '');
                    if ($status === 'valid' || $status === 'issued') {
                        $total_facturas++;
                    }
                }
            }

            $total_pages = $response->total_pages ?? 1;
            if ($page >= $total_pages) {
                break;
            }

            $page++;
        }

        return $total_facturas;
    } catch (Exception $e) {
        return 0;
    }
}

try {
    require_once dirname(__FILE__) . '/../vendor/autoload.php';
    $facturapi_system = new \Facturapi\Facturapi($FACTURAPI_SECRET_KEY);

    // Obtener API key de prueba
    $result_api_key = $facturapi_system->Organizations->getTestApiKey($empresa['facturapi_organization_id']);
    
    $test_api_key = '';
    if (is_string($result_api_key)) {
        $test_api_key = $result_api_key;
    } elseif (is_object($result_api_key) && property_exists($result_api_key, 'api_key')) {
        $test_api_key = $result_api_key->api_key;
    } elseif (is_array($result_api_key) && isset($result_api_key['api_key'])) {
        $test_api_key = $result_api_key['api_key'];
    }

    if (empty($test_api_key)) {
        echo json_encode(['success' => false, 'message' => 'No se pudo obtener la API key']);
        exit();
    }

    // Contar facturas emitidas
    $facturas_emitidas = contarFacturasFacturapi($test_api_key);
    
    // Calcular timbres disponibles
    $timbres_disponibles = max(0, $empresa['timbres_totales'] - $facturas_emitidas);

    // Actualizar en la base de datos
    $sql_update = "UPDATE empresas SET timbres_disponibles = ?, fecha_actualizacion_timbres = NOW() WHERE id = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("ii", $timbres_disponibles, $id_empresa);
    
    if ($stmt_update->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Timbres sincronizados correctamente',
            'facturas_emitidas' => $facturas_emitidas,
            'timbres_disponibles' => $timbres_disponibles
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar la base de datos']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>