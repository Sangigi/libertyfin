<?php
// guardar_datos_fiscales_empresa.php
//
// Guarda los datos fiscales básicos (persona física/moral, RFC, razón
// social, régimen fiscal, código postal fiscal) en sistema_config de la
// base de datos propia de la empresa. Solo el admin de la empresa.

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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$campo = function ($nombre, $max = null) use ($input) {
    $v = trim((string)($input[$nombre] ?? ''));
    if ($v === '') return null;
    return $max ? mb_substr($v, 0, $max) : $v;
};

$tipoPersona = trim((string)($input['tipo_persona'] ?? ''));
if ($tipoPersona !== '' && !in_array($tipoPersona, ['fisica', 'moral'], true)) {
    echo json_encode(['success' => false, 'message' => 'tipo_persona debe ser "fisica" o "moral"']);
    exit();
}

$rfc = $campo('rfc', 13);
if ($rfc !== null) {
    $rfc = strtoupper($rfc);
    if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
        echo json_encode(['success' => false, 'message' => 'El RFC no tiene un formato válido.']);
        exit();
    }
}

$cpFiscal = $campo('cp_fiscal', 5);
if ($cpFiscal !== null && !preg_match('/^\d{5}$/', $cpFiscal)) {
    echo json_encode(['success' => false, 'message' => 'El código postal fiscal debe tener 5 dígitos.']);
    exit();
}

$razonSocial = $campo('razon_social', 200);
$regimenFiscal = $campo('regimen_fiscal', 10);

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    asegurar_tablas_pago($conn);

    $conn->prepare("
        UPDATE sistema_config SET
            tipo_persona = ?,
            rfc = ?,
            razon_social = ?,
            regimen_fiscal = ?,
            cp_fiscal = ?
    ")->execute([
        $tipoPersona !== '' ? $tipoPersona : null,
        $rfc,
        $razonSocial,
        $regimenFiscal,
        $cpFiscal,
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('guardar_datos_fiscales_empresa: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al guardar los datos fiscales.']);
}
