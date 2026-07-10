<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'] ?? '';

if (empty($dbname)) {
    echo json_encode(['success' => false, 'message' => 'Error de configuración de base de datos']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$sucursal_id = intval($_POST['sucursal_id'] ?? $_SESSION['sucursal_id']);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
    
    // Obtener stock actualizado de todos los productos
    $sql_stock = "
        SELECT 
            p.id,
            p.nombre,
            p.codigo,
            COALESCE(ps.stock, 0) as stock_sucursal
        FROM productos p
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
        WHERE p.activo = 1
        ORDER BY p.nombre
    ";
    
    $stmt = $conn->prepare($sql_stock);
    $stmt->bind_param("i", $sucursal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stock_actualizado = [];
    while ($row = $result->fetch_assoc()) {
        $stock_actualizado[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'stock_actualizado' => $stock_actualizado
    ]);
    
} catch (Exception $e) {
    error_log("Error en obtener_stock_actualizado.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del sistema. Contacte al administrador.']);
}
?>