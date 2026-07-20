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

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Primero, asegurarse de que la tabla empresas tenga el campo sucursales_extra
$sql_check_column = "SHOW COLUMNS FROM empresas LIKE 'sucursales_extra'";
$result_check = $conn->query($sql_check_column);
if ($result_check->num_rows == 0) {
    $sql_add_column = "ALTER TABLE empresas ADD COLUMN sucursales_extra INT DEFAULT 0";
    $conn->query($sql_add_column);
}

$empresa_id = $_POST['empresa_id'] ?? 0;
$num_sucursales = $_POST['num_sucursales'] ?? 1;
$precio_unitario = 499;
$precio_sin_iva = $num_sucursales * $precio_unitario;
$precio_con_iva = $precio_sin_iva * 1.16;

// Obtener sucursales actuales
$sql_select = "SELECT sucursales_extra FROM empresas WHERE id = ?";
$stmt_select = $conn->prepare($sql_select);
$stmt_select->bind_param("i", $empresa_id);
$stmt_select->execute();
$result = $stmt_select->get_result();
$empresa = $result->fetch_assoc();

$sucursales_anteriores = $empresa['sucursales_extra'] ?? 0;
$sucursales_nuevas = $sucursales_anteriores + $num_sucursales;

// Actualizar sucursales
$sql_update = "UPDATE empresas SET sucursales_extra = ? WHERE id = ?";
$stmt_update = $conn->prepare($sql_update);
$stmt_update->bind_param("ii", $sucursales_nuevas, $empresa_id);

if ($stmt_update->execute()) {
    // Registrar la activación
    $sql_insert = "INSERT INTO activaciones_sucursales 
                   (empresa_id, sucursales_anteriores, sucursales_nuevas, 
                    precio_sin_iva, precio_con_iva, fecha_activacion, usuario_activo) 
                   VALUES (?, ?, ?, ?, ?, NOW(), ?)";
    
    $stmt_insert = $conn->prepare($sql_insert);
    $usuario = $_SESSION['usuario_nombre'] ?? 'admin';
    $stmt_insert->bind_param("iiddds", $empresa_id, $sucursales_anteriores, $num_sucursales,
                             $precio_sin_iva, $precio_con_iva, $usuario);
    $stmt_insert->execute();
    $stmt_insert->close();

    header("Location: gestionar_empresa.php?id=" . $empresa_id . "&mensaje=sucursales_success&cantidad=" . $num_sucursales);
} else {
    header("Location: gestionar_empresa.php?id=" . $empresa_id . "&mensaje=error");
}

$conn->close();
?>