<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Verificar si es administrador
if ($_SESSION['usuario_rol'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'No tienes permisos para realizar esta acción']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Conectar a la base de datos de la empresa
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Procesar la solicitud
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear':
                crearSucursal($conn);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Acción no válida']);
                break;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Solicitud no válida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function crearSucursal($conn) {
    $nombre = trim($conn->real_escape_string($_POST['nombre']));
    
    if (empty($nombre)) {
        echo json_encode(['success' => false, 'message' => 'El nombre de la sucursal es requerido']);
        return;
    }

    try {
        // Verificar si ya existe una sucursal con el mismo nombre
        $sql_check = "SELECT id FROM sucursales WHERE nombre = ? AND activo = 1";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("s", $nombre);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Ya existe una sucursal con ese nombre']);
            $stmt_check->close();
            return;
        }
        $stmt_check->close();

        // Insertar nueva sucursal
        $sql = "INSERT INTO sucursales (nombre, activo) VALUES (?, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $nombre);

        if ($stmt->execute()) {
            $sucursal_id = $conn->insert_id;
            echo json_encode([
                'success' => true, 
                'message' => 'Sucursal creada exitosamente',
                'sucursal_id' => $sucursal_id,
                'nombre' => $nombre
            ]);
        } else {
            throw new Exception("Error al crear sucursal: " . $stmt->error);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>