<?php
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_secure', 1);   // ← cambiar a 1, tu sitio es HTTPS
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
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

// Headers para JSON
header('Content-Type: application/json');

// Conectar a la base de datos
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Configurar charset
    $conn->set_charset("utf8");

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'crear') {
        $nombre = trim($conn->real_escape_string($_POST['nombre']));
        
        if (empty($nombre)) {
            echo json_encode(['success' => false, 'message' => 'El nombre de la categoría es requerido']);
            exit();
        }

        // Verificar si ya existe una categoría con ese nombre (activa o inactiva)
        $sql_check = "SELECT id FROM categorias WHERE nombre = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("s", $nombre);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            // Si existe pero está inactiva, la reactivamos
            $row = $result_check->fetch_assoc();
            $sql_reactivar = "UPDATE categorias SET activo = 1 WHERE id = ?";
            $stmt_reactivar = $conn->prepare($sql_reactivar);
            $stmt_reactivar->bind_param("i", $row['id']);
            
            if ($stmt_reactivar->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Categoría reactivada exitosamente',
                    'categoria_id' => $row['id'],
                    'nombre' => $nombre
                ]);
            } else {
                throw new Exception("Error al reactivar categoría: " . $stmt_reactivar->error);
            }
            $stmt_reactivar->close();
        } else {
            // Insertar nueva categoría
            $sql = "INSERT INTO categorias (nombre, descripcion, activo) VALUES (?, '', 1)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $nombre);

            if ($stmt->execute()) {
                $nueva_categoria_id = $stmt->insert_id;
                echo json_encode([
                    'success' => true, 
                    'message' => 'Categoría creada exitosamente',
                    'categoria_id' => $nueva_categoria_id,
                    'nombre' => $nombre
                ]);
            } else {
                throw new Exception("Error al crear categoría: " . $stmt->error);
            }

            $stmt->close();
        }

        $stmt_check->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }

} catch (Exception $e) {
    error_log("Error en guardar_categoria.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}

// Cerrar conexión
if (isset($conn)) {
    $conn->close();
}
?>