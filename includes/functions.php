<?php
function sanitizeInput($data) {
    if (is_null($data)) return '';
    return htmlspecialchars(strip_tags(trim($data)));
}

function validateRegistrationData($data) {
    $errors = [];

    if (empty($data['empresa_nombre']) || strlen($data['empresa_nombre']) < 3) {
        $errors[] = "El nombre de la empresa debe tener al menos 3 caracteres";
    }

    if (empty($data['empresa_ruc']) || !preg_match('/^[0-9]{7,15}$/', $data['empresa_ruc'])) {
        $errors[] = "El RUC debe contener solo números (7-15 dígitos)";
    }

    if (empty($data['admin_nombre']) || strlen($data['admin_nombre']) < 3) {
        $errors[] = "El nombre del administrador debe tener al menos 3 caracteres";
    }

    if (empty($data['admin_email']) || !filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Ingrese un email válido para el administrador";
    }

    if (empty($data['admin_password']) || strlen($data['admin_password']) < 6) {
        $errors[] = "La contraseña debe tener al menos 6 caracteres";
    }

    if ($data['admin_password'] !== $data['confirm_password']) {
        $errors[] = "Las contraseñas no coinciden";
    }

    return $errors;
}

function handleFileUpload($file, $upload_dir = "uploads/logos/") {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    // Validar tipo de archivo
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception("Solo se permiten archivos JPEG, PNG y GIF");
    }

    // Validar tamaño (máximo 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception("El archivo no debe exceder 2MB");
    }

    // Crear directorio si no existe
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $file_name = 'logo_' . time() . '.' . $file_extension;
    $target_path = $upload_dir . $file_name;

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return $target_path;
    }

    throw new Exception("Error al subir el archivo");
}
?>