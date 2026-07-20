<?php
// Facturacion/certificado_procesar.php
require '../vendor/autoload.php';

use Facturapi\Facturapi;

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Verificar que sea una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['cert_mensaje'] = '❌ Método no permitido';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

// Verificar acción
if (!isset($_POST['accion']) || $_POST['accion'] !== 'cargar_certificado') {
    $_SESSION['cert_mensaje'] = '❌ Acción no válida';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

// Validar datos requeridos
$organization_id = $_POST['organization_id'] ?? '';
$api_key = $_POST['api_key'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($organization_id) || empty($api_key)) {
    $_SESSION['cert_mensaje'] = '❌ Faltan datos requeridos para la organización';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

// Validar archivos subidos
if (!isset($_FILES['cer_file']) || !isset($_FILES['key_file'])) {
    $_SESSION['cert_mensaje'] = '❌ Debes subir ambos archivos (.cer y .key)';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

$cer_file = $_FILES['cer_file'];
$key_file = $_FILES['key_file'];

// Validar errores de subida
$upload_errors = [
    0 => 'No hay error',
    1 => 'El archivo excede el tamaño máximo permitido en php.ini',
    2 => 'El archivo excede el tamaño máximo especificado en el formulario',
    3 => 'El archivo solo se subió parcialmente',
    4 => 'No se subió ningún archivo',
    6 => 'Falta la carpeta temporal',
    7 => 'No se pudo escribir en el disco',
    8 => 'Una extensión de PHP detuvo la subida'
];

if ($cer_file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['cert_mensaje'] = '❌ Error en archivo .cer: ' . ($upload_errors[$cer_file['error']] ?? 'Error desconocido');
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

if ($key_file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['cert_mensaje'] = '❌ Error en archivo .key: ' . ($upload_errors[$key_file['error']] ?? 'Error desconocido');
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

// Validar extensiones
$cer_ext = strtolower(pathinfo($cer_file['name'], PATHINFO_EXTENSION));
$key_ext = strtolower(pathinfo($key_file['name'], PATHINFO_EXTENSION));

if ($cer_ext !== 'cer') {
    $_SESSION['cert_mensaje'] = '❌ El archivo de certificado debe tener extensión .cer';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

if ($key_ext !== 'key') {
    $_SESSION['cert_mensaje'] = '❌ El archivo de llave debe tener extensión .key';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

// Tamaño máximo: 2MB
$max_size = 2 * 1024 * 1024;
if ($cer_file['size'] > $max_size) {
    $_SESSION['cert_mensaje'] = '❌ El archivo .cer excede el tamaño máximo de 2MB';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

if ($key_file['size'] > $max_size) {
    $_SESSION['cert_mensaje'] = '❌ El archivo .key excede el tamaño máximo de 2MB';
    $_SESSION['cert_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

try {
    // Crear directorio temporal si no existe
    $temp_dir = sys_get_temp_dir() . '/facturacion_csd/';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    // Mover archivos a ubicación temporal
    $cer_path = $temp_dir . uniqid('cer_') . '.cer';
    $key_path = $temp_dir . uniqid('key_') . '.key';
    
    if (!move_uploaded_file($cer_file['tmp_name'], $cer_path)) {
        throw new Exception('No se pudo guardar el archivo .cer temporalmente');
    }
    
    if (!move_uploaded_file($key_file['tmp_name'], $key_path)) {
        unlink($cer_path); // Limpiar archivo .cer
        throw new Exception('No se pudo guardar el archivo .key temporalmente');
    }
    
    // Inicializar Facturapi
    $facturapi = new Facturapi($api_key);
    
    // Subir certificado a Facturapi
    $resultado = $facturapi->Organizations->uploadCertificate(
        $organization_id,
        [
            "cer" => $cer_path,
            "key" => $key_path,
            "password" => $password
        ]
    );
    
    // Limpiar archivos temporales
    unlink($cer_path);
    unlink($key_path);
    
    $_SESSION['cert_mensaje'] = '✅ Certificado cargado correctamente';
    $_SESSION['cert_tipo_mensaje'] = 'success';
    
} catch (Exception $e) {
    // Limpiar archivos temporales si existen
    if (isset($cer_path) && file_exists($cer_path)) unlink($cer_path);
    if (isset($key_path) && file_exists($key_path)) unlink($key_path);
    
    $error_msg = $e->getMessage();
    
    // Procesar mensajes de error comunes
    if (strpos($error_msg, 'password') !== false || strpos($error_msg, 'contraseña') !== false) {
        $_SESSION['cert_mensaje'] = '❌ Contraseña incorrecta. Verifica que sea la contraseña correcta del archivo .key';
    } elseif (strpos($error_msg, 'expired') !== false) {
        $_SESSION['cert_mensaje'] = '❌ El certificado está expirado. Debes renovarlo en el SAT';
    } elseif (strpos($error_msg, 'invalid certificate') !== false) {
        $_SESSION['cert_mensaje'] = '❌ El certificado es inválido. Verifica que los archivos sean correctos';
    } elseif (strpos($error_msg, 'mismatch') !== false) {
        $_SESSION['cert_mensaje'] = '❌ Los archivos .cer y .key no coinciden. Deben ser del mismo CSD';
    } else {
        $_SESSION['cert_mensaje'] = '❌ Error al cargar certificado: ' . $error_msg;
    }
    
    $_SESSION['cert_tipo_mensaje'] = 'danger';
}

header("Location: inicio.php");
exit();
?>