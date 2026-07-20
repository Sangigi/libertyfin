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

$empresa_id = $_POST['empresa_id'] ?? 0;
$cantidad_timbres = $_POST['cantidad_timbres'] ?? 0;

// Obtener información actual de la empresa
$sql = "SELECT timbres_disponibles, timbres_totales, plan, facturapi_organization_id FROM empresas WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
$empresa = $result->fetch_assoc();

// Precios de timbres
$precios_timbres = [
    50 => ['sin_iva' => 100, 'con_iva' => 116],
    100 => ['sin_iva' => 150, 'con_iva' => 174],
    200 => ['sin_iva' => 200, 'con_iva' => 232],
    300 => ['sin_iva' => 250, 'con_iva' => 290],
    500 => ['sin_iva' => 300, 'con_iva' => 348]
];

$precio_sin_iva = $precios_timbres[$cantidad_timbres]['sin_iva'] ?? 0;
$precio_con_iva = $precios_timbres[$cantidad_timbres]['con_iva'] ?? 0;

$timbres_anteriores = $empresa['timbres_disponibles'];
$timbres_nuevos = $timbres_anteriores + $cantidad_timbres;
$timbres_totales_nuevos = $empresa['timbres_totales'] + $cantidad_timbres;

// Actualizar timbres
$sql_update = "UPDATE empresas SET 
                timbres_disponibles = ?,
                timbres_totales = ?,
                fecha_activacion_timbres = NOW()
                WHERE id = ?";

$stmt_update = $conn->prepare($sql_update);
$stmt_update->bind_param("iii", $timbres_nuevos, $timbres_totales_nuevos, $empresa_id);

if ($stmt_update->execute()) {
    // Registrar la activación en la tabla de activaciones_timbres
    $sql_insert = "INSERT INTO activaciones_timbres 
                   (empresa_id, cantidad_timbres, precio_sin_iva, precio_con_iva, 
                    timbres_anteriores, timbres_nuevos, fecha_activacion, usuario_activo) 
                   VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)";
    
    $stmt_insert = $conn->prepare($sql_insert);
    $usuario = $_SESSION['usuario_nombre'] ?? 'admin';
    $stmt_insert->bind_param("iiddiis", $empresa_id, $cantidad_timbres, $precio_sin_iva, $precio_con_iva, 
                             $timbres_anteriores, $timbres_nuevos, $usuario);
    $stmt_insert->execute();
    $stmt_insert->close();

    header("Location: gestionar_empresa.php?id=" . $empresa_id . "&mensaje=success&timbres=" . $cantidad_timbres);
} else {
    header("Location: gestionar_empresa.php?id=" . $empresa_id . "&mensaje=error");
}

$conn->close();
?>