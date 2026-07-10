<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();
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

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener filtros de la URL
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$filtro_activo = isset($_GET['activo']) ? $_GET['activo'] : '';

// Construir consulta con filtros
$sql_where = "WHERE 1=1";
$params = [];
$types = "";

if (!empty($filtro_estado) && $filtro_estado !== 'todos') {
    $sql_where .= " AND estado_verificacion = ?";
    $params[] = $filtro_estado;
    $types .= "s";
}

if (!empty($filtro_busqueda)) {
    $sql_where .= " AND (nombre_distribuidor LIKE ? OR email LIKE ? OR rfc LIKE ? OR telefono LIKE ? OR numero_control LIKE ?)";
    $busqueda_param = "%" . $filtro_busqueda . "%";
    for ($i = 0; $i < 5; $i++) {
        $params[] = $busqueda_param;
    }
    $types .= "sssss";
}

if (!empty($filtro_activo) && $filtro_activo !== 'todos') {
    if ($filtro_activo === 'activos') {
        $sql_where .= " AND activo = 1";
    } elseif ($filtro_activo === 'inactivos') {
        $sql_where .= " AND activo = 0";
    }
}

// Consulta para exportar
$sql = "SELECT 
            numero_control,
            nombre_distribuidor,
            telefono,
            email,
            rfc,
            banco,
            numero_cuenta,
            CASE 
                WHEN estado_verificacion = 'pendiente' THEN 'Pendiente'
                WHEN estado_verificacion = 'en_revision' THEN 'En Revisión'
                WHEN estado_verificacion = 'aprobado' THEN 'Aprobado'
                WHEN estado_verificacion = 'rechazado' THEN 'Rechazado'
                ELSE estado_verificacion
            END as estado_verificacion,
            CASE WHEN activo = 1 THEN 'Activo' ELSE 'Inactivo' END as estado,
            DATE_FORMAT(fecha_registro, '%d/%m/%Y %H:%i') as fecha_registro,
            DATE_FORMAT(fecha_verificacion, '%d/%m/%Y %H:%i') as fecha_verificacion,
            observaciones_verificacion
        FROM distribuidores
        $sql_where 
        ORDER BY fecha_registro DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Configurar headers para descarga de Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="distribuidores_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Abrir output
$output = fopen('php://output', 'w');

// Definir codificación UTF-8 para caracteres especiales
echo "\xEF\xBB\xBF"; // BOM para UTF-8

// Escribir encabezados
fputcsv($output, array(
    'Número Control',
    'Nombre Distribuidor',
    'Teléfono',
    'Email',
    'RFC',
    'Banco',
    'Número Cuenta',
    'Estado Verificación',
    'Estado',
    'Fecha Registro',
    'Fecha Verificación',
    'Observaciones'
), "\t");

// Escribir datos
while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row, "\t");
}

fclose($output);
$stmt->close();
$conn->close();
exit();
?>