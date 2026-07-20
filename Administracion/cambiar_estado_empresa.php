<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Configuración de conexión
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Obtener datos
$id_empresa = isset($_GET['id']) ? intval($_GET['id']) : 0;
$accion = isset($_GET['accion']) ? $_GET['accion'] : '';

if ($id_empresa <= 0 || !in_array($accion, ['activar', 'desactivar'])) {
    header("Location: empresas.php?mensaje=Parámetros inválidos&tipo=danger");
    exit();
}

// Conectar a la base de datos
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    header("Location: empresas.php?mensaje=Error de conexión&tipo=danger");
    exit();
}

// Determinar nuevo estado
$nuevo_estado = ($accion == 'activar') ? 1 : 0;
$mensaje_estado = ($accion == 'activar') ? 'activada' : 'desactivada';

try {
    // Actualizar solo el campo activo
    $sql = "UPDATE empresas SET activo = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $nuevo_estado, $id_empresa);
    
    if ($stmt->execute()) {
        $mensaje = "Empresa $mensaje_estado correctamente";
        $tipo_mensaje = "success";
    } else {
        $mensaje = "Error al $mensaje_estado la empresa";
        $tipo_mensaje = "danger";
    }
    
    $stmt->close();
    $conn->close();
    
    // Redireccionar de vuelta
    header("Location: gestionar_empresa.php?id=$id_empresa&mensaje=" . urlencode($mensaje) . "&tipo=$tipo_mensaje");
    exit();
    
} catch (Exception $e) {
    $conn->close();
    header("Location: empresas.php?mensaje=Error: " . urlencode($e->getMessage()) . "&tipo=danger");
    exit();
}
?>