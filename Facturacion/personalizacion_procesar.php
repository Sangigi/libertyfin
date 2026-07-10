<?php
// personalizacion_procesar.php
require '../vendor/autoload.php';

use Facturapi\Facturapi;

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Obtener datos de la sesión
$organization_id = $_POST['organization_id'] ?? '';
$api_key = $_POST['api_key'] ?? '';
$accion = $_POST['accion'] ?? '';

// Verificar que tenemos los datos necesarios
if (empty($organization_id) || empty($api_key) || empty($accion)) {
    $_SESSION['personalizacion_mensaje'] = '❌ Datos incompletos para procesar la solicitud';
    $_SESSION['personalizacion_tipo_mensaje'] = 'danger';
    header("Location: inicio.php");
    exit();
}

try {
    // Inicializar Facturapi
    $facturapi = new Facturapi($api_key);

    if ($accion === 'subir_logo') {
        // Subir logo
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['logo_file']['tmp_name'];
            $file_name = $_FILES['logo_file']['name'];
            
            // Validar tipo de archivo
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
            $file_type = mime_content_type($tmp_name);
            
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception('Tipo de archivo no permitido. Solo se aceptan imágenes.');
            }
            
            // Validar tamaño (2MB máximo)
            if ($_FILES['logo_file']['size'] > 2 * 1024 * 1024) {
                throw new Exception('El archivo es demasiado grande. Máximo 2MB.');
            }
            
            // Subir logo usando la librería Facturapi
            $organization = $facturapi->Organizations->uploadLogo($organization_id, $tmp_name);
            
            $_SESSION['personalizacion_mensaje'] = '✅ Logo subido correctamente';
            $_SESSION['personalizacion_tipo_mensaje'] = 'success';
        } else {
            throw new Exception('No se recibió ningún archivo o hubo un error en la carga');
        }
        
    } elseif ($accion === 'actualizar_personalizacion') {
        // Actualizar personalización
        $datos_personalizacion = [];
        
        // Color
        if (!empty($_POST['color_hex'])) {
            $color = trim($_POST['color_hex']);
            // Validar formato hexadecimal
            if (preg_match('/^#[0-9A-F]{6}$/i', $color)) {
                $datos_personalizacion['color'] = strtoupper($color);
            }
        }
        
        // Números de folio
        if (!empty($_POST['next_folio_number'])) {
            $datos_personalizacion['next_folio_number'] = (int)$_POST['next_folio_number'];
        }
        
        if (!empty($_POST['next_folio_number_test'])) {
            $datos_personalizacion['next_folio_number_test'] = (int)$_POST['next_folio_number_test'];
        }
        
        // Configuración PDF
        if (!empty($_POST['pdf_extra'])) {
            $pdf_extra = [];
            foreach ($_POST['pdf_extra'] as $key => $value) {
                $pdf_extra[$key] = ($value === '1');
            }
            $datos_personalizacion['pdf_extra'] = $pdf_extra;
        }
        
        // Actualizar organización
        $organizacion_actualizada = $facturapi->Organizations->updateLegal($organization_id, $datos_personalizacion);
        
        $_SESSION['personalizacion_mensaje'] = '✅ Configuración de personalización actualizada correctamente';
        $_SESSION['personalizacion_tipo_mensaje'] = 'success';
    }
    
} catch (Exception $e) {
    $_SESSION['personalizacion_mensaje'] = '❌ Error: ' . $e->getMessage();
    $_SESSION['personalizacion_tipo_mensaje'] = 'danger';
}

// Redirigir de vuelta
header("Location: inicio.php");
exit();
?>