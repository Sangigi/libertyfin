<?php
// =============================================
// AJAX: GUARDAR EDICIÓN DE EMPRESA
// =============================================

// Deshabilitar reporte de errores HTML
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Configuración de sesión
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);
session_start();
date_default_timezone_set('America/Mexico_City');

// Función para enviar respuesta JSON
function sendJsonResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

// Verificar sesión
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    sendJsonResponse(false, 'No autorizado');
}

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Método no permitido');
}

// Cargar configuración
try {
    require_once __DIR__ . '/../../config/database.php';
} catch (Exception $e) {
    sendJsonResponse(false, 'Error al cargar configuración: ' . $e->getMessage());
}

// Validar campos requeridos
$required_fields = ['id_empresa', 'nombre_empresa', 'giro_comercial', 'telefono', 'email', 'nombre_contacto', 'plan'];
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
        sendJsonResponse(false, "El campo $field es requerido");
    }
}

try {
    // Obtener conexión
    $pdo = getDBConnection();
    
    // Sanitizar datos
    $id_empresa = intval($_POST['id_empresa']);
    $nombre_empresa = trim($_POST['nombre_empresa']);
    $giro_comercial = intval($_POST['giro_comercial']);
    $rfc = trim($_POST['rfc'] ?? '');
    $telefono = trim($_POST['telefono']);
    $email = trim($_POST['email']);
    $direccion = trim($_POST['direccion'] ?? '');
    $nombre_contacto = trim($_POST['nombre_contacto']);
    $email_admin = trim($_POST['email_admin'] ?? '');
    $plan = $_POST['plan'];
    $activo = isset($_POST['activo']) ? intval($_POST['activo']) : 1;
    $estado_verificacion = $_POST['estado_verificacion'] ?? 'pendiente';
    
    // Validar email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse(false, 'Email no válido');
    }
    
    if (!empty($email_admin) && !filter_var($email_admin, FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse(false, 'Email del administrador no válido');
    }
    
    // Verificar si la empresa existe
    $check_sql = "SELECT id FROM empresas WHERE id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$id_empresa]);
    if (!$check_stmt->fetch()) {
        sendJsonResponse(false, 'La empresa no existe');
    }
    
    // Actualizar empresa - versión simplificada sin subconsulta
    $sql = "UPDATE empresas SET 
                nombre_empresa = ?,
                giro_comercial = ?,
                rfc = ?,
                telefono = ?,
                email = ?,
                direccion = ?,
                nombre_contacto = ?,
                email_admin = ?,
                plan = ?,
                activo = ?,
                estado_verificacion = ?
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $nombre_empresa,
        $giro_comercial,
        $rfc,
        $telefono,
        $email,
        $direccion,
        $nombre_contacto,
        $email_admin,
        $plan,
        $activo,
        $estado_verificacion,
        $id_empresa
    ]);
    
    // Si el estado cambió a aprobado, actualizar fecha de verificación
    if ($estado_verificacion === 'aprobado') {
        $update_date_sql = "UPDATE empresas SET fecha_verificacion = NOW() WHERE id = ? AND estado_verificacion = 'aprobado'";
        $date_stmt = $pdo->prepare($update_date_sql);
        $date_stmt->execute([$id_empresa]);
    }
    
    if ($result) {
        sendJsonResponse(true, 'Empresa actualizada correctamente');
    } else {
        sendJsonResponse(false, 'Error al actualizar la empresa');
    }
    
} catch (PDOException $e) {
    error_log('Error al guardar empresa: ' . $e->getMessage());
    sendJsonResponse(false, 'Error en la base de datos: ' . $e->getMessage());
} catch (Exception $e) {
    error_log('Error general al guardar empresa: ' . $e->getMessage());
    sendJsonResponse(false, 'Error al procesar la solicitud: ' . $e->getMessage());
}