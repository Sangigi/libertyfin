<?php
session_start();
header('Content-Type: application/json');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

$producto_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($producto_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de producto inválido']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]);
    exit();
}

$tiene_dependencias = false;
$dependencias = [];

// 1. Verificar en venta_detalles
$sql_ventas = "SELECT COUNT(*) as total FROM venta_detalles WHERE producto_id = ?";
$stmt = $conn->prepare($sql_ventas);
$stmt->bind_param("i", $producto_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$ventas = intval($row['total']);
if ($ventas > 0) {
    $tiene_dependencias = true;
    $dependencias['ventas'] = $ventas;
}
$stmt->close();

// 2. Verificar en compra_detalles
$sql_compras = "SELECT COUNT(*) as total FROM compra_detalles WHERE producto_id = ?";
$stmt = $conn->prepare($sql_compras);
$stmt->bind_param("i", $producto_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$compras = intval($row['total']);
if ($compras > 0) {
    $tiene_dependencias = true;
    $dependencias['compras'] = $compras;
}
$stmt->close();

// 3. Verificar en movimientos_inventario
$sql_movimientos = "SELECT COUNT(*) as total FROM movimientos_inventario WHERE producto_id = ?";
$stmt = $conn->prepare($sql_movimientos);
$stmt->bind_param("i", $producto_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$movimientos = intval($row['total']);
if ($movimientos > 0) {
    $tiene_dependencias = true;
    $dependencias['movimientos'] = $movimientos;
}
$stmt->close();

$conn->close();

echo json_encode([
    'success' => true,
    'tiene_dependencias' => $tiene_dependencias,
    'ventas' => $dependencias['ventas'] ?? 0,
    'compras' => $dependencias['compras'] ?? 0,
    'movimientos' => $dependencias['movimientos'] ?? 0,
    'message' => $tiene_dependencias ? 'El producto tiene registros asociados' : 'El producto puede ser eliminado'
]);
?>