<?php
// guardar_datos_pago_empresa.php
//
// Guarda el formulario de alta de comercio (titular, representante legal,
// datos de la empresa, identificación y datos BANCARIOS) en la tabla
// datos_pago_comercio de la base de datos propia de la empresa. El
// clausulado del contrato de procesamiento de transacciones es obligatorio
// aceptarlo solo la PRIMERA vez que se envía la información; en ediciones
// posteriores no se vuelve a exigir (mismo criterio que el formulario).

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

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    asegurar_tablas_pago($conn);

    $stmtExiste = $conn->query("SELECT id, clausulado_aceptado_en FROM datos_pago_comercio LIMIT 1");
    $existente = $stmtExiste->fetch(PDO::FETCH_ASSOC);

    $aceptaClausulado = !empty($input['acepta_clausulado']) && $input['acepta_clausulado'] !== '0';
    $yaAceptado = $existente && !empty($existente['clausulado_aceptado_en']);

    if (!$existente && !$aceptaClausulado) {
        echo json_encode(['success' => false, 'message' => 'Debes aceptar el clausulado del contrato de procesamiento de transacciones para enviar esta información.']);
        exit();
    }

    $campo = function ($nombre, $max = null) use ($input) {
        $v = trim((string)($input[$nombre] ?? ''));
        if ($v === '') return null;
        return $max ? mb_substr($v, 0, $max) : $v;
    };
    $campoFecha = function ($nombre) use ($input) {
        $v = trim((string)($input[$nombre] ?? ''));
        if ($v === '') return null;
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    };

    $vals = [
        'titular_nombre'           => $campo('titular_nombre', 200),
        'nombre_comercio'          => $campo('nombre_comercio', 200),
        'titular_correo'           => $campo('titular_correo', 160),
        'giro'                     => $campo('giro', 200),
        'calle_numero'             => $campo('calle_numero', 200),
        'numero_interior'          => $campo('numero_interior', 50),
        'colonia'                  => $campo('colonia', 150),
        'delegacion_municipio'     => $campo('delegacion_municipio', 150),
        'ciudad'                   => $campo('ciudad', 100),
        'estado_direccion'         => $campo('estado_direccion', 100),
        'pais'                     => $campo('pais', 100) ?: 'México',
        'telefono_oficina'         => $campo('telefono_oficina', 20),
        'telefono_celular'         => $campo('telefono_celular', 20),
        'nombre_vendedor'          => $campo('nombre_vendedor', 150),
        'rep_legal_nombre'         => $campo('rep_legal_nombre', 200),
        'rep_legal_escritura'      => $campo('rep_legal_escritura', 200),
        'rep_legal_notaria_numero' => $campo('rep_legal_notaria_numero', 50),
        'rep_legal_notario_nombre' => $campo('rep_legal_notario_nombre', 200),
        'rep_legal_ciudad'         => $campo('rep_legal_ciudad', 100),
        'empresa_escritura'        => $campo('empresa_escritura', 200),
        'empresa_folio_rpc'        => $campo('empresa_folio_rpc', 100),
        'empresa_ciudad'           => $campo('empresa_ciudad', 100),
        'empresa_notario_nombre'   => $campo('empresa_notario_nombre', 200),
        'empresa_notaria_numero'   => $campo('empresa_notaria_numero', 50),
        'id_tipo'                  => $campo('id_tipo', 50),
        'id_numero'                => $campo('id_numero', 100),
        'id_fecha_expedicion'      => $campoFecha('id_fecha_expedicion'),
        'id_vigencia'              => $campoFecha('id_vigencia'),
        'banco'                    => $campo('banco', 100),
        'plaza'                    => $campo('plaza', 100),
        'sucursal_bancaria'        => $campo('sucursal_bancaria', 100),
        'cuenta_cheques'           => $campo('cuenta_cheques', 30),
        'cuenta_clabe'             => $campo('cuenta_clabe', 18),
    ];

    if ($vals['cuenta_clabe'] !== null && !preg_match('/^\d{18}$/', $vals['cuenta_clabe'])) {
        echo json_encode(['success' => false, 'message' => 'La cuenta CLABE debe tener 18 dígitos.']);
        exit();
    }

    $usuarioId = intval($_SESSION['usuario_id'] ?? 0);

    if ($existente) {
        $sets = [];
        $params = [];
        foreach ($vals as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $sets[] = 'actualizado_en = NOW()';
        $sets[] = 'actualizado_por = ?';
        $params[] = $usuarioId;
        if ($aceptaClausulado && !$yaAceptado) {
            $sets[] = 'clausulado_aceptado_en = NOW()';
        }
        $params[] = $existente['id'];
        $conn->prepare('UPDATE datos_pago_comercio SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    } else {
        $cols = array_keys($vals);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare(
            'INSERT INTO datos_pago_comercio (' . implode(', ', $cols) . ', actualizado_en, actualizado_por, clausulado_aceptado_en)
             VALUES (' . $placeholders . ', NOW(), ?, NOW())'
        )->execute(array_merge(array_values($vals), [$usuarioId]));
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('guardar_datos_pago_empresa: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al guardar los datos de pago.']);
}
