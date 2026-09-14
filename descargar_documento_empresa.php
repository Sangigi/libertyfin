<?php
// descargar_documento_empresa.php
//
// Sirve un documento subido en "Mi cuenta" -> Documentos. Los archivos
// viven en uploads_privados/ (bloqueado por .htaccess a acceso directo),
// así que esta es la ÚNICA forma de verlos/descargarlos, y solo con sesión
// de administrador de la propia empresa.

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    exit('No autorizado');
}
if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('No tienes permisos para ver este documento');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/includes/mi_cuenta_pago.php';

$tipo = trim($_GET['tipo'] ?? '');
if (!array_key_exists($tipo, MCP_TIPOS_DOCUMENTO)) {
    http_response_code(400);
    exit('Tipo de documento inválido');
}

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    $stmt = $conn->prepare('SELECT * FROM documentos_comercio WHERE tipo = ? LIMIT 1');
    $stmt->execute([$tipo]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        exit('Documento no encontrado');
    }

    $rutaAbs = mcp_directorio_privado($_SESSION['empresa_db']) . '/' . $doc['ruta_archivo'];
    if (!is_file($rutaAbs)) {
        http_response_code(404);
        exit('El archivo ya no está disponible');
    }

    header('Content-Type: ' . ($doc['mime_real'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($rutaAbs));
    header('Content-Disposition: inline; filename="' . basename($doc['nombre_original'] ?: $doc['ruta_archivo']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($rutaAbs);
} catch (Exception $e) {
    error_log('descargar_documento_empresa: ' . $e->getMessage());
    http_response_code(500);
    exit('Error al obtener el documento');
}
