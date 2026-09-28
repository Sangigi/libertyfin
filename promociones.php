<?php
// =============================================
// SESIÓN Y ENTORNO
// =============================================
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
session_start();

date_default_timezone_set('America/Mexico_City');
const PROMO_MYSQL_TZ = '-06:00';

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Login");
    exit();
}

// ---------------------------------------------
// PERMISOS (opcional)
// ---------------------------------------------
$PROMO_ROLES_PERMITIDOS = null;
$PROMO_SESSION_ROL_KEY  = 'rol';

if (is_array($PROMO_ROLES_PERMITIDOS)) {
    $rol_actual = strtolower((string)($_SESSION[$PROMO_SESSION_ROL_KEY] ?? ''));
    $roles_ok   = array_map('strtolower', $PROMO_ROLES_PERMITIDOS);
    if (!in_array($rol_actual, $roles_ok, true)) {
        http_response_code(403);
        die('No tienes permisos para administrar promociones.');
    }
}

// ---------------------------------------------
// CSRF
// ---------------------------------------------
if (empty($_SESSION['csrf_promos'])) {
    $_SESSION['csrf_promos'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_promos'];

// =============================================
// CONSTANTES DE NEGOCIO
// =============================================
const APLICA_PERMITIDO = [
    'descuento_porcentual' => ['producto', 'categoria', 'marca', 'venta_completa'],
    'descuento_fijo'       => ['producto', 'categoria', 'marca', 'venta_completa'],
    'precio_especial'      => ['producto', 'categoria', 'marca'],
    'llevalo_paga'         => ['producto', 'categoria', 'marca'],
    'precio_volumen'       => ['producto', 'categoria', 'marca'],
    'combo'                => ['combo'],
];
const METODOS_PAGO  = ['efectivo', 'tarjeta', 'transferencia'];
const TIPOS_CLIENTE = ['publico', 'mayorista', 'vip'];

// =============================================
// CONEXIÓN A BD DE EMPRESA
// =============================================
$conn = null;
$empresa_info = [];
$sucursales = [];
$categorias = [];
$productos = [];

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    try {
        $conn->exec("SET time_zone = '" . PROMO_MYSQL_TZ . "'");
    } catch (Exception $e) {
        // Si el motor no lo permite, se continúa con la zona del servidor
    }

    $empresa_info = $conn->query("
        SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo
        FROM sistema_config LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $categorias = $conn->query("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $productos  = $conn->query("SELECT id, codigo, nombre, precio, marca, categoria_id FROM productos WHERE activo = 1 ORDER BY nombre LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// =============================================
// HELPERS
// =============================================
function tipoPromoLabel(string $tipo): string {
    return [
        'descuento_porcentual' => 'Descuento %',
        'descuento_fijo'       => 'Descuento $',
        'precio_especial'      => 'Precio especial',
        'llevalo_paga'         => 'Lleva N / Paga M',
        'precio_volumen'       => 'Precio por volumen',
        'combo'                => 'Combo / Paquete',
    ][$tipo] ?? $tipo;
}

function aplicaLabel(string $a): string {
    return [
        'producto'        => 'Producto específico',
        'categoria'       => 'Categoría',
        'marca'           => 'Marca',
        'venta_completa'  => 'Toda la venta',
        'combo'           => 'Combo',
    ][$a] ?? $a;
}

/** "2026-09-24T18:30" → "2026-09-24 18:30:00" */
function normalizarFechaHora(string $v): string {
    $v = trim(str_replace('T', ' ', $v));
    if (strlen($v) === 16) $v .= ':00';
    return $v;
}

/** Normaliza string vacío a NULL */
function nullSiVacio($v) {
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function validarPromocion(array $d, ?PDO $conn = null): array {
    $errores = [];

    if ($d['nombre'] === '') $errores[] = 'El nombre es obligatorio';

    if (!array_key_exists($d['tipo_promocion'], APLICA_PERMITIDO)) {
        $errores[] = 'Tipo de promoción no válido';
        return $errores;
    }
    if (!in_array($d['aplica_a'], APLICA_PERMITIDO[$d['tipo_promocion']], true)) {
        $errores[] = 'La combinación de tipo de promoción y "Aplica a" no es válida';
    }

    $fi = DateTime::createFromFormat('Y-m-d H:i:s', $d['fecha_inicio']);
    $ff = DateTime::createFromFormat('Y-m-d H:i:s', $d['fecha_fin']);
    if (!$fi || !$ff) {
        $errores[] = 'Las fechas de inicio y fin son obligatorias y deben ser válidas';
    } elseif ($fi >= $ff) {
        $errores[] = 'La fecha de fin debe ser posterior a la fecha de inicio';
    }

    if (($d['hora_inicio'] === null) !== ($d['hora_fin'] === null)) {
        $errores[] = 'Captura hora de inicio y hora de fin, o deja ambas vacías';
    } elseif ($d['hora_inicio'] !== null) {
        $reHora = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
        if (!preg_match($reHora, $d['hora_inicio']) || !preg_match($reHora, $d['hora_fin'])) {
            $errores[] = 'El formato de las horas no es válido';
        } elseif (substr($d['hora_inicio'], 0, 5) === substr($d['hora_fin'], 0, 5)) {
            $errores[] = 'La hora de inicio y la hora de fin no pueden ser iguales';
        }
    }

    switch ($d['tipo_promocion']) {
        case 'descuento_porcentual':
            if ($d['valor_descuento'] <= 0 || $d['valor_descuento'] > 100) $errores[] = 'El % de descuento debe ser mayor a 0 y máximo 100';
            break;
        case 'descuento_fijo':
            if ($d['valor_descuento'] <= 0) $errores[] = 'El monto de descuento debe ser mayor a 0';
            break;
        case 'precio_especial':
            if ($d['precio_especial'] <= 0) $errores[] = 'El precio especial debe ser mayor a 0';
            break;
        case 'llevalo_paga':
            if ($d['cantidad_lleva'] <= 0 || $d['cantidad_paga'] <= 0) $errores[] = 'Cantidades de "lleva" y "paga" deben ser mayores a 0';
            if ($d['cantidad_paga'] >= $d['cantidad_lleva']) $errores[] = 'La cantidad a pagar debe ser menor que la cantidad a llevar';
            break;
        case 'precio_volumen':
            if ($d['cantidad_minima_volumen'] <= 0 || $d['precio_volumen'] <= 0) $errores[] = 'Cantidad mínima y precio por volumen requeridos';
            break;
        case 'combo':
            if (empty($d['combo_productos'])) $errores[] = 'Debe agregar al menos un producto al combo';
            if ($d['precio_especial'] <= 0) $errores[] = 'El precio del combo debe ser mayor a 0';
            if ($conn && $d['precio_especial'] > 0 && !empty($d['combo_productos'])) {
                $stmtP = $conn->prepare("SELECT precio FROM productos WHERE id = ?");
                $suma = 0.0;
                foreach ($d['combo_productos'] as $item) {
                    $stmtP->execute([$item['producto_id']]);
                    $suma += (float)$stmtP->fetchColumn() * $item['cantidad'];
                }
                if ($suma > 0 && $d['precio_especial'] >= $suma) {
                    $errores[] = 'El precio del combo ($' . number_format($d['precio_especial'], 2)
                               . ') debe ser menor a la suma de los precios normales ($' . number_format($suma, 2) . ')';
                }
            }
            break;
    }

    if ($d['aplica_a'] === 'producto' && empty($d['productos_aplicables'])) {
        $errores[] = 'Selecciona al menos un producto';
    }
    if ($d['aplica_a'] === 'categoria' && empty($d['categorias_aplicables'])) {
        $errores[] = 'Selecciona al menos una categoría';
    }
    if ($d['aplica_a'] === 'marca' && empty($d['marcas_aplicables'])) {
        $errores[] = 'Especifica al menos una marca';
    }
    if (!$d['todas_sucursales'] && empty($d['sucursales_aplicables'])) {
        $errores[] = 'Selecciona al menos una sucursal';
    }

    if ($d['metodo_pago'] !== null && !in_array($d['metodo_pago'], METODOS_PAGO, true)) {
        $errores[] = 'Método de pago no válido';
    }
    if ($d['tipo_cliente'] !== null && !in_array($d['tipo_cliente'], TIPOS_CLIENTE, true)) {
        $errores[] = 'Tipo de cliente no válido';
    }
    if ($d['cantidad_minima'] < 0 || $d['monto_minimo'] < 0) {
        $errores[] = 'Las condiciones mínimas no pueden ser negativas';
    }
    if ($d['prioridad'] < 1 || $d['prioridad'] > 999) {
        $errores[] = 'La prioridad debe estar entre 1 y 999';
    }

    return $errores;
}

/**
 * Guarda los aplicables de la promoción.
 * - Productos → tabla `promocion_productos`
 * - Categorías / marcas → tabla `promociones_aplicables`
 */
function guardarAplicables(PDO $conn, int $promo_id, array $d): void {
    // Limpiar ambas tablas
    $conn->prepare("DELETE FROM promociones_aplicables WHERE promocion_id = ?")->execute([$promo_id]);
    $conn->prepare("DELETE FROM promocion_productos WHERE promocion_id = ?")->execute([$promo_id]);

    // Productos específicos → tabla dedicada
    if ($d['aplica_a'] === 'producto' && !empty($d['productos_aplicables'])) {
        $stmtP = $conn->prepare("INSERT INTO promocion_productos (promocion_id, producto_id) VALUES (?, ?)");
        foreach ($d['productos_aplicables'] as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) $stmtP->execute([$promo_id, $pid]);
        }
    }

    // Categorías y marcas → promociones_aplicables
    $stmt = $conn->prepare("INSERT INTO promociones_aplicables (promocion_id, tipo, referencia_id, referencia_nombre) VALUES (?,?,?,?)");

    if ($d['aplica_a'] === 'categoria' && !empty($d['categorias_aplicables'])) {
        foreach ($d['categorias_aplicables'] as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) $stmt->execute([$promo_id, 'categoria', $cid, null]);
        }
    } elseif ($d['aplica_a'] === 'marca' && !empty($d['marcas_aplicables'])) {
        foreach ($d['marcas_aplicables'] as $m) {
            $m = trim($m);
            if ($m !== '') $stmt->execute([$promo_id, 'marca', null, $m]);
        }
    }
}

function guardarSucursales(PDO $conn, int $promo_id, array $d): void {
    $conn->prepare("DELETE FROM promociones_sucursales WHERE promocion_id = ?")->execute([$promo_id]);
    if ($d['todas_sucursales'] || empty($d['sucursales_aplicables'])) return;
    $stmt = $conn->prepare("INSERT INTO promociones_sucursales (promocion_id, sucursal_id) VALUES (?,?)");
    foreach ($d['sucursales_aplicables'] as $sid) $stmt->execute([$promo_id, (int)$sid]);
}

function guardarCombo(PDO $conn, int $promo_id, array $d): void {
    $conn->prepare("DELETE FROM promociones_combo WHERE promocion_id = ?")->execute([$promo_id]);
    if ($d['tipo_promocion'] !== 'combo' || empty($d['combo_productos'])) return;
    $stmt = $conn->prepare("INSERT INTO promociones_combo (promocion_id, producto_id, cantidad) VALUES (?,?,?)");
    foreach ($d['combo_productos'] as $item) {
        $stmt->execute([$promo_id, (int)$item['producto_id'], max(1, (int)$item['cantidad'])]);
    }
}

/**
 * Indica si la promoción ya se aplicó en alguna venta.
 * Usa venta_detalles (plural) y promocion_id.
 */
function promocionUsadaEnVentas(PDO $conn, int $id): bool {
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM venta_detalles WHERE promocion_id = ?");
        if (!$st || !$st->execute([$id])) return false;
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function recolectarDatosPost(array $post): array {
    // Color del badge: solo #RRGGBB
    $color = (string)($post['color_badge'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#667eea';

    // Vacíos → NULL
    $metodo  = nullSiVacio($post['metodo_pago']  ?? '');
    $cliente = nullSiVacio($post['tipo_cliente'] ?? '');
    $hi      = nullSiVacio($post['hora_inicio']  ?? '');
    $hf      = nullSiVacio($post['hora_fin']     ?? '');

    // Días de la semana: solo 1-7, sin repetidos
    $dias = null;
    $dias_raw = nullSiVacio($post['dias_semana'] ?? '');
    if ($dias_raw !== null) {
        $lista = [];
        foreach (explode(',', $dias_raw) as $x) {
            $x = trim($x);
            if (preg_match('/^[1-7]$/', $x)) $lista[(int)$x] = (int)$x;
        }
        ksort($lista);
        $dias = $lista ? implode(',', $lista) : null;
    }

    // Combo: sin duplicados (se suman cantidades)
    $combo = [];
    $rawCombo = json_decode((string)($post['combo_productos_json'] ?? '[]'), true);
    if (is_array($rawCombo)) {
        foreach ($rawCombo as $item) {
            if (!is_array($item)) continue;
            $pid = (int)($item['producto_id'] ?? 0);
            if ($pid <= 0) continue;
            $cant = max(1, (int)($item['cantidad'] ?? 1));
            if (isset($combo[$pid])) $combo[$pid]['cantidad'] += $cant;
            else $combo[$pid] = ['producto_id' => $pid, 'cantidad' => $cant];
        }
    }

    return [
        'nombre'                  => trim((string)($post['nombre'] ?? '')),
        'descripcion'             => trim((string)($post['descripcion'] ?? '')),
        'tipo_promocion'          => (string)($post['tipo_promocion'] ?? 'descuento_porcentual'),
        'aplica_a'                => (string)($post['aplica_a'] ?? 'producto'),
        'fecha_inicio'            => normalizarFechaHora((string)($post['fecha_inicio'] ?? '')),
        'fecha_fin'               => normalizarFechaHora((string)($post['fecha_fin'] ?? '')),
        'dias_semana'             => $dias,
        'hora_inicio'             => $hi,
        'hora_fin'                => $hf,
        'todas_sucursales'        => isset($post['todas_sucursales']) ? 1 : 0,
        'valor_descuento'         => (float)($post['valor_descuento'] ?? 0),
        'precio_especial'         => (float)($post['precio_especial'] ?? 0),
        'cantidad_lleva'          => (int)($post['cantidad_lleva'] ?? 0),
        'cantidad_paga'           => (int)($post['cantidad_paga'] ?? 0),
        'cantidad_minima_volumen' => (int)($post['cantidad_minima_volumen'] ?? 0),
        'precio_volumen'          => (float)($post['precio_volumen'] ?? 0),
        'cantidad_minima'         => (int)($post['cantidad_minima'] ?? 0),
        'monto_minimo'            => (float)($post['monto_minimo'] ?? 0),
        'metodo_pago'             => $metodo,
        'tipo_cliente'            => $cliente,
        'acumulable'              => isset($post['acumulable']) ? 1 : 0,
        'prioridad'               => (int)($post['prioridad'] ?? 10),
        'activo'                  => isset($post['activo']) ? 1 : 0,
        'color_badge'             => $color,
        'productos_aplicables'    => array_values(array_filter(array_map('intval', (array)($post['productos_aplicables'] ?? [])))),
        'categorias_aplicables'   => array_values(array_filter(array_map('intval', (array)($post['categorias_aplicables'] ?? [])))),
        'marcas_aplicables'       => array_values(array_filter(array_map('trim', explode(',', (string)($post['marcas_aplicables_texto'] ?? ''))))),
        'sucursales_aplicables'   => array_values(array_filter(array_map('intval', (array)($post['sucursales_aplicables'] ?? [])))),
        'combo_productos'         => array_values($combo),
    ];
}

// =============================================
// PROCESAR FORMULARIOS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    try {
        if (!hash_equals($csrf_token, (string)($_POST['csrf'] ?? ''))) {
            throw new Exception('La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.');
        }

        if ($_POST['accion'] === 'crear' || $_POST['accion'] === 'editar') {
            $d = recolectarDatosPost($_POST);
            $errores = validarPromocion($d, $conn);

            if (!empty($errores)) {
                $_SESSION['mensaje'] = implode(' • ', $errores);
                $_SESSION['tipo_mensaje'] = 'danger';
                header('Location: promociones.php');
                exit();
            }

            $valores = [
                $d['nombre'], $d['descripcion'], $d['tipo_promocion'], $d['aplica_a'],
                $d['fecha_inicio'], $d['fecha_fin'], $d['dias_semana'], $d['hora_inicio'], $d['hora_fin'],
                $d['todas_sucursales'], $d['valor_descuento'], $d['precio_especial'],
                $d['cantidad_lleva'], $d['cantidad_paga'], $d['cantidad_minima_volumen'], $d['precio_volumen'],
                $d['cantidad_minima'], $d['monto_minimo'], $d['metodo_pago'], $d['tipo_cliente'],
                $d['acumulable'], $d['prioridad'], $d['activo'], $d['color_badge'],
            ];

            $conn->beginTransaction();

            if ($_POST['accion'] === 'crear') {
                $stmt = $conn->prepare("
                    INSERT INTO promociones (
                        nombre, descripcion, tipo_promocion, aplica_a,
                        fecha_inicio, fecha_fin, dias_semana, hora_inicio, hora_fin,
                        todas_sucursales, valor_descuento, precio_especial,
                        cantidad_lleva, cantidad_paga, cantidad_minima_volumen, precio_volumen,
                        cantidad_minima, monto_minimo, metodo_pago, tipo_cliente,
                        acumulable, prioridad, activo, color_badge
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute($valores);
                $promo_id = (int)$conn->lastInsertId();
                $msg = 'Promoción creada exitosamente';
            } else {
                $promo_id = (int)($_POST['id'] ?? 0);
                if ($promo_id <= 0) throw new Exception('ID inválido');

                $stmt = $conn->prepare("
                    UPDATE promociones SET
                        nombre = ?, descripcion = ?, tipo_promocion = ?, aplica_a = ?,
                        fecha_inicio = ?, fecha_fin = ?, dias_semana = ?, hora_inicio = ?, hora_fin = ?,
                        todas_sucursales = ?, valor_descuento = ?, precio_especial = ?,
                        cantidad_lleva = ?, cantidad_paga = ?, cantidad_minima_volumen = ?, precio_volumen = ?,
                        cantidad_minima = ?, monto_minimo = ?, metodo_pago = ?, tipo_cliente = ?,
                        acumulable = ?, prioridad = ?, activo = ?, color_badge = ?
                    WHERE id = ?
                ");
                $stmt->execute(array_merge($valores, [$promo_id]));
                $msg = 'Promoción actualizada exitosamente';
            }

            guardarAplicables($conn, $promo_id, $d);
            guardarSucursales($conn, $promo_id, $d);
            guardarCombo($conn, $promo_id, $d);

            $conn->commit();
            $_SESSION['mensaje'] = $msg;
            $_SESSION['tipo_mensaje'] = 'success';

        } elseif ($_POST['accion'] === 'eliminar') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');

            if (promocionUsadaEnVentas($conn, $id)) {
                throw new Exception('Esta promoción ya se usó en ventas. Desactívala en lugar de eliminarla para conservar el historial.');
            }

            $conn->beginTransaction();
            foreach (['promociones_aplicables', 'promociones_sucursales', 'promociones_combo', 'promocion_productos'] as $tabla) {
                $conn->prepare("DELETE FROM $tabla WHERE promocion_id = ?")->execute([$id]);
            }
            $conn->prepare("DELETE FROM promociones WHERE id = ?")->execute([$id]);
            $conn->commit();

            $_SESSION['mensaje'] = 'Promoción eliminada';
            $_SESSION['tipo_mensaje'] = 'success';

        } elseif ($_POST['accion'] === 'toggle_activo') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            $conn->prepare("UPDATE promociones SET activo = NOT activo WHERE id = ?")->execute([$id]);
            $_SESSION['mensaje'] = 'Estado actualizado';
            $_SESSION['tipo_mensaje'] = 'success';
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['mensaje'] = 'Error: ' . $e->getMessage();
        $_SESSION['tipo_mensaje'] = 'danger';
    }

    header('Location: promociones.php');
    exit();
}

// =============================================
// ESTADÍSTICAS
// =============================================
$st = $conn->query("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(activo = 1 AND NOW() BETWEEN fecha_inicio AND fecha_fin), 0) AS vigentes,
           COALESCE(SUM(activo = 1 AND fecha_inicio > NOW()), 0) AS programadas,
           COALESCE(SUM(activo = 1 AND fecha_fin < NOW()), 0) AS vencidas
    FROM promociones
")->fetch(PDO::FETCH_ASSOC);

$total_promos       = (int)$st['total'];
$promos_activas     = (int)$st['vigentes'];
$promos_programadas = (int)$st['programadas'];
$promos_vencidas    = (int)$st['vencidas'];

// =============================================
// FILTROS Y LISTADO
// =============================================
$filtro_estado = $_GET['estado'] ?? 'todas';
$filtro_buscar = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];

if ($filtro_buscar !== '') {
    $where[] = "(p.nombre LIKE ? OR p.descripcion LIKE ?)";
    $params[] = "%$filtro_buscar%";
    $params[] = "%$filtro_buscar%";
}

if ($filtro_estado === 'activas') {
    $where[] = "p.activo = 1 AND NOW() BETWEEN p.fecha_inicio AND p.fecha_fin";
} elseif ($filtro_estado === 'programadas') {
    $where[] = "p.activo = 1 AND p.fecha_inicio > NOW()";
} elseif ($filtro_estado === 'vencidas') {
    $where[] = "p.activo = 1 AND p.fecha_fin < NOW()";
} elseif ($filtro_estado === 'inactivas') {
    $where[] = "p.activo = 0";
}

$where_sql = implode(' AND ', $where);

$stmt = $conn->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM promociones_aplicables WHERE promocion_id = p.id) AS total_aplicables,
           (SELECT COUNT(*) FROM promocion_productos WHERE promocion_id = p.id) AS total_productos,
           (SELECT COUNT(*) FROM promociones_sucursales WHERE promocion_id = p.id) AS total_sucursales
    FROM promociones p
    WHERE $where_sql
    ORDER BY p.activo DESC, p.prioridad ASC, p.fecha_inicio DESC
");
$stmt->execute($params);
$promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtA = $conn->prepare("SELECT tipo, referencia_id, referencia_nombre FROM promociones_aplicables WHERE promocion_id = ?");
$stmtP = $conn->prepare("SELECT producto_id FROM promocion_productos WHERE promocion_id = ?");
$stmtS = $conn->prepare("SELECT sucursal_id FROM promociones_sucursales WHERE promocion_id = ?");
$stmtC = $conn->prepare("
    SELECT pc.producto_id, pc.cantidad, pr.nombre, pr.codigo
    FROM promociones_combo pc
    LEFT JOIN productos pr ON pr.id = pc.producto_id
    WHERE pc.promocion_id = ?
");

$promos_data = [];
$ids_productos_usados = [];

foreach ($promociones as $p) {
    $pid = (int)$p['id'];

    $stmtA->execute([$pid]);
    $aplicables = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    $categorias_aplic = [];
    $marcas_aplic = [];
    foreach ($aplicables as $a) {
        if ($a['tipo'] === 'categoria') $categorias_aplic[] = (int)$a['referencia_id'];
        elseif ($a['tipo'] === 'marca') $marcas_aplic[] = $a['referencia_nombre'];
    }

    $stmtP->execute([$pid]);
    $productos_aplic = array_map('intval', array_column($stmtP->fetchAll(PDO::FETCH_ASSOC), 'producto_id'));

    $stmtS->execute([$pid]);
    $sucursales_aplic = array_column($stmtS->fetchAll(PDO::FETCH_ASSOC), 'sucursal_id');

    $stmtC->execute([$pid]);
    $combo = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    foreach ($productos_aplic as $x) $ids_productos_usados[$x] = true;
    foreach ($combo as $c) {
        if (!empty($c['producto_id'])) $ids_productos_usados[(int)$c['producto_id']] = true;
    }

    $p['aplicables_data'] = [
        'productos'  => $productos_aplic,
        'categorias' => $categorias_aplic,
        'marcas'     => $marcas_aplic,
    ];
    $p['sucursales_data'] = $sucursales_aplic;
    $p['combo_data']      = $combo;

    $promos_data[] = $p;
}

// Productos ligados a promociones que quedaron fuera del LIMIT del catálogo
$ids_en_catalogo = array_flip(array_map('intval', array_column($productos, 'id')));
$ids_faltantes = [];
foreach (array_keys($ids_productos_usados) as $idp) {
    if (!isset($ids_en_catalogo[$idp])) $ids_faltantes[] = (int)$idp;
}
if (!empty($ids_faltantes)) {
    $ph = implode(',', array_fill(0, count($ids_faltantes), '?'));
    $stmtF = $conn->prepare("SELECT id, codigo, nombre, precio, marca, categoria_id FROM productos WHERE id IN ($ph)");
    $stmtF->execute($ids_faltantes);
    foreach ($stmtF->fetchAll(PDO::FETCH_ASSOC) as $extra) $productos[] = $extra;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Promociones - <?php echo htmlspecialchars($_SESSION['empresa_nombre'] ?? ''); ?></title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/crm-theme.css">

    <style>
        :root {
            --primary-color:   <?php echo !empty($empresa_info['color_primario'])   ? htmlspecialchars($empresa_info['color_primario'])   : '#27ae60'; ?>;
            --secondary-color: <?php echo !empty($empresa_info['color_secundario']) ? htmlspecialchars($empresa_info['color_secundario']) : '#2ecc71'; ?>;
        }
    </style>

    <style>
        .main-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 4rem;
        }

        .promo-card {
            border-left: 4px solid var(--primary-color);
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .promo-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--lf-shadow);
        }
        .promo-card.inactiva {
            opacity: .65;
            border-left-color: var(--lf-muted-2);
        }

        .promo-badge-tipo {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            font-size: .72rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 999px;
            background: var(--lf-primary-100);
            color: var(--lf-primary-ink);
            letter-spacing: .01em;
        }

        .promo-vigencia { font-size: .85rem; color: var(--lf-muted); }
        .promo-alcance  { font-size: .8rem;  color: var(--lf-muted); }

        .status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .01em;
        }
        .status-pill.vigente    { background: var(--lf-success-bg); color: var(--lf-success); }
        .status-pill.programada { background: var(--lf-info-bg);    color: var(--lf-info); }
        .status-pill.vencida    { background: var(--lf-danger-bg);  color: var(--lf-danger); }
        .status-pill.inactiva   { background: var(--lf-surface-3);  color: var(--lf-muted); }

        .stat-card-link { cursor: pointer; }
        .stat-card-link .metric-value.verde    { color: var(--lf-success) !important; }
        .stat-card-link .metric-value.amarillo { color: var(--lf-warning) !important; }
        .stat-card-link .metric-value.rojo     { color: var(--lf-danger)  !important; }

        .tipo-section { display: none; }
        .tipo-section.active { display: block; }

        .producto-picker-item {
            padding: 10px;
            border: 1px solid var(--lf-border);
            border-radius: var(--lf-r-sm);
            margin-bottom: 6px;
            cursor: pointer;
            background: var(--lf-surface);
            transition: background .15s ease, border-color .15s ease;
        }
        .producto-picker-item:hover { background: var(--lf-surface-2); }
        .producto-picker-item.selected {
            background: var(--lf-primary-050);
            border-color: var(--primary-color);
        }

        .combo-item {
            background: var(--lf-surface-2);
            padding: 10px;
            border-radius: var(--lf-r-sm);
            margin-bottom: 8px;
            border: 1px solid var(--lf-border);
        }

        .combo-picker {
            background: var(--lf-surface-2);
            border: 1px solid var(--lf-border);
            border-radius: var(--lf-r-sm);
            padding: 10px;
            margin-bottom: 10px;
        }

        .picker-scroll {
            max-height: 260px;
            overflow-y: auto;
            border: 1px solid var(--lf-border);
            border-radius: var(--lf-r);
            padding: 8px;
            background: var(--lf-surface);
        }

        .section-heading {
            color: var(--primary-color);
            border-bottom: 1px solid var(--lf-border);
            padding-bottom: .5rem;
            margin: 1.25rem 0 1rem;
            font-size: .9rem;
            font-weight: 700;
            letter-spacing: -.01em;
        }
        .section-heading:first-child { margin-top: 0; }

        .sin-resultados {
            background: var(--lf-surface-2);
            border-radius: var(--lf-r-sm);
            border: 1px dashed var(--lf-border);
        }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<div class="main-wrapper">

    <!-- Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-percent me-2"></i>Promociones
            </h2>
            <p class="text-muted mb-0 small">Administra descuentos, precios especiales, combos y más.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="productos.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Productos
            </a>
            <button class="btn btn-primary" id="btnNuevaPromocion">
                <i class="fas fa-plus me-2"></i>Nueva Promoción
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['mensaje'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($_SESSION['tipo_mensaje'] ?? 'info'); ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['mensaje']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="row mb-4 g-3">
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card-link" onclick="filtrarPor('todas')">
                <div class="card-body">
                    <div class="metric-label">Total</div>
                    <div class="metric-value text-primary"><?php echo $total_promos; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card-link" onclick="filtrarPor('activas')">
                <div class="card-body">
                    <div class="metric-label">Vigentes</div>
                    <div class="metric-value verde"><?php echo $promos_activas; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card-link" onclick="filtrarPor('programadas')">
                <div class="card-body">
                    <div class="metric-label">Programadas</div>
                    <div class="metric-value text-info"><?php echo $promos_programadas; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card-link" onclick="filtrarPor('vencidas')">
                <div class="card-body">
                    <div class="metric-label">Vencidas</div>
                    <div class="metric-value rojo"><?php echo $promos_vencidas; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="searchPromo"
                            placeholder="Buscar por nombre o descripción..."
                            value="<?php echo htmlspecialchars($filtro_buscar); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select class="form-select" id="filtroEstado">
                        <option value="todas" <?php echo $filtro_estado==='todas'?'selected':''; ?>>Todas</option>
                        <option value="activas" <?php echo $filtro_estado==='activas'?'selected':''; ?>>Vigentes</option>
                        <option value="programadas" <?php echo $filtro_estado==='programadas'?'selected':''; ?>>Programadas</option>
                        <option value="vencidas" <?php echo $filtro_estado==='vencidas'?'selected':''; ?>>Vencidas</option>
                        <option value="inactivas" <?php echo $filtro_estado==='inactivas'?'selected':''; ?>>Inactivas</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-secondary w-100" onclick="limpiarFiltros()">
                        <i class="fas fa-times me-1"></i>Limpiar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Lista -->
    <?php if (empty($promos_data)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-tags"></i>
                    <h5 class="mb-1">No hay promociones registradas</h5>
                    <p class="mb-3">Crea tu primera promoción para empezar a ofrecer descuentos.</p>
                    <button class="btn btn-primary" onclick="document.getElementById('btnNuevaPromocion').click()">
                        <i class="fas fa-plus me-2"></i>Crear Promoción
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($promos_data as $p):
                $now = time();
                $fi = strtotime($p['fecha_inicio']);
                $ff = strtotime($p['fecha_fin']);
                if (!$p['activo']) { $estado_txt='Inactiva'; $estado_cls='inactiva'; }
                elseif ($now < $fi) { $estado_txt='Programada'; $estado_cls='programada'; }
                elseif ($now > $ff) { $estado_txt='Vencida'; $estado_cls='vencida'; }
                else { $estado_txt='Vigente'; $estado_cls='vigente'; }

                $regla_txt = '';
                switch ($p['tipo_promocion']) {
                    case 'descuento_porcentual': $regla_txt = number_format($p['valor_descuento'],0) . '% de descuento'; break;
                    case 'descuento_fijo':       $regla_txt = '$' . number_format($p['valor_descuento'],2) . ' de descuento'; break;
                    case 'precio_especial':      $regla_txt = 'Precio especial: $' . number_format($p['precio_especial'],2); break;
                    case 'llevalo_paga':         $regla_txt = 'Lleva ' . $p['cantidad_lleva'] . ', paga ' . $p['cantidad_paga']; break;
                    case 'precio_volumen':       $regla_txt = 'Desde ' . $p['cantidad_minima_volumen'] . ' pzas a $' . number_format($p['precio_volumen'],2); break;
                    case 'combo':                $regla_txt = 'Combo (' . count($p['combo_data']) . ' productos) a $' . number_format($p['precio_especial'],2); break;
                }
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="card promo-card h-100 <?php echo !$p['activo'] ? 'inactiva' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                            <span class="promo-badge-tipo">
                                <i class="fas fa-tag"></i><?php echo htmlspecialchars(tipoPromoLabel($p['tipo_promocion'])); ?>
                            </span>
                            <span class="status-pill <?php echo $estado_cls; ?>"><?php echo $estado_txt; ?></span>
                        </div>

                        <h5 class="card-title mb-1"><?php echo htmlspecialchars($p['nombre']); ?></h5>
                        <?php if (!empty($p['descripcion'])): ?>
                            <p class="text-muted small mb-2"><?php echo htmlspecialchars($p['descripcion']); ?></p>
                        <?php endif; ?>

                        <div class="mb-2">
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($regla_txt); ?></span>
                        </div>

                        <div class="promo-alcance mb-2">
                            <i class="fas fa-bullseye me-1"></i><?php echo htmlspecialchars(aplicaLabel($p['aplica_a'])); ?>
                            <?php if ($p['aplica_a']==='producto'): ?>
                                (<?php echo (int)$p['total_productos']; ?>)
                            <?php elseif ($p['aplica_a']==='categoria' || $p['aplica_a']==='marca'): ?>
                                (<?php echo (int)$p['total_aplicables']; ?>)
                            <?php endif; ?>
                            &nbsp;·&nbsp;<i class="fas fa-store me-1"></i>
                            <?php if ($p['todas_sucursales']): ?>
                                Todas las sucursales
                            <?php else: ?>
                                <?php echo $p['total_sucursales']; ?> sucursal(es)
                            <?php endif; ?>
                            <?php if ($p['acumulable']): ?>
                                &nbsp;·&nbsp;<i class="fas fa-layer-group me-1 text-success"></i>Acumulable
                            <?php endif; ?>
                        </div>

                        <div class="promo-vigencia mb-3">
                            <i class="far fa-calendar-alt me-1"></i>
                            <?php echo date('d/m/Y H:i', $fi); ?> → <?php echo date('d/m/Y H:i', $ff); ?>
                            <?php if (!empty($p['hora_inicio']) && !empty($p['hora_fin'])): ?>
                                <br><i class="far fa-clock me-1"></i>
                                <?php echo substr($p['hora_inicio'],0,5); ?> - <?php echo substr($p['hora_fin'],0,5); ?>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-1 flex-wrap">
                            <button class="btn btn-sm btn-outline-primary btn-editar"
                                data-promo='<?php echo htmlspecialchars(json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}', ENT_QUOTES); ?>'>
                                <i class="fas fa-edit me-1"></i>Editar
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Cambiar estado de esta promoción?')">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="accion" value="toggle_activo">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-<?php echo $p['activo'] ? 'warning' : 'success'; ?>">
                                    <i class="fas fa-<?php echo $p['activo'] ? 'toggle-on' : 'toggle-off'; ?> me-1"></i>
                                    <?php echo $p['activo'] ? 'Desactivar' : 'Activar'; ?>
                                </button>
                            </form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar definitivamente esta promoción?')">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- =====================================================
     MODAL PROMOCIÓN
====================================================== -->
<div class="modal fade" id="promoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tag me-2"></i><span id="promoModalTitle">Nueva Promoción</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" id="promoForm" novalidate>
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="accion" id="promoAccion" value="crear">
                <input type="hidden" name="id" id="promoId">
                <input type="hidden" name="combo_productos_json" id="comboProductosJson" value="[]">
                <input type="hidden" name="dias_semana" id="diasSemanaHidden" value="">

                <div class="modal-body" id="promoModalBody" style="max-height: 75vh; overflow-y: auto;">

                    <div class="alert alert-danger d-none" id="promoErrores" role="alert"></div>

                    <h6 class="section-heading"><i class="fas fa-info-circle me-1"></i>Información básica</h6>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Nombre de la promoción *</label>
                            <input type="text" class="form-control" name="nombre" id="promoNombre" maxlength="255" placeholder="Ej: 2x1 en bebidas">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Color del badge</label>
                            <input type="color" class="form-control form-control-color w-100" name="color_badge" id="promoColor" value="#667eea">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" name="descripcion" id="promoDescripcion" rows="2" placeholder="Descripción interna o para el cliente"></textarea>
                        </div>
                    </div>

                    <h6 class="section-heading"><i class="fas fa-cog me-1"></i>Tipo y aplicación</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tipo de promoción *</label>
                            <select class="form-select" name="tipo_promocion" id="promoTipo">
                                <option value="descuento_porcentual">Descuento porcentual (%)</option>
                                <option value="descuento_fijo">Descuento monto fijo ($)</option>
                                <option value="precio_especial">Precio especial</option>
                                <option value="llevalo_paga">Lleva N / Paga M (2x1, 3x2…)</option>
                                <option value="precio_volumen">Precio por volumen</option>
                                <option value="combo">Combo / Paquete</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Aplica a *</label>
                            <select class="form-select" name="aplica_a" id="promoAplicaA">
                                <option value="producto">Producto(s) específico(s)</option>
                                <option value="categoria">Categoría(s)</option>
                                <option value="marca">Marca(s)</option>
                                <option value="venta_completa">Toda la venta</option>
                                <option value="combo">Combo (productos agrupados)</option>
                            </select>
                            <small class="form-text">Las opciones disponibles dependen del tipo de promoción.</small>
                        </div>
                    </div>

                    <div class="tipo-section" data-tipo="descuento_porcentual">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Porcentaje de descuento (%) *</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="valor_descuento" id="valorDescuentoPct" min="0.01" max="100" step="0.01" placeholder="Ej: 15">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tipo-section" data-tipo="descuento_fijo">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Monto de descuento ($) *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="valor_descuento" id="valorDescuentoFijo" min="0.01" step="0.01" placeholder="Ej: 10.00">
                                </div>
                                <small class="form-text">En producto, categoría o marca se descuenta por pieza; en "Toda la venta" se descuenta del total.</small>
                            </div>
                        </div>
                    </div>

                    <div class="tipo-section" data-tipo="precio_especial">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Precio especial ($) *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="precio_especial" id="precioEspecial" min="0.01" step="0.01" placeholder="Ej: 49.90">
                                </div>
                                <small class="form-text">El producto se venderá a este precio durante la vigencia.</small>
                            </div>
                        </div>
                    </div>

                    <div class="tipo-section" data-tipo="llevalo_paga">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Cantidad que lleva *</label>
                                <input type="number" class="form-control" name="cantidad_lleva" id="cantidadLleva" min="2" step="1" placeholder="Ej: 2">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Cantidad que paga *</label>
                                <input type="number" class="form-control" name="cantidad_paga" id="cantidadPaga" min="1" step="1" placeholder="Ej: 1">
                            </div>
                            <div class="col-md-6 mb-3 d-flex align-items-end">
                                <div class="alert alert-info py-2 mb-0 small w-100">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Ejemplo: 2x1 → lleva 2, paga 1. · 3x2 → lleva 3, paga 2.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tipo-section" data-tipo="precio_volumen">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cantidad mínima *</label>
                                <input type="number" class="form-control" name="cantidad_minima_volumen" id="cantidadMinVolumen" min="1" step="1" placeholder="Ej: 6">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Precio unitario ($) *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="precio_volumen" id="precioVolumen" min="0.01" step="0.01" placeholder="Ej: 12.50">
                                </div>
                            </div>
                            <div class="col-md-4 mb-3 d-flex align-items-end">
                                <div class="alert alert-info py-2 mb-0 small w-100">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Desde N piezas el precio unitario baja. Para varios escalones crea una promoción por escalón.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tipo-section" data-tipo="combo">
                        <div class="row">
                            <div class="col-md-5 mb-3">
                                <label class="form-label">Precio del combo ($) *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="precio_especial" id="precioCombo" min="0.01" step="0.01" placeholder="Ej: 99.00">
                                </div>
                                <small class="form-text">Precio total al llevar todos los productos del combo. Debe ser menor a la suma de sus precios normales.</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Productos del combo *</label>
                            <div class="combo-picker">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <input type="text" class="form-control" id="comboBuscar" placeholder="Buscar por nombre o código...">
                                    </div>
                                    <div class="col-md-5">
                                        <select class="form-select" id="comboSelect"></select>
                                    </div>
                                    <div class="col-4 col-md-1">
                                        <input type="number" class="form-control" id="comboCant" min="1" step="1" value="1" title="Cantidad">
                                    </div>
                                    <div class="col-8 col-md-2">
                                        <button type="button" class="btn btn-outline-primary w-100" id="btnAgregarCombo">
                                            <i class="fas fa-plus me-1"></i>Agregar
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div id="comboContainer">
                                <div class="text-muted small">Aún no hay productos en el combo.</div>
                            </div>
                            <div class="small mt-2" id="comboResumen"></div>
                        </div>
                    </div>

                    <h6 class="section-heading"><i class="fas fa-calendar-alt me-1"></i>Vigencia</h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Fecha y hora inicio *</label>
                            <input type="datetime-local" class="form-control" name="fecha_inicio" id="fechaInicio">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Fecha y hora fin *</label>
                            <input type="datetime-local" class="form-control" name="fecha_fin" id="fechaFin">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Hora inicio (opcional)</label>
                            <input type="time" class="form-control" name="hora_inicio" id="horaInicio">
                            <small class="form-text">Filtro dentro del horario diario</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Hora fin (opcional)</label>
                            <input type="time" class="form-control" name="hora_fin" id="horaFin">
                            <small class="form-text">Si es menor que la de inicio, cruza medianoche</small>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Días de la semana (opcional)</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php
                                $dias = ['1'=>'Lun','2'=>'Mar','3'=>'Mié','4'=>'Jue','5'=>'Vie','6'=>'Sáb','7'=>'Dom'];
                                foreach ($dias as $k=>$v): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input dia-semana" type="checkbox" value="<?php echo $k; ?>" id="dia_<?php echo $k; ?>">
                                        <label class="form-check-label" for="dia_<?php echo $k; ?>"><?php echo $v; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="form-text">Si no marcas ninguno, aplica todos los días.</small>
                        </div>
                    </div>

                    <h6 class="section-heading"><i class="fas fa-store me-1"></i>Alcance por sucursal</h6>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="todas_sucursales" id="todasSucursales" checked>
                                <label class="form-check-label" for="todasSucursales">
                                    <strong>Aplicar en todas las sucursales</strong>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-12 mb-3" id="sucursalesLista" style="display: none;">
                            <?php foreach ($sucursales as $s): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input sucursal-chk" type="checkbox" name="sucursales_aplicables[]" value="<?php echo (int)$s['id']; ?>" id="suc_<?php echo (int)$s['id']; ?>">
                                    <label class="form-check-label" for="suc_<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="aplicablesProducto" class="aplicables-block" style="display:none;">
                        <h6 class="section-heading"><i class="fas fa-box me-1"></i>Productos aplicables</h6>
                        <input type="text" class="form-control mb-2" id="buscarProductoAplic" placeholder="Buscar por nombre o código...">
                        <div class="picker-scroll" id="listaProductosAplic">
                            <?php foreach ($productos as $prod): ?>
                                <label class="producto-picker-item d-flex align-items-center gap-2">
                                    <input type="checkbox" class="form-check-input chk-producto" name="productos_aplicables[]" value="<?php echo (int)$prod['id']; ?>">
                                    <div class="flex-grow-1">
                                        <strong><?php echo htmlspecialchars($prod['nombre']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($prod['codigo']); ?> · $<?php echo number_format($prod['precio'],2); ?></small>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="form-text d-block mt-2">Selecciona uno o más productos a los que aplica la promoción.</small>
                    </div>

                    <div id="aplicablesCategoria" class="aplicables-block" style="display:none;">
                        <h6 class="section-heading"><i class="fas fa-folder me-1"></i>Categorías aplicables</h6>
                        <div class="picker-scroll">
                            <?php foreach ($categorias as $c): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input chk-categoria" type="checkbox" name="categorias_aplicables[]" value="<?php echo (int)$c['id']; ?>" id="cat_<?php echo (int)$c['id']; ?>">
                                    <label class="form-check-label" for="cat_<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="aplicablesMarca" class="aplicables-block" style="display:none;">
                        <h6 class="section-heading"><i class="fas fa-bookmark me-1"></i>Marcas aplicables</h6>
                        <input type="text" class="form-control" name="marcas_aplicables_texto" id="marcasAplicTexto" placeholder="Ej: Coca-Cola, Sony, Samsung (separadas por coma)">
                        <small class="form-text">Escribe las marcas separadas por coma.</small>
                    </div>

                    <h6 class="section-heading"><i class="fas fa-filter me-1"></i>Condiciones opcionales</h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Cantidad mínima de productos</label>
                            <input type="number" class="form-control" name="cantidad_minima" id="cantidadMinima" min="0" step="1" value="0" placeholder="0 = sin mínimo">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Monto mínimo de compra ($)</label>
                            <input type="number" class="form-control" name="monto_minimo" id="montoMinimo" min="0" step="0.01" value="0" placeholder="0 = sin mínimo">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Método de pago</label>
                            <select class="form-select" name="metodo_pago" id="metodoPago">
                                <option value="">Cualquiera</option>
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Tipo de cliente</label>
                            <select class="form-select" name="tipo_cliente" id="tipoCliente">
                                <option value="">Cualquiera</option>
                                <option value="publico">Público general</option>
                                <option value="mayorista">Mayorista</option>
                                <option value="vip">VIP</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="section-heading"><i class="fas fa-sliders-h me-1"></i>Comportamiento</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Prioridad (menor = más alta)</label>
                            <input type="number" class="form-control" name="prioridad" id="prioridad" min="1" max="999" value="10">
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="acumulable" id="acumulable">
                                <label class="form-check-label" for="acumulable">
                                    <strong>Acumulable con otras promociones</strong>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="activo" id="activo" checked>
                                <label class="form-check-label" for="activo">
                                    <strong>Promoción activa</strong>
                                </label>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// ========== FILTROS ==========
function filtrarPor(estado) {
    const url = new URL(window.location.href);
    url.searchParams.set('estado', estado);
    window.location.href = url.toString();
}
function limpiarFiltros() {
    window.location.href = 'promociones.php';
}

$(function() {
    const productosCatalogo = <?php echo json_encode($productos, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]'; ?>;
    const APLICA_PERMITIDO  = <?php echo json_encode(APLICA_PERMITIDO) ?: '{}'; ?>;
    const promoModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('promoModal'));

    // =============================================
    // UTILIDADES
    // =============================================
    function normalizarTexto(str) {
        return (str || '').toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }
    const fmtMoney = n => n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const nz = v => (parseFloat(v) > 0 ? v : '');
    const fmtLocal = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

    $('#searchPromo').on('keypress', function(e) {
        if (e.which === 13) {
            const url = new URL(window.location.href);
            url.searchParams.set('q', $(this).val());
            window.location.href = url.toString();
        }
    });
    $('#filtroEstado').on('change', function() {
        filtrarPor($(this).val());
    });

    $('#buscarProductoAplic, #comboBuscar, #comboCant').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (this.id === 'comboCant') $('#btnAgregarCombo').click();
        }
    });

    // =============================================
    // SECCIÓN DINÁMICA POR TIPO
    // =============================================
    function mostrarSeccionTipo(tipo) {
        $('.tipo-section').removeClass('active').find(':input').prop('disabled', true);
        $('.tipo-section[data-tipo="' + tipo + '"]').addClass('active').find(':input').prop('disabled', false);
    }

    function mostrarAplicables(aplicaA) {
        $('.aplicables-block').hide();
        if (aplicaA === 'producto') $('#aplicablesProducto').show();
        else if (aplicaA === 'categoria') $('#aplicablesCategoria').show();
        else if (aplicaA === 'marca') $('#aplicablesMarca').show();
    }

    function ajustarAplicaA(tipo) {
        const permitidos = APLICA_PERMITIDO[tipo] || [];
        const $sel = $('#promoAplicaA');
        $sel.find('option').each(function() {
            const ok = permitidos.indexOf(this.value) !== -1;
            $(this).prop('disabled', !ok).prop('hidden', !ok);
        });
        if (permitidos.length && permitidos.indexOf($sel.val()) === -1) {
            $sel.val(permitidos[0]);
        }
        mostrarAplicables($sel.val());
    }

    $('#promoTipo').on('change', function() {
        const tipo = $(this).val();
        mostrarSeccionTipo(tipo);
        ajustarAplicaA(tipo);
        actualizarResumenCombo();
    });
    $('#promoAplicaA').on('change', function() {
        mostrarAplicables($(this).val());
    });

    // ========== BUSCADOR DE PRODUCTOS APLICABLES ==========
    $('#buscarProductoAplic').on('input', function() {
        const q = normalizarTexto($(this).val());
        let visibles = 0;

        $('#listaProductosAplic .producto-picker-item').each(function() {
            const texto = normalizarTexto($(this).text());
            const coincide = q === '' || texto.includes(q);
            $(this).toggle(coincide);
            if (coincide) visibles++;
        });

        let $vacio = $('#listaProductosAplic .sin-resultados');
        if (visibles === 0 && q !== '') {
            if ($vacio.length === 0) {
                $('#listaProductosAplic').append(
                    '<div class="sin-resultados text-muted small text-center py-3">' +
                    '<i class="fas fa-search me-1"></i>Sin resultados para "<span></span>"' +
                    '</div>'
                );
                $vacio = $('#listaProductosAplic .sin-resultados');
            }
            $vacio.find('span').text($(this).val());
            $vacio.show();
        } else if ($vacio.length) {
            $vacio.hide();
        }
    });

    $(document).on('change', '.chk-producto', function() {
        $(this).closest('.producto-picker-item').toggleClass('selected', $(this).is(':checked'));
    });

    // ========== SUCURSALES ==========
    $('#todasSucursales').on('change', function() {
        if ($(this).is(':checked')) $('#sucursalesLista').hide();
        else $('#sucursalesLista').show();
    });

    // =============================================
    // COMBO
    // =============================================
    let comboItems = [];

    function precioProducto(id) {
        const p = productosCatalogo.find(x => x.id == id);
        return p ? (parseFloat(p.precio) || 0) : 0;
    }
    function sumaCombo() {
        return comboItems.reduce((acc, it) => acc + precioProducto(it.producto_id) * it.cantidad, 0);
    }

    function poblarComboSelect(q) {
        const nq = normalizarTexto(q);
        const $s = $('#comboSelect').empty();
        let n = 0;
        for (const p of productosCatalogo) {
            if (nq && !normalizarTexto(p.nombre + ' ' + p.codigo).includes(nq)) continue;
            $s.append($('<option>').val(p.id).text(p.nombre + ' (' + p.codigo + ') · $' + fmtMoney(parseFloat(p.precio) || 0)));
            if (++n >= 100) break;
        }
        if (n === 0) $s.append($('<option value="" disabled selected>').text('Sin resultados'));
    }

    function actualizarResumenCombo() {
        const $r = $('#comboResumen').removeClass('text-danger text-success text-muted');
        if (comboItems.length === 0) { $r.text(''); return; }
        const suma = sumaCombo();
        const precio = parseFloat($('#precioCombo').val()) || 0;
        let txt = 'Suma de precios normales: $' + fmtMoney(suma);
        if (precio > 0 && suma > 0) {
            if (precio >= suma) {
                txt += ' · El precio del combo debe ser menor a esa suma';
                $r.addClass('text-danger');
            } else {
                const ahorro = suma - precio;
                txt += ' · Ahorro para el cliente: $' + fmtMoney(ahorro) + ' (' + Math.round(ahorro / suma * 100) + '%)';
                $r.addClass('text-success');
            }
        } else {
            $r.addClass('text-muted');
        }
        $r.text(txt);
    }

    function renderCombo() {
        const $c = $('#comboContainer').empty();
        if (comboItems.length === 0) {
            $c.html('<div class="text-muted small">Aún no hay productos en el combo.</div>');
        } else {
            comboItems.forEach((item, idx) => {
                const prod = productosCatalogo.find(p => p.id == item.producto_id);
                const $row  = $('<div class="combo-item d-flex align-items-center gap-2">');
                const $info = $('<div class="flex-grow-1">');
                $info.append($('<strong>').text(prod ? prod.nombre : '(producto no disponible)'));
                $info.append($('<small class="text-muted d-block">').text(prod ? prod.codigo + ' · $' + fmtMoney(parseFloat(prod.precio) || 0) : ''));
                const $cant = $('<input type="number" class="form-control form-control-sm combo-cant" min="1">').attr('data-idx', idx).val(item.cantidad);
                const $del  = $('<button type="button" class="btn btn-sm btn-outline-danger combo-del"><i class="fas fa-times"></i></button>').attr('data-idx', idx);
                $row.append($info, $('<div style="width:100px;">').append($cant), $del);
                $c.append($row);
            });
        }
        $('#comboProductosJson').val(JSON.stringify(comboItems));
        actualizarResumenCombo();
    }

    $('#comboBuscar').on('input', function() { poblarComboSelect($(this).val()); });
    $('#precioCombo').on('input', actualizarResumenCombo);

    $('#btnAgregarCombo').on('click', function() {
        const pid  = parseInt($('#comboSelect').val());
        const cant = Math.max(1, parseInt($('#comboCant').val()) || 1);
        if (!pid) return;
        const existente = comboItems.find(i => i.producto_id === pid);
        if (existente) existente.cantidad += cant;
        else comboItems.push({ producto_id: pid, cantidad: cant });
        $('#comboCant').val(1);
        renderCombo();
    });

    $(document).on('click', '.combo-del', function() {
        comboItems.splice(parseInt($(this).attr('data-idx')), 1);
        renderCombo();
    });
    $(document).on('input change', '.combo-cant', function() {
        const idx = parseInt($(this).attr('data-idx'));
        if (comboItems[idx]) comboItems[idx].cantidad = Math.max(1, parseInt($(this).val()) || 1);
        $('#comboProductosJson').val(JSON.stringify(comboItems));
        actualizarResumenCombo();
    });

    // =============================================
    // VALIDACIÓN EN CLIENTE
    // =============================================
    function mostrarErrores(lista) {
        const $ul = $('<ul class="mb-0">');
        lista.forEach(m => $ul.append($('<li>').text(m)));
        $('#promoErrores').removeClass('d-none').empty().append($ul);
        $('#promoModalBody').scrollTop(0);
    }
    function ocultarErrores() {
        $('#promoErrores').addClass('d-none').empty();
    }

    function validarFormulario() {
        const err = [];
        const tipo   = $('#promoTipo').val();
        const aplica = $('#promoAplicaA').val();
        const num = sel => parseFloat($(sel).val()) || 0;
        const ent = sel => parseInt($(sel).val()) || 0;

        if (!$('#promoNombre').val().trim()) err.push('El nombre es obligatorio');

        const permitidos = APLICA_PERMITIDO[tipo] || [];
        if (permitidos.indexOf(aplica) === -1) err.push('La combinación de tipo de promoción y "Aplica a" no es válida');

        const fi = $('#fechaInicio').val(), ff = $('#fechaFin').val();
        if (!fi || !ff) err.push('Las fechas de inicio y fin son obligatorias');
        else if (fi >= ff) err.push('La fecha de fin debe ser posterior a la fecha de inicio');

        const hi = $('#horaInicio').val(), hf = $('#horaFin').val();
        if ((hi && !hf) || (!hi && hf)) err.push('Captura hora de inicio y hora de fin, o deja ambas vacías');
        else if (hi && hf && hi === hf) err.push('La hora de inicio y la hora de fin no pueden ser iguales');

        switch (tipo) {
            case 'descuento_porcentual': {
                const v = num('#valorDescuentoPct');
                if (v <= 0 || v > 100) err.push('El % de descuento debe ser mayor a 0 y máximo 100');
                break;
            }
            case 'descuento_fijo':
                if (num('#valorDescuentoFijo') <= 0) err.push('El monto de descuento debe ser mayor a 0');
                break;
            case 'precio_especial':
                if (num('#precioEspecial') <= 0) err.push('El precio especial debe ser mayor a 0');
                break;
            case 'llevalo_paga': {
                const lleva = ent('#cantidadLleva'), paga = ent('#cantidadPaga');
                if (lleva <= 0 || paga <= 0) err.push('Las cantidades de "lleva" y "paga" deben ser mayores a 0');
                else if (paga >= lleva) err.push('La cantidad a pagar debe ser menor que la cantidad a llevar');
                break;
            }
            case 'precio_volumen':
                if (ent('#cantidadMinVolumen') <= 0 || num('#precioVolumen') <= 0) err.push('Cantidad mínima y precio por volumen son requeridos');
                break;
            case 'combo': {
                if (comboItems.length === 0) err.push('Agrega al menos un producto al combo');
                const precio = num('#precioCombo');
                if (precio <= 0) err.push('El precio del combo debe ser mayor a 0');
                else if (comboItems.length && sumaCombo() > 0 && precio >= sumaCombo()) {
                    err.push('El precio del combo ($' + fmtMoney(precio) + ') debe ser menor a la suma de los precios normales ($' + fmtMoney(sumaCombo()) + ')');
                }
                break;
            }
        }

        if (aplica === 'producto' && $('.chk-producto:checked').length === 0) err.push('Selecciona al menos un producto');
        if (aplica === 'categoria' && $('.chk-categoria:checked').length === 0) err.push('Selecciona al menos una categoría');
        if (aplica === 'marca' && !$('#marcasAplicTexto').val().split(',').some(m => m.trim() !== '')) err.push('Especifica al menos una marca');

        if (!$('#todasSucursales').is(':checked') && $('.sucursal-chk:checked').length === 0) {
            err.push('Selecciona al menos una sucursal');
        }
        return err;
    }

    // =============================================
    // ABRIR MODAL: NUEVA / EDITAR
    // =============================================
    function resetFormulario() {
        $('#promoForm')[0].reset();
        ocultarErrores();
        comboItems = [];
        $('#comboBuscar').val('');
        $('#comboCant').val(1);
        poblarComboSelect('');
        renderCombo();

        $('.chk-producto, .chk-categoria, .sucursal-chk, .dia-semana').prop('checked', false);
        $('.producto-picker-item').removeClass('selected');
        $('#todasSucursales').prop('checked', true);
        $('#sucursalesLista').hide();
        $('#activo').prop('checked', true);

        $('#buscarProductoAplic').val('');
        $('#listaProductosAplic .producto-picker-item').show();
        $('#listaProductosAplic .sin-resultados').hide();
    }

    $('#btnNuevaPromocion').on('click', function() {
        resetFormulario();
        $('#promoAccion').val('crear');
        $('#promoId').val('');
        $('#promoModalTitle').text('Nueva Promoción');

        $('#promoTipo').val('descuento_porcentual').trigger('change');
        $('#promoAplicaA').val('producto');
        mostrarAplicables('producto');

        const ahora = new Date();
        const en30  = new Date(); en30.setDate(ahora.getDate() + 30);
        $('#fechaInicio').val(fmtLocal(ahora));
        $('#fechaFin').val(fmtLocal(en30));
        $('#promoColor').val('#667eea');

        promoModal.show();
    });

    $('.btn-editar').on('click', function() {
        const p = JSON.parse($(this).attr('data-promo'));
        resetFormulario();
        $('#promoAccion').val('editar');
        $('#promoId').val(p.id);
        $('#promoModalTitle').text('Editar Promoción');

        $('#promoNombre').val(p.nombre);
        $('#promoDescripcion').val(p.descripcion || '');
        $('#promoColor').val(p.color_badge || '#667eea');

        $('#promoTipo').val(p.tipo_promocion).trigger('change');
        $('#promoAplicaA').val(p.aplica_a);
        mostrarAplicables(p.aplica_a);

        $('#valorDescuentoPct').val(nz(p.valor_descuento));
        $('#valorDescuentoFijo').val(nz(p.valor_descuento));
        $('#precioEspecial').val(nz(p.precio_especial));
        $('#precioCombo').val(nz(p.precio_especial));
        $('#cantidadLleva').val(nz(p.cantidad_lleva));
        $('#cantidadPaga').val(nz(p.cantidad_paga));
        $('#cantidadMinVolumen').val(nz(p.cantidad_minima_volumen));
        $('#precioVolumen').val(nz(p.precio_volumen));

        $('#fechaInicio').val((p.fecha_inicio || '').slice(0, 16).replace(' ', 'T'));
        $('#fechaFin').val((p.fecha_fin || '').slice(0, 16).replace(' ', 'T'));
        $('#horaInicio').val((p.hora_inicio || '').slice(0, 5));
        $('#horaFin').val((p.hora_fin || '').slice(0, 5));

        if (p.dias_semana) {
            p.dias_semana.split(',').forEach(d => $('#dia_' + d.trim()).prop('checked', true));
        }

        if (p.todas_sucursales == 1) {
            $('#todasSucursales').prop('checked', true);
            $('#sucursalesLista').hide();
        } else {
            $('#todasSucursales').prop('checked', false);
            $('#sucursalesLista').show();
            (p.sucursales_data || []).forEach(sid => $('#suc_' + sid).prop('checked', true));
        }

        (p.aplicables_data?.productos || []).forEach(pid => {
            $('.chk-producto[value="' + pid + '"]').prop('checked', true)
                .closest('.producto-picker-item').addClass('selected');
        });
        (p.aplicables_data?.categorias || []).forEach(cid => {
            $('.chk-categoria[value="' + cid + '"]').prop('checked', true);
        });
        $('#marcasAplicTexto').val((p.aplicables_data?.marcas || []).join(', '));

        $('#cantidadMinima').val(p.cantidad_minima || 0);
        $('#montoMinimo').val(p.monto_minimo || 0);
        $('#metodoPago').val(p.metodo_pago || '');
        $('#tipoCliente').val(p.tipo_cliente || '');

        $('#prioridad').val(p.prioridad || 10);
        $('#acumulable').prop('checked', p.acumulable == 1);
        $('#activo').prop('checked', p.activo == 1);

        comboItems = (p.combo_data || []).map(c => ({
            producto_id: parseInt(c.producto_id),
            cantidad: parseInt(c.cantidad)
        }));
        renderCombo();

        promoModal.show();
    });

    // ========== SUBMIT ==========
    $('#promoForm').on('submit', function(e) {
        $('#diasSemanaHidden').val($('.dia-semana:checked').map((i, el) => el.value).get().join(','));

        const errores = validarFormulario();
        if (errores.length) {
            e.preventDefault();
            mostrarErrores(errores);
            return false;
        }
        ocultarErrores();
    });

    // ========== ESTADO INICIAL ==========
    poblarComboSelect('');
    mostrarSeccionTipo($('#promoTipo').val());
    ajustarAplicaA($('#promoTipo').val());
});
</script>
</body>
</html>