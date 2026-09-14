<?php
// subir_documento_empresa.php
//
// Sube uno de los documentos requeridos para activar cobros reales
// (identificación, estado de cuenta bancario, comprobante de domicilio,
// constancia fiscal). El archivo se guarda en uploads_privados/, fuera de
// acceso web directo (ver includes/mi_cuenta_pago.php e
// includes/mi_cuenta_pago.php::mcp_directorio_privado) — solo se puede
// descargar por descargar_documento_empresa.php, con sesión autenticada.

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}
if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tienes permisos para realizar esta acción']);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/includes/mi_cuenta_pago.php';

$tipo = trim($_POST['tipo'] ?? '');
if (!array_key_exists($tipo, MCP_TIPOS_DOCUMENTO)) {
    echo json_encode(['success' => false, 'message' => 'Tipo de documento inválido.']);
    exit();
}
if (empty($_FILES['archivo'])) {
    echo json_encode(['success' => false, 'message' => 'Selecciona un archivo.']);
    exit();
}

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    asegurar_tablas_pago($conn);

    $res = mcp_guardar_documento($_FILES['archivo'], $_SESSION['empresa_db']);
    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => $res['error']]);
        exit();
    }

    $usuarioId = intval($_SESSION['usuario_id'] ?? 0);
    $nombreOriginal = mb_substr($_FILES['archivo']['name'] ?? '', 0, 255);

    $stmtExistente = $conn->prepare('SELECT id, ruta_archivo FROM documentos_comercio WHERE tipo = ? LIMIT 1');
    $stmtExistente->execute([$tipo]);
    $existente = $stmtExistente->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $conn->prepare('
            UPDATE documentos_comercio SET
                ruta_archivo = ?, nombre_original = ?, mime_real = ?, tamano_bytes = ?,
                estado = "pendiente", motivo_rechazo = NULL,
                subido_por = ?, subido_en = NOW(), revisado_por = NULL, revisado_en = NULL
            WHERE id = ?
        ')->execute([
            $res['ruta_relativa'], $nombreOriginal, $res['mime_real'], $res['tamano_bytes'],
            $usuarioId, $existente['id'],
        ]);

        // Reemplaza el archivo físico anterior (best-effort).
        $rutaVieja = mcp_directorio_privado($_SESSION['empresa_db']) . '/' . $existente['ruta_archivo'];
        if (is_file($rutaVieja) && $existente['ruta_archivo'] !== $res['ruta_relativa']) {
            @unlink($rutaVieja);
        }
    } else {
        $conn->prepare('
            INSERT INTO documentos_comercio (tipo, ruta_archivo, nombre_original, mime_real, tamano_bytes, estado, subido_por, subido_en)
            VALUES (?, ?, ?, ?, ?, "pendiente", ?, NOW())
        ')->execute([
            $tipo, $res['ruta_relativa'], $nombreOriginal, $res['mime_real'], $res['tamano_bytes'], $usuarioId,
        ]);
    }

    // El estado agregado vuelve a "en_revision" con cualquier subida nueva,
    // incluso si ya estaba "aprobada": un documento reemplazado necesita
    // volver a revisarse.
    $conn->exec("UPDATE sistema_config SET documentacion_estado = 'en_revision'");

    echo json_encode(['success' => true, 'tipo' => $tipo, 'estado' => 'pendiente']);
} catch (Exception $e) {
    error_log('subir_documento_empresa: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al subir el documento.']);
}
