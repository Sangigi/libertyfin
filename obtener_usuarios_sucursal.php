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

    // Obtener ID de la sucursal
    $sucursal_id = intval($_GET['sucursal_id'] ?? 0);
    
    if ($sucursal_id <= 0) {
        throw new Exception("ID de sucursal inválido");
    }

    // Obtener usuarios de la sucursal específica
    $sql_usuarios = "
        SELECT id, nombre, email, rol, sucursal_id 
        FROM usuarios 
        WHERE sucursal_id = ? AND activo = 1 
        ORDER BY nombre ASC
    ";
    $stmt_usuarios = $conn->prepare($sql_usuarios);
    $stmt_usuarios->bind_param("i", $sucursal_id);
    $stmt_usuarios->execute();
    $result_usuarios = $stmt_usuarios->get_result();
    
    $usuarios = [];
    while ($row = $result_usuarios->fetch_assoc()) {
        $usuarios[] = [
            'id' => $row['id'],
            'nombre' => $row['nombre'],
            'email' => $row['email'],
            'rol' => $row['rol'],
            'sucursal_id' => $row['sucursal_id']
        ];
    }
    $stmt_usuarios->close();

    // Devolver respuesta JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'usuarios' => $usuarios,
        'total' => count($usuarios)
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>