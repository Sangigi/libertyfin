<?php
header('Content-Type: application/json');

// Configuración de la base de datos
$host = 'libertyfin.com.mx';
$dbname = 'juanc141_ventas';
$username = 'juanc141_alexis';
$password = 'Alexis1997';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

// Obtener datos del POST
$nombre_completo = trim($_POST['nombre_completo'] ?? '');
$empresa = trim($_POST['empresa'] ?? '');
$correo = trim($_POST['correo'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$estado = trim($_POST['estado'] ?? '');
$experiencia_ventas = trim($_POST['experiencia_ventas'] ?? '');
$mensaje = trim($_POST['mensaje'] ?? '');

// Validaciones
$errors = [];

if (empty($nombre_completo)) {
    $errors[] = 'El nombre completo es obligatorio';
}
if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'El correo electrónico no es válido';
}
if (empty($telefono)) {
    $errors[] = 'El teléfono es obligatorio';
}
if (empty($estado)) {
    $errors[] = 'El estado es obligatorio';
}
if (!in_array($experiencia_ventas, ['si', 'no'])) {
    $errors[] = 'La experiencia en ventas es obligatoria';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => $errors[0], 'errors' => $errors]);
    exit;
}

// Verificar si ya existe una solicitud pendiente para este correo
$stmt = $pdo->prepare("SELECT id FROM solicitud_distribuidores WHERE correo = ? AND estado_solicitud = 'pendiente'");
$stmt->execute([$correo]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Ya tienes una solicitud pendiente. Te contactaremos pronto.']);
    exit;
}

// Insertar datos
try {
    $sql = "INSERT INTO solicitud_distribuidores (nombre_completo, empresa, correo, telefono, estado, experiencia_ventas, mensaje) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nombre_completo, $empresa, $correo, $telefono, $estado, $experiencia_ventas, $mensaje]);
    
    $solicitud_id = $pdo->lastInsertId();
    
    // Aquí puedes agregar código para enviar email de confirmación
    // enviarEmailConfirmacion($correo, $nombre_completo);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Solicitud enviada con éxito. Te contactaremos en menos de 24 horas.',
        'id' => $solicitud_id
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()]);
}
?>