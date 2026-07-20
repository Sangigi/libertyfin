<?php
session_start();
header('Content-Type: application/json');

// Verificar sesión
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit();
}

$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'total';

// Obtener stock mínimo global
$stock_minimo = 5;
$sql_config = "SELECT stock_minimo_global FROM sistema_config LIMIT 1";
$result_config = $conn->query($sql_config);
if ($result_config && $result_config->num_rows > 0) {
    $row_config = $result_config->fetch_assoc();
    $stock_minimo = $row_config['stock_minimo_global'] ?? 5;
}

$sql = "";
switch($tipo) {
    case 'total':
        $sql = "SELECT id, codigo, nombre, stock, unidad_medida 
                FROM productos 
                WHERE activo = 1 
                ORDER BY nombre ASC";
        break;
    case 'con_stock':
        $sql = "SELECT id, codigo, nombre, stock, unidad_medida 
                FROM productos 
                WHERE activo = 1 AND stock > 0 
                ORDER BY nombre ASC";
        break;
    case 'bajo_stock':
        $sql = "SELECT id, codigo, nombre, stock, unidad_medida 
                FROM productos 
                WHERE activo = 1 AND stock > 0 AND stock <= ? 
                ORDER BY stock ASC, nombre ASC";
        break;
    case 'sin_stock':
        $sql = "SELECT id, codigo, nombre, stock, unidad_medida 
                FROM productos 
                WHERE activo = 1 AND stock = 0 
                ORDER BY nombre ASC";
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Tipo no válido']);
        $conn->close();
        exit();
}

$productos = [];

if ($tipo == 'bajo_stock') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $stock_minimo);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $productos[] = [
            'id' => $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'stock_total' => floatval($row['stock']),
            'unidad_medida' => $row['unidad_medida'] ?? 'pieza'
        ];
    }
}

echo json_encode([
    'success' => true,
    'productos' => $productos,
    'total' => count($productos),
    'stock_minimo' => $stock_minimo
]);

$conn->close();
?>