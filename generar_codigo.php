<?php
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_secure', 1);   // ← cambiar a 1, tu sitio es HTTPS
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

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

header('Content-Type: application/json');

try {
    // Conectar a la base de datos
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
    
    // Función para generar código automático
    function generarCodigoAutomatico($conn, $prefijo = 'PROD') {
        // Buscar el último código con el prefijo
        $sql = "SELECT MAX(CAST(SUBSTRING(codigo, LENGTH(?) + 1) AS UNSIGNED)) as ultimo_num 
                FROM productos 
                WHERE codigo LIKE CONCAT(?, '%') 
                AND codigo REGEXP '^' || ? || '[0-9]+$'";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error en preparación: " . $conn->error);
        }
        
        $stmt->bind_param("sss", $prefijo, $prefijo, $prefijo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        $ultimo_num = $row['ultimo_num'] ? intval($row['ultimo_num']) : 0;
        $nuevo_num = $ultimo_num + 1;
        
        // Formatear con ceros a la izquierda
        $codigo = sprintf('%s%04d', $prefijo, $nuevo_num);
        
        // Verificar que no exista
        $sql_check = "SELECT COUNT(*) as existe FROM productos WHERE codigo = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("s", $codigo);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $row_check = $result_check->fetch_assoc();
        $stmt_check->close();
        
        if ($row_check['existe'] > 0) {
            // Si existe, intentar con el siguiente número
            $nuevo_num++;
            $codigo = sprintf('%s%04d', $prefijo, $nuevo_num);
        }
        
        return $codigo;
    }
    
    // Generar el código
    $codigo = generarCodigoAutomatico($conn);
    
    echo json_encode([
        'success' => true,
        'codigo' => $codigo,
        'message' => 'Código generado exitosamente'
    ]);
    
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>