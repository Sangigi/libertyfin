<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Conectar a la base de datos
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    $producto_id = intval($_POST['producto_id']);
    $sucursal_id = intval($_POST['sucursal_id']);

    // Obtener stock de la sucursal específica
    $sql = "SELECT stock, stock_minimo FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $producto_id, $sucursal_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'stock' => $row['stock'],
            'stock_minimo' => $row['stock_minimo']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'stock' => 0,
            'stock_minimo' => 0
        ]);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>