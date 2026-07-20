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

$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit();
}

$producto_id = isset($_GET['producto_id']) ? intval($_GET['producto_id']) : 0;

if ($producto_id <= 0) {
    echo json_encode(['success' => true, 'precios' => []]);
    exit();
}

$sql = "SELECT id, cantidad_minima, precio_especial FROM producto_precios_mayoreo WHERE producto_id = ? AND activo = 1 ORDER BY cantidad_minima ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $producto_id);
$stmt->execute();
$result = $stmt->get_result();

$precios = [];
while ($row = $result->fetch_assoc()) {
    $precios[] = [
        'id' => $row['id'],
        'cantidad_minima' => floatval($row['cantidad_minima']),
        'precio_especial' => floatval($row['precio_especial'])
    ];
}

$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'precios' => $precios]);
?>