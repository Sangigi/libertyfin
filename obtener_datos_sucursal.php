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
    $sucursal_id = intval($_GET['id'] ?? 0);
    
    if ($sucursal_id <= 0) {
        throw new Exception("ID de sucursal inválido");
    }

    // Obtener datos de la sucursal
    $sql_sucursal = "SELECT * FROM sucursales WHERE id = ?";
    $stmt_sucursal = $conn->prepare($sql_sucursal);
    $stmt_sucursal->bind_param("i", $sucursal_id);
    $stmt_sucursal->execute();
    $result_sucursal = $stmt_sucursal->get_result();
    
    if ($result_sucursal->num_rows === 0) {
        throw new Exception("Sucursal no encontrada");
    }
    
    $sucursal = $result_sucursal->fetch_assoc();
    $stmt_sucursal->close();

    // Obtener estadísticas de ventas
    $sql_ventas = "
        SELECT 
            COUNT(*) as total_ventas,
            COALESCE(SUM(total), 0) as monto_total,
            COUNT(DISTINCT usuario_id) as total_usuarios
        FROM ventas 
        WHERE sucursal_id = ?
    ";
    $stmt_ventas = $conn->prepare($sql_ventas);
    $stmt_ventas->bind_param("i", $sucursal_id);
    $stmt_ventas->execute();
    $result_ventas = $stmt_ventas->get_result();
    $ventas = $result_ventas->fetch_assoc() ?: [
        'total_ventas' => 0,
        'monto_total' => 0,
        'total_usuarios' => 0
    ];
    $stmt_ventas->close();

    // Obtener conteo de usuarios
    $sql_usuarios = "SELECT COUNT(*) as total_usuarios FROM usuarios WHERE sucursal_id = ?";
    $stmt_usuarios = $conn->prepare($sql_usuarios);
    $stmt_usuarios->bind_param("i", $sucursal_id);
    $stmt_usuarios->execute();
    $result_usuarios = $stmt_usuarios->get_result();
    $usuarios_count = $result_usuarios->fetch_assoc()['total_usuarios'] ?? 0;
    $stmt_usuarios->close();

    // Limpiar datos para JSON
    $sucursal_limpia = [
        'id' => $sucursal['id'],
        'nombre' => $sucursal['nombre'] ?? '',
        'direccion' => $sucursal['direccion'] ?? '',
        'telefono' => $sucursal['telefono'] ?? '',
        'email' => $sucursal['email'] ?? '',
        'responsable' => $sucursal['responsable'] ?? '',
        'activo' => (bool)$sucursal['activo'],
        'fecha_creacion' => $sucursal['fecha_creacion'] ?? '',
        'fecha_actualizacion' => $sucursal['fecha_actualizacion'] ?? ''
    ];

    // Devolver respuesta JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'sucursal' => $sucursal_limpia,
        'ventas' => $ventas,
        'usuarios' => (int)$usuarios_count
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