<?php
session_start();

$db_config = [
    'host' => 'libertyfin.com.mx',
    'user' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'database' => 'juanc141_ventas'
];

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: login-distribuidor.php");
    exit;
}

$numero_control = isset($_POST['numero_control']) ? trim($_POST['numero_control']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (empty($numero_control) || empty($password)) {
    $_SESSION['error_login'] = "Por favor, ingrese su número de control y contraseña.";
    header("Location: login-distribuidor.php");
    exit;
}

$conn = new mysqli(
    $db_config['host'],
    $db_config['user'],
    $db_config['password'],
    $db_config['database']
);

if ($conn->connect_error) {
    $_SESSION['error_login'] = "Error de conexión. Intente más tarde.";
    header("Location: login-distribuidor.php");
    exit;
}

// Buscar distribuidor por número de control
$sql = "SELECT id, nombre_distribuidor, email, password, estado_verificacion, activo 
        FROM distribuidores 
        WHERE numero_control = ? AND activo = TRUE";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $numero_control);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_login'] = "Número de control o contraseña incorrectos.";
    header("Location: login-distribuidor.php");
    exit;
}

$distribuidor = $result->fetch_assoc();

// Verificar contraseña
if (password_verify($password, $distribuidor['password'])) {
    // Login exitoso
    $_SESSION['distribuidor_id'] = $distribuidor['id'];
    $_SESSION['distribuidor_nombre'] = $distribuidor['nombre_distribuidor'];
    $_SESSION['distribuidor_email'] = $distribuidor['email'];
    $_SESSION['distribuidor_control'] = $numero_control;
    $_SESSION['distribuidor_estado'] = $distribuidor['estado_verificacion'];
    
    // Redirigir al panel de distribuidor
    header("Location: panel-distribuidor.php");
    exit;
} else {
    $_SESSION['error_login'] = "Número de control o contraseña incorrectos.";
    header("Location: login-distribuidor.php");
    exit;
}

$conn->close();
?>