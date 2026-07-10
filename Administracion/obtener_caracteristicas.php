<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

header('Content-Type: application/json');

// Configuración de la base de datos principal
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname_main = "juanc141_ventas";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

$empresa_id = isset($_POST['empresa_id']) ? intval($_POST['empresa_id']) : 0;

if ($empresa_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de empresa inválido']);
    exit();
}

$conn = new mysqli($servername, $username, $password, $dbname_main);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]);
    exit();
}

// Obtener características
$caracteristicas = [
    'precio_compra' => 1,
    'unidad_medida' => 1,
    'proveedor' => 1,
    'fecha_caducidad' => 1,
    'categoria' => 1
];

$tipos_unidad = ['pieza', 'kilo', 'litro'];

$sql = "SELECT caracteristica, habilitado, configuracion_extra 
        FROM empresa_caracteristicas 
        WHERE empresa_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $empresa_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $caracteristicas[$row['caracteristica']] = $row['habilitado'];
    
    if ($row['caracteristica'] === 'unidad_medida' && !empty($row['configuracion_extra'])) {
        $tipos_unidad = json_decode($row['configuracion_extra'], true);
        if (!is_array($tipos_unidad) || empty($tipos_unidad)) {
            $tipos_unidad = ['pieza'];
        }
    }
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'data' => [
        'precio_compra' => $caracteristicas['precio_compra'],
        'unidad_medida' => $caracteristicas['unidad_medida'],
        'proveedor' => $caracteristicas['proveedor'],
        'fecha_caducidad' => $caracteristicas['fecha_caducidad'],
        'categoria' => $caracteristicas['categoria'],
        'tipos_unidad' => $tipos_unidad
    ]
]);
?>