<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $producto_id = isset($_GET['producto_id']) ? intval($_GET['producto_id']) : null;
    
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            throw new Exception("Error de conexión: " . $conn->connect_error);
        }
        
        $sql = "
            SELECT 
                mi.*,
                p.nombre as producto_nombre,
                s.nombre as sucursal_nombre,
                u.nombre as usuario_nombre,
                DATE_FORMAT(mi.fecha, '%d/%m/%Y %H:%i') as fecha_formateada
            FROM movimientos_inventario mi
            LEFT JOIN productos p ON mi.producto_id = p.id
            LEFT JOIN sucursales s ON mi.sucursal_id = s.id  
            LEFT JOIN usuarios u ON mi.usuario_id = u.id
            WHERE 1=1
        ";
        
        $params = [];
        $types = "";
        
        if ($producto_id) {
            $sql .= " AND mi.producto_id = ?";
            $params[] = $producto_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY mi.fecha DESC LIMIT 100";
        
        $stmt = $conn->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $movimientos = [];
        while ($row = $result->fetch_assoc()) {
            $movimientos[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $movimientos
        ]);
        
        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}
?>