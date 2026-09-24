<?php
// =============================================
// SESIÓN Y ENTORNO
// =============================================
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';
require 'vendor/autoload.php';

use Facturapi\Facturapi;

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Login");
    exit();
}

// =============================================
// CONSTANTES
// =============================================
define('PRODUCTOS_POR_PAGINA', 5);
define('MAX_IMAGENES_PRODUCTO', 5);
define('MAX_TAMANO_IMAGEN', 4 * 1024 * 1024);
define('RUTA_UPLOADS_RELATIVA', '/uploads/productos/');
define('FACTURAPI_API_KEY', 'sk_user_LV9Sw1JcA15AUyxSfD53ntQH6sCMiYmRRMP6tpJCi2');

$PLANES_LIMITES = [
    'prueba'      => 100,
    'basico'      => 100,
    'emprendedor' => 500,
    'premium'     => PHP_INT_MAX,
];

$UNIDADES_CONFIG = [
    'pieza'  => ['product_key' => '43211508', 'unit_key' => 'H87', 'unit_name' => 'Pieza',     'sufijo' => ' piezas'],
    'kilo'   => ['product_key' => '43211601', 'unit_key' => 'KG',  'unit_name' => 'Kilogramo', 'sufijo' => ' kg'],
    'litro'  => ['product_key' => '43211602', 'unit_key' => 'LTR', 'unit_name' => 'Litro',     'sufijo' => ' L'],
];

// =============================================
// VARIABLES GLOBALES
// =============================================
$empresa_plan         = 'prueba';
$timbres_disponibles  = 0;
$timbres_totales      = 0;
$terminal_emida       = null;
$notification_status  = null;
$organization_id      = null;
$test_api_key_working = null;
$stock_minimo_global  = 5;

// =============================================
// CARACTERÍSTICAS DE LA EMPRESA (default)
// =============================================
$caracteristicas = [
    'precio_compra'    => true,
    'unidad_medida'    => true,
    'proveedor'        => true,
    'fecha_caducidad'  => true,
    'categoria'        => true,
    'tipo_producto'    => true,
    'merma'            => true,
];
$tipos_unidad_permitidos   = ['pieza', 'kilo', 'litro'];
$tipos_producto_permitidos = ['Estandar', 'Premium', 'Económico'];
$config_merma = [
    'porcentaje_danado'         => 0,
    'porcentaje_deshidratacion' => 0,
    'aplicar_merma_venta'       => 0,
    'aplicar_merma_compra'      => 0,
];

// =============================================
// CONEXIÓN ÚNICA A BD PRINCIPAL (PDO)
// =============================================
$conn_main = null;
try {
    $conn_main = getDBConnection();

    // Datos de empresa
    $stmt = $conn_main->prepare("
        SELECT plan, facturapi_organization_id, timbres_totales, timbres_disponibles, terminal_emida
        FROM empresas WHERE id = ?
    ");
    $stmt->execute([$_SESSION['empresa_id']]);
    $empresa_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;

    if ($empresa_data) {
        $empresa_plan         = $empresa_data['plan'] ?? 'prueba';
        $organization_id      = $empresa_data['facturapi_organization_id'] ?? null;
        $timbres_totales      = $empresa_data['timbres_totales'] ?? 0;
        $timbres_disponibles  = $empresa_data['timbres_disponibles'] ?? 0;
        $terminal_emida       = $empresa_data['terminal_emida'] ?? null;
    }

    // Características
    $stmt = $conn_main->prepare("
        SELECT caracteristica, habilitado, configuracion_extra
        FROM empresa_caracteristicas
        WHERE empresa_id = ?
    ");
    $stmt->execute([$_SESSION['empresa_id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!array_key_exists($row['caracteristica'], $caracteristicas)) continue;
        $caracteristicas[$row['caracteristica']] = (bool)$row['habilitado'];

        if (!empty($row['configuracion_extra'])) {
            $extra = json_decode($row['configuracion_extra'], true);
            if (!is_array($extra)) continue;

            if ($row['caracteristica'] === 'unidad_medida' && !empty($extra)) {
                $tipos_unidad_permitidos = $extra;
            } elseif ($row['caracteristica'] === 'tipo_producto' && !empty($extra)) {
                $tipos_producto_permitidos = $extra;
            } elseif ($row['caracteristica'] === 'merma') {
                $config_merma = array_merge($config_merma, $extra);
            }
        }
    }
    $stmt = null;

    // Notificaciones Emida
    if (file_exists(__DIR__ . '/../EmidaServicios/config.php')) {
        require_once __DIR__ . '/../EmidaServicios/config.php';
        if (function_exists('getNotificationStatus')) {
            $notification_status = getNotificationStatus($conn_main);
        }
    }
} catch (Exception $e) {
    error_log("Error conexión/config BD principal: " . $e->getMessage());
}

// Aliases para template
$mostrar_precio_compra    = $caracteristicas['precio_compra'];
$mostrar_unidad_medida    = $caracteristicas['unidad_medida'];
$mostrar_proveedor        = $caracteristicas['proveedor'];
$mostrar_fecha_caducidad  = $caracteristicas['fecha_caducidad'];
$mostrar_categoria        = $caracteristicas['categoria'];
$mostrar_tipo_producto    = $caracteristicas['tipo_producto'];
$mostrar_merma            = $caracteristicas['merma'];

if (!$mostrar_unidad_medida) $tipos_unidad_permitidos = ['pieza'];
if (!$mostrar_tipo_producto) $tipos_producto_permitidos = ['Estandar'];

$hide_precio_compra_style   = $mostrar_precio_compra    ? '' : 'style="display: none;"';
$hide_unidad_medida_style   = $mostrar_unidad_medida    ? '' : 'style="display: none;"';
$hide_proveedor_style       = $mostrar_proveedor        ? '' : 'style="display: none;"';
$hide_fecha_caducidad_style = $mostrar_fecha_caducidad  ? '' : 'style="display: none;"';
$hide_categoria_style       = $mostrar_categoria        ? '' : 'style="display: none;"';
$hide_tipo_producto_style   = $mostrar_tipo_producto    ? '' : 'style="display: none;"';
$hide_merma_style           = $mostrar_merma            ? '' : 'style="display: none;"';

// =============================================
// FACTURAPI (solo si hay organización)
// =============================================
if (!empty($organization_id)) {
    try {
        $facturapi = new Facturapi(FACTURAPI_API_KEY);
        $organizacion = $facturapi->Organizations->retrieve($organization_id);
        try {
            $test_api_key_working = $facturapi->Organizations->getTestApiKey($organization_id);
            $_SESSION['test_api_key'] = $test_api_key_working;
        } catch (Exception $e) {
            error_log("Error getTestApiKey: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log("Error Facturapi: " . $e->getMessage());
    }
}

// =============================================
// HELPERS — IMÁGENES
// =============================================
function resolverDirectorioUploads(): array
{
    $candidatos = [
        $_SERVER['DOCUMENT_ROOT'] . RUTA_UPLOADS_RELATIVA => RUTA_UPLOADS_RELATIVA,
        dirname(__FILE__) . '/uploads/productos/'        => 'uploads/productos/',
    ];
    foreach ($candidatos as $abs => $rel) {
        if (!is_dir($abs) && !@mkdir($abs, 0777, true)) continue;
        if (is_writable($abs)) return ['abs' => rtrim($abs, '/') . '/', 'rel' => $rel];
    }
    return ['abs' => null, 'rel' => RUTA_UPLOADS_RELATIVA];
}

function subirMultiplesImagenes(array $files, int $producto_id): array
{
    $rutas = [];
    if (empty($files['imagenes']['tmp_name'][0])) return $rutas;

    $dir = resolverDirectorioUploads();
    if (!$dir['abs']) {
        error_log("No hay directorio escribible para uploads");
        return $rutas;
    }

    $tipos_permitidos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $total = min(count($files['imagenes']['tmp_name']), MAX_IMAGENES_PRODUCTO);

    for ($i = 0; $i < $total; $i++) {
        if ($files['imagenes']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['imagenes']['size'][$i] > MAX_TAMANO_IMAGEN) continue;

        $mime = mime_content_type($files['imagenes']['tmp_name'][$i]);
        if (!in_array($mime, $tipos_permitidos, true)) continue;

        $ext = strtolower(pathinfo($files['imagenes']['name'][$i], PATHINFO_EXTENSION));
        $nombre = "producto_{$producto_id}_" . time() . "_" . bin2hex(random_bytes(4)) . ".{$ext}";

        if (move_uploaded_file($files['imagenes']['tmp_name'][$i], $dir['abs'] . $nombre)) {
            $rutas[] = $dir['rel'] . $nombre;
        }
    }
    return $rutas;
}

function guardarImagenesProducto(PDO $conn, int $producto_id, array $imagenes, int $principal_index = 0): int
{
    try {
        $conn->prepare("DELETE FROM producto_imagenes WHERE producto_id = ?")->execute([$producto_id]);
        if (empty($imagenes)) return 0;

        $stmt = $conn->prepare("
            INSERT INTO producto_imagenes (producto_id, ruta_imagen, orden, es_principal)
            VALUES (?, ?, ?, ?)
        ");
        $insertados = 0;
        foreach ($imagenes as $i => $ruta) {
            $ruta = str_replace('//', '/', $ruta);
            $stmt->execute([$producto_id, $ruta, $i, $i === $principal_index ? 1 : 0]);
            $insertados++;
        }
        return $insertados;
    } catch (Exception $e) {
        error_log("guardarImagenesProducto: " . $e->getMessage());
        return 0;
    }
}

function obtenerImagenesProducto(PDO $conn, int $producto_id): array
{
    $stmt = $conn->prepare("
        SELECT id, ruta_imagen, orden, es_principal
        FROM producto_imagenes
        WHERE producto_id = ?
        ORDER BY es_principal DESC, orden ASC
    ");
    $stmt->execute([$producto_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =============================================
// HELPERS — NEGOCIO
// =============================================
function verificarLimiteProductos(PDO $conn, string $plan, array $planes_limites): array
{
    $total = (int)$conn->query("SELECT COUNT(*) FROM productos WHERE activo = 1")->fetchColumn();
    $limite = $planes_limites[$plan] ?? 100;

    return [
        'total'       => $total,
        'limite'      => $limite,
        'disponibles' => max(0, $limite - $total),
        'alcanzado'   => $total >= $limite,
    ];
}

function generarCodigoAutomatico(PDO $conn, string $prefijo = 'PROD'): string
{
    $stmt = $conn->prepare("
        SELECT MAX(CAST(SUBSTRING(codigo, LENGTH(?) + 1) AS UNSIGNED))
        FROM productos
        WHERE codigo LIKE CONCAT(?, '%')
          AND codigo REGEXP CONCAT('^', ?, '[0-9]+$')
    ");
    $stmt->execute([$prefijo, $prefijo, $prefijo]);
    $ultimo = (int)$stmt->fetchColumn();
    $nuevo = sprintf('%s%04d', $prefijo, $ultimo + 1);

    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM productos WHERE codigo = ?");
    $stmt_check->execute([$nuevo]);
    if ((int)$stmt_check->fetchColumn() > 0) {
        return generarCodigoAutomatico($conn, $prefijo);
    }
    return $nuevo;
}

function formatearStockPorUnidad($stock, string $unidad_medida): string
{
    if (!is_numeric($stock)) return '0';

    $es_decimal = ($stock - floor($stock)) > 0;
    $num = $es_decimal
        ? rtrim(rtrim(number_format((float)$stock, 3, '.', ''), '0'), '.')
        : number_format((float)$stock, 0, '.', '');

    $sufijos = [
        'kg' => ' kg', 'kilo' => ' kg', 'kilogramo' => ' kg',
        'litro' => ' L', 'l' => ' L',
        'tonelada' => ' ton', 'ton' => ' ton',
        'pieza' => ' piezas', 'unidad' => ' unidades',
    ];
    $sufijo = $sufijos[$unidad_medida] ?? '';
    if (in_array($unidad_medida, ['pieza', 'unidad'], true) && (float)$stock === 1.0) {
        $sufijo = rtrim($sufijo, 's');
    }
    return $num . $sufijo;
}

// =============================================
// FACTURAPI — crear / actualizar
// =============================================
function construirFacturapiData(array $productoData, array $unidades_config): array
{
    $unidad = $productoData['unidad_medida'] ?? 'pieza';
    $cfg = $unidades_config[$unidad] ?? $unidades_config['pieza'];

    $descripcion = $productoData['nombre'];
    if (!empty($productoData['descripcion'])) {
        $descripcion .= ' - ' . $productoData['descripcion'];
    }

    return [
        'description'  => $descripcion,
        'product_key'  => $cfg['product_key'],
        'unit_key'     => $cfg['unit_key'],
        'unit_name'    => $cfg['unit_name'],
        'price'        => (float)$productoData['precio'],
        'tax_included' => true,
        'taxability'   => '02',
        'sku'          => $productoData['codigo'],
        'taxes' => [[
            'type' => 'IVA', 'rate' => 0.16, 'withholding' => false, 'factor' => 'Tasa',
        ]],
    ];
}

function sincronizarProductoFacturapi(?string $facturapi_id, array $productoData, ?string $test_api_key, ?string $organization_id, array $unidades_config): array
{
    if (empty($organization_id)) {
        return ['success' => true, 'facturapi_producto_id' => $facturapi_id, 'message' => 'Producto sin facturación'];
    }
    if (empty($test_api_key)) {
        return ['success' => true, 'facturapi_producto_id' => $facturapi_id, 'message' => 'Sin API key de prueba'];
    }

    try {
        $facturapi = new Facturapi($test_api_key);
        $data = construirFacturapiData($productoData, $unidades_config);

        $response = empty($facturapi_id)
            ? $facturapi->Products->create($data)
            : $facturapi->Products->update($facturapi_id, $data);

        if (!empty($response->id)) {
            return [
                'success' => true,
                'facturapi_producto_id' => $response->id,
                'message' => empty($facturapi_id) ? 'Producto creado en FacturaAPI' : 'Producto actualizado en FacturaAPI',
            ];
        }
        return ['success' => false, 'facturapi_producto_id' => $facturapi_id, 'message' => 'FacturaAPI no devolvió ID'];
    } catch (Exception $e) {
        return ['success' => false, 'facturapi_producto_id' => $facturapi_id, 'message' => 'Error FacturaAPI: ' . $e->getMessage()];
    }
}

// =============================================
// PAGINACIÓN
// =============================================
$registros_por_pagina = PRODUCTOS_POR_PAGINA;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// =============================================
// BLOQUE PRINCIPAL
// =============================================
$productos                    = [];
$categorias                   = [];
$sucursales                   = [];
$proveedores                  = [];
$stock_por_sucursal           = [];
$imagenes_por_producto        = [];
$precios_mayoreo_por_producto = [];
$total_registros              = 0;
$total_paginas                = 0;
$total_productos              = 0;
$con_stock                    = 0;
$sin_stock                    = 0;
$bajo_stock                   = 0;
$valor_total_inventario       = 0;
$limite_alcanzado             = false;
$productos_disponibles        = 0;
$total_productos_activos      = 0;
$limite_productos             = 100;
$empresa_info                 = [];
$logo_empresa                 = null;
$logo_src_base64              = null;

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Límite
    $limite_info              = verificarLimiteProductos($conn, $empresa_plan, $PLANES_LIMITES);
    $limite_alcanzado         = $limite_info['alcanzado'];
    $productos_disponibles    = $limite_info['disponibles'];
    $total_productos_activos  = $limite_info['total'];
    $limite_productos         = $limite_info['limite'];

    // Config sistema
    $empresa_info = $conn->query("
        SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo, stock_minimo_global
        FROM sistema_config LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $stock_minimo_global = $empresa_info['stock_minimo_global'] ?? 5;

    // Logo
    if (!empty($empresa_info['logo'])) {
        $logo_path = null;
        foreach ([
            $empresa_info['logo'], '../' . $empresa_info['logo'],
            'logos/' . $empresa_info['logo'], 'img/' . $empresa_info['logo'],
            'images/' . $empresa_info['logo'], 'assets/' . $empresa_info['logo'],
            'uploads/' . $empresa_info['logo'],
            '../logos/' . $empresa_info['logo'], '../img/' . $empresa_info['logo'],
            '../uploads/' . $empresa_info['logo'],
        ] as $ruta) {
            if (is_file($ruta)) { $logo_path = $ruta; break; }
        }
        if ($logo_path) {
            $ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true)) {
                $logo_empresa = $logo_path;
                $logo_src_base64 = 'data:image/' . $ext . ';base64,' . base64_encode(file_get_contents($logo_path));
            }
        }
    }

    // WHERE dinámico
    $where = ["1=1"];
    $params = [];

    $search           = trim($_GET['search'] ?? '');
    $categoria_filtro = $_GET['categoria'] ?? '';
    $proveedor_filtro = $_GET['proveedor'] ?? '';
    $sucursal_filtro  = $_GET['sucursal'] ?? '';
    $show_inactive    = isset($_GET['show_inactive']);

    if ($search !== '') {
        $where[] = "(p.codigo LIKE ? OR p.nombre LIKE ? OR p.marca LIKE ? OR p.descripcion LIKE ?)";
        $term = "%$search%";
        array_push($params, $term, $term, $term, $term);
    }
    if ($categoria_filtro !== '') { $where[] = "p.categoria_id = ?"; $params[] = $categoria_filtro; }
    if ($proveedor_filtro !== '') { $where[] = "p.proveedor_id = ?"; $params[] = $proveedor_filtro; }
    if ($sucursal_filtro !== '')  { $where[] = "ps.sucursal_id = ?"; $params[] = $sucursal_filtro; }
    if (!$show_inactive)          { $where[] = "p.activo = 1"; }

    $where_sql = implode(' AND ', $where);

    // Total registros
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT p.id)
        FROM productos p
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
        WHERE $where_sql
    ");
    $stmt->execute($params);
    $total_registros = (int)$stmt->fetchColumn();
    $stmt = null;

    $total_paginas = (int)ceil($total_registros / $registros_por_pagina);
    if ($total_paginas > 0 && $pagina_actual > $total_paginas) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // Productos de la página
    $stmt = $conn->prepare("
        SELECT p.*, c.nombre AS categoria_nombre, pr.nombre AS proveedor_nombre,
               p.tipo_producto, p.porcentaje_merma_danado, p.porcentaje_merma_deshidratacion,
               p.aplicar_merma_venta, p.aplicar_merma_compra,
               COALESCE(GROUP_CONCAT(DISTINCT ps.sucursal_id), '') AS sucursales_ids,
               COALESCE(GROUP_CONCAT(DISTINCT s.nombre SEPARATOR ', '), 'Sin sucursales') AS sucursales_nombres,
               COALESCE(SUM(ps.stock), 0) AS stock_total,
               COALESCE(MIN(ps.stock_minimo), 0) AS stock_minimo_total
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
        LEFT JOIN sucursales s ON ps.sucursal_id = s.id
        WHERE $where_sql
        GROUP BY p.id
        ORDER BY p.fecha_creacion DESC, p.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$registros_por_pagina, $offset]));
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null;

    // Datos relacionados solo de la página
    $productos_ids = array_column($productos, 'id');
    if (!empty($productos_ids)) {
        $placeholders = implode(',', array_fill(0, count($productos_ids), '?'));

        $stmt = $conn->prepare("
            SELECT producto_id, sucursal_id, stock, stock_minimo
            FROM producto_sucursal
            WHERE producto_id IN ($placeholders)
        ");
        $stmt->execute($productos_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stock_por_sucursal[$r['producto_id']][$r['sucursal_id']] = [
                'stock'        => $r['stock'],
                'stock_minimo' => $r['stock_minimo'],
            ];
        }
        $stmt = null;

        $stmt = $conn->prepare("
            SELECT * FROM producto_imagenes
            WHERE producto_id IN ($placeholders)
            ORDER BY producto_id, es_principal DESC, orden ASC
        ");
        $stmt->execute($productos_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $imagenes_por_producto[$r['producto_id']][] = $r;
        }
        $stmt = null;

        $stmt = $conn->prepare("
            SELECT * FROM producto_precios_mayoreo
            WHERE producto_id IN ($placeholders) AND activo = 1
            ORDER BY cantidad_minima ASC
        ");
        $stmt->execute($productos_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $precios_mayoreo_por_producto[$r['producto_id']][] = $r;
        }
        $stmt = null;
    }

    // Catálogos
    $categorias  = $conn->query("SELECT id, nombre FROM categorias WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
    $sucursales  = $conn->query("SELECT id, nombre FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
    $proveedores = $conn->query("SELECT id, nombre FROM proveedores WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas reales (basadas en producto_sucursal)
    $stmt = $conn->prepare("
        SELECT
            COUNT(*)                                                                AS total_productos,
            SUM(CASE WHEN total_stock > 0 THEN 1 ELSE 0 END)                        AS con_stock,
            SUM(CASE WHEN total_stock = 0 THEN 1 ELSE 0 END)                        AS sin_stock,
            SUM(CASE WHEN total_stock > 0 AND total_stock <= ? THEN 1 ELSE 0 END)   AS bajo_stock,
            SUM(total_stock * precio)                                               AS valor_total
        FROM (
            SELECT p.id, p.precio,
                   COALESCE(SUM(ps.stock), 0) AS total_stock
            FROM productos p
            LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
            WHERE p.activo = 1
            GROUP BY p.id, p.precio
        ) AS agg
    ");
    $stmt->execute([$stock_minimo_global]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt = null;

    $total_productos        = (int)($stats['total_productos'] ?? 0);
    $con_stock              = (int)($stats['con_stock'] ?? 0);
    $sin_stock              = (int)($stats['sin_stock'] ?? 0);
    $bajo_stock             = (int)($stats['bajo_stock'] ?? 0);
    $valor_total_inventario = (float)($stats['valor_total'] ?? 0);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// =============================================
// CRUD
// =============================================
function guardarPreciosMayoreo(PDO $conn, int $producto_id, array $precios_mayoreo): void
{
    $conn->prepare("DELETE FROM producto_precios_mayoreo WHERE producto_id = ?")->execute([$producto_id]);
    if (empty($precios_mayoreo)) return;

    $stmt = $conn->prepare("
        INSERT INTO producto_precios_mayoreo (producto_id, cantidad_minima, precio_especial, activo)
        VALUES (?, ?, ?, 1)
    ");
    foreach ($precios_mayoreo as $p) {
        if (!isset($p['cantidad'], $p['precio'])) continue;
        if ($p['cantidad'] <= 0 || $p['precio'] <= 0) continue;
        $stmt->execute([$producto_id, $p['cantidad'], $p['precio']]);
    }
    $stmt = null;
}

function recolectarDatosFormulario(array $post): array
{
    return [
        'codigo'        => trim($post['codigo'] ?? ''),
        'nombre'        => trim($post['nombre'] ?? ''),
        'descripcion'   => trim($post['descripcion'] ?? ''),
        'marca'         => trim($post['marca'] ?? ''),
        'subprecio'     => (float)($post['subprecio'] ?? 0),
        'descuento'     => (float)($post['descuento'] ?? 0),
        'costo'         => (float)($post['costo'] ?? 0),
        'utilidad'      => (float)($post['utilidad'] ?? 0),
        'precio'        => (float)($post['precio'] ?? 0),
        'categoria_id'  => !empty($post['categoria_id']) ? (int)$post['categoria_id'] : null,
        'proveedor_id'  => !empty($post['proveedor_id']) ? (int)$post['proveedor_id'] : null,
        'unidad_medida' => trim($post['unidad_medida'] ?? 'pieza'),
        'peso_kg'       => (float)($post['peso_kg'] ?? 1.0),
        'permite_fracciones'              => isset($post['permite_fracciones']) ? 1 : 0,
        'fecha_caducidad'                 => !empty($post['fecha_caducidad']) ? $post['fecha_caducidad'] : null,
        'tipo_producto'                   => trim($post['tipo_producto'] ?? 'Estandar'),
        'porcentaje_merma_danado'         => (float)($post['porcentaje_merma_danado'] ?? 0),
        'porcentaje_merma_deshidratacion' => (float)($post['porcentaje_merma_deshidratacion'] ?? 0),
        'aplicar_merma_venta'             => isset($post['aplicar_merma_venta']) ? 1 : 0,
        'aplicar_merma_compra'            => isset($post['aplicar_merma_compra']) ? 1 : 0,
        'precios_mayoreo'                 => json_decode($post['precios_mayoreo'] ?? '[]', true) ?: [],
    ];
}

function normalizarPrecioDescuento(float $precio, float $subprecio, float $descuento): array
{
    if ($precio <= 0) {
        $precio = $subprecio;
        if ($descuento > 0) $precio = $subprecio - ($subprecio * ($descuento / 100));
    }
    if ($subprecio > 0 && $precio > 0) {
        $calc = (($subprecio - $precio) / $subprecio) * 100;
        if ($calc >= 0 && $calc <= 100) $descuento = $calc;
    }
    return [$precio, $descuento];
}

function calcularStockTotal(array $sucursales_seleccionadas, array $post): float
{
    $total = 0;
    foreach ($sucursales_seleccionadas as $sid) {
        $total += (float)($post['stock_' . $sid] ?? 0);
    }
    return $total;
}

function crearProducto(PDO $conn, float $stock_minimo_global, ?string $test_api_key, ?string $organization_id, array $unidades_config): void
{
    $d = recolectarDatosFormulario($_POST);
    [$d['precio'], $d['descuento']] = normalizarPrecioDescuento($d['precio'], $d['subprecio'], $d['descuento']);

    $sucursales_seleccionadas = $_POST['sucursales'] ?? [];
    $stock_total = calcularStockTotal($sucursales_seleccionadas, $_POST);

    try {
        $conn->beginTransaction();

        $facturapi_result = sincronizarProductoFacturapi(
            null,
            ['nombre' => $d['nombre'], 'codigo' => $d['codigo'], 'precio' => $d['precio'],
             'descripcion' => $d['descripcion'], 'unidad_medida' => $d['unidad_medida']],
            $test_api_key,
            $organization_id,
            $unidades_config
        );
        $facturapi_id = $facturapi_result['facturapi_producto_id'] ?? null;

        $stmt = $conn->prepare("
            INSERT INTO productos (
                codigo, nombre, descripcion, marca, precio, subprecio, costo, descuento, utilidad,
                categoria_id, proveedor_id, stock, stock_minimo, unidad_medida, peso_kg,
                permite_fracciones, fecha_caducidad, facturapi_producto_id, tipo_producto,
                porcentaje_merma_danado, porcentaje_merma_deshidratacion,
                aplicar_merma_venta, aplicar_merma_compra
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $d['codigo'], $d['nombre'], $d['descripcion'], $d['marca'],
            $d['precio'], $d['precio'], $d['costo'], $d['descuento'], $d['utilidad'],
            $d['categoria_id'], $d['proveedor_id'], $stock_total, $stock_minimo_global,
            $d['unidad_medida'], $d['peso_kg'], $d['permite_fracciones'], $d['fecha_caducidad'],
            $facturapi_id, $d['tipo_producto'],
            $d['porcentaje_merma_danado'], $d['porcentaje_merma_deshidratacion'],
            $d['aplicar_merma_venta'], $d['aplicar_merma_compra'],
        ]);
        $producto_id = (int)$conn->lastInsertId();
        $stmt = null;

        if (!empty($d['precios_mayoreo'])) {
            guardarPreciosMayoreo($conn, $producto_id, $d['precios_mayoreo']);
        }

        $imagenes_subidas = [];
        if (!empty($_FILES['imagenes']['tmp_name'][0])) {
            $imagenes_subidas = subirMultiplesImagenes($_FILES, $producto_id);
            if (!empty($imagenes_subidas)) {
                $principal = min((int)($_POST['imagen_principal'] ?? 0), count($imagenes_subidas) - 1);
                guardarImagenesProducto($conn, $producto_id, $imagenes_subidas, $principal);
            }
        }

        if (!empty($sucursales_seleccionadas)) {
            $stmt = $conn->prepare("
                INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($sucursales_seleccionadas as $sid) {
                $stmt->execute([$producto_id, $sid, (float)($_POST['stock_' . $sid] ?? 0), $stock_minimo_global]);
            }
            $stmt = null;
        }

        $conn->commit();

        $_SESSION['mensaje'] = "Producto creado exitosamente"
            . (!empty($imagenes_subidas) ? " con " . count($imagenes_subidas) . " imagen(es)" : "")
            . (!empty($d['precios_mayoreo']) ? " con " . count($d['precios_mayoreo']) . " regla(s) de mayoreo" : "");
        $_SESSION['tipo_mensaje'] = "success";

        if (!empty($organization_id) && isset($facturapi_result['success']) && !$facturapi_result['success']) {
            $_SESSION['mensaje'] .= " (FacturaAPI: " . $facturapi_result['message'] . ")";
            $_SESSION['tipo_mensaje'] = "warning";
        }
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: productos.php');
    exit();
}

function editarProducto(PDO $conn, float $stock_minimo_global, int $timbres_disponibles, ?string $test_api_key, ?string $organization_id, array $unidades_config): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { header('Location: productos.php'); exit(); }

    $d = recolectarDatosFormulario($_POST);
    [$d['precio'], $d['descuento']] = normalizarPrecioDescuento($d['precio'], $d['subprecio'], $d['descuento']);

    $sucursales_seleccionadas = $_POST['sucursales'] ?? [];
    $stock_total = calcularStockTotal($sucursales_seleccionadas, $_POST);

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT facturapi_producto_id FROM productos WHERE id = ?");
        $stmt->execute([$id]);
        $facturapi_id_actual = $stmt->fetchColumn() ?: null;
        $stmt = null;

        $nuevo_facturapi_id = $facturapi_id_actual;
        $facturapi_result = null;

        if (!empty($organization_id) && !empty($test_api_key) && $timbres_disponibles > 0) {
            $facturapi_result = sincronizarProductoFacturapi(
                $facturapi_id_actual,
                ['nombre' => $d['nombre'], 'codigo' => $d['codigo'], 'precio' => $d['precio'],
                 'descripcion' => $d['descripcion'], 'unidad_medida' => $d['unidad_medida']],
                $test_api_key,
                $organization_id,
                $unidades_config
            );
            if ($facturapi_result['success'] && !empty($facturapi_result['facturapi_producto_id'])) {
                $nuevo_facturapi_id = $facturapi_result['facturapi_producto_id'];
            }
        }

        $stmt = $conn->prepare("
            UPDATE productos SET
                codigo = ?, nombre = ?, descripcion = ?, marca = ?, precio = ?, subprecio = ?,
                costo = ?, descuento = ?, stock = ?, stock_minimo = ?, categoria_id = ?, proveedor_id = ?,
                unidad_medida = ?, peso_kg = ?, permite_fracciones = ?, fecha_caducidad = ?,
                facturapi_producto_id = ?, tipo_producto = ?, porcentaje_merma_danado = ?,
                porcentaje_merma_deshidratacion = ?, aplicar_merma_venta = ?, aplicar_merma_compra = ?,
                utilidad = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $d['codigo'], $d['nombre'], $d['descripcion'], $d['marca'],
            $d['precio'], $d['precio'], $d['costo'], $d['descuento'],
            $stock_total, $stock_minimo_global, $d['categoria_id'], $d['proveedor_id'],
            $d['unidad_medida'], $d['peso_kg'], $d['permite_fracciones'], $d['fecha_caducidad'],
            $nuevo_facturapi_id, $d['tipo_producto'],
            $d['porcentaje_merma_danado'], $d['porcentaje_merma_deshidratacion'],
            $d['aplicar_merma_venta'], $d['aplicar_merma_compra'], $d['utilidad'], $id,
        ]);
        $stmt = null;

        guardarPreciosMayoreo($conn, $id, $d['precios_mayoreo']);

        // Imágenes
        $imagenes_finales = [];
        if (!empty($_POST['imagenes_existentes'])) {
            $existentes = json_decode($_POST['imagenes_existentes'], true) ?: [];
            foreach ($existentes as $img) {
                if (!empty($img['ruta_imagen'])) $imagenes_finales[] = $img['ruta_imagen'];
            }
        } else {
            foreach (obtenerImagenesProducto($conn, $id) as $img) {
                $imagenes_finales[] = $img['ruta_imagen'];
            }
        }

        $nuevas_imagenes = [];
        if (!empty($_FILES['imagenes']['tmp_name'][0])) {
            $total_despues = count($imagenes_finales) + count($_FILES['imagenes']['tmp_name']);
            if ($total_despues > MAX_IMAGENES_PRODUCTO) {
                throw new Exception("No se pueden agregar más de " . MAX_IMAGENES_PRODUCTO . " imágenes por producto");
            }
            $nuevas_imagenes = subirMultiplesImagenes($_FILES, $id);
            $imagenes_finales = array_merge($imagenes_finales, $nuevas_imagenes);
        }

        if (!empty($imagenes_finales)) {
            $principal = (int)($_POST['imagen_principal'] ?? 0);
            if ($principal < 0 || $principal >= count($imagenes_finales)) $principal = 0;
            guardarImagenesProducto($conn, $id, $imagenes_finales, $principal);
        } else {
            $conn->prepare("DELETE FROM producto_imagenes WHERE producto_id = ?")->execute([$id]);
        }

        // Sucursales
        $conn->prepare("DELETE FROM producto_sucursal WHERE producto_id = ?")->execute([$id]);
        if (!empty($sucursales_seleccionadas)) {
            $stmt = $conn->prepare("
                INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($sucursales_seleccionadas as $sid) {
                $stmt->execute([$id, $sid, (float)($_POST['stock_' . $sid] ?? 0), $stock_minimo_global]);
            }
            $stmt = null;
        }

        $conn->commit();

        $_SESSION['mensaje'] = "Producto actualizado exitosamente"
            . (!empty($nuevas_imagenes) ? " con " . count($nuevas_imagenes) . " nueva(s) imagen(es)" : "")
            . (!empty($d['precios_mayoreo']) ? " con " . count($d['precios_mayoreo']) . " regla(s) de mayoreo" : "");
        $_SESSION['tipo_mensaje'] = "success";

        if (!empty($organization_id) && isset($facturapi_result['success']) && !$facturapi_result['success']) {
            $_SESSION['mensaje'] .= " (FacturaAPI: " . $facturapi_result['message'] . ")";
            $_SESSION['tipo_mensaje'] = "warning";
        }
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("editarProducto: " . $e->getMessage());
        $_SESSION['mensaje'] = "Error al actualizar producto: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: productos.php');
    exit();
}

function cambiarEstadoProducto(PDO $conn): void
{
    $id = (int)($_POST['id'] ?? 0);
    $activo = (int)($_POST['activo'] ?? 0);

    try {
        $stmt = $conn->prepare("UPDATE productos SET activo = ? WHERE id = ?");
        $stmt->execute([$activo, $id]);
        echo json_encode(['success' => true, 'message' => "Producto " . ($activo ? "activado" : "desactivado") . " exitosamente"]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// =============================================
// PROCESAR FORMULARIOS (POST)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    switch ($_POST['accion']) {
        case 'crear':
            crearProducto($conn, $stock_minimo_global, $test_api_key_working, $organization_id, $UNIDADES_CONFIG);
            break;
        case 'editar':
            editarProducto($conn, $stock_minimo_global, $timbres_disponibles, $test_api_key_working, $organization_id, $UNIDADES_CONFIG);
            break;
        case 'cambiar_estado':
            cambiarEstadoProducto($conn);
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Productos - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <style>
    .modal-dialog,
    .modal-content,
    .modal-body,
    .modal-header,
    .modal-footer {
        background-color: #ffffff !important;
    }
    [data-theme="dark"] .modal-dialog,
    [data-theme="dark"] .modal-content,
    [data-theme="dark"] .modal-body,
    [data-theme="dark"] .modal-header,
    [data-theme="dark"] .modal-footer {
        background-color: #1e1e1e !important;
    }
    @media (max-width: 991.98px) {
        .modal-fullscreen-lg-down .modal-content {
            background-color: #ffffff !important;
        }
        [data-theme="dark"] .modal-fullscreen-lg-down .modal-content {
            background-color: #1e1e1e !important;
        }
    }
    .modal-content {
        border: none !important;
        box-shadow: none !important;
    }
    </style>

    <link rel="stylesheet" href="css/crm-theme.css">
</head>

<body>

    <?php include 'includes/navbar.php'; ?>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Header -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 header-actions gap-3">
                    <h2>
                        <i class="fas fa-boxes me-2"></i>
                        Gestión de Productos
                        <?php if ($empresa_plan != 'premium'): ?>
                            <small class="badge bg-<?php echo $limite_alcanzado ? 'danger' : 'info'; ?> ms-2">
                                <?php echo $total_productos_activos; ?>/<?php echo $limite_productos; ?> productos
                            </small>
                        <?php endif; ?>
                    </h2>

                    <div class="d-flex flex-wrap gap-2 w-100 w-md-auto">
                        <button class="btn btn-primary flex-grow-1 flex-md-grow-0" id="btnNuevoProducto"
                            <?php echo $limite_alcanzado ? 'disabled title="Ha alcanzado el límite de productos"' : ''; ?>>
                            <i class="fas fa-plus me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Nuevo Producto</span>
                            <span class="d-sm-none">Nuevo</span>
                        </button>

                        <button class="btn btn-success flex-grow-1 flex-md-grow-0" id="btnImportarProductos"
                            <?php echo $limite_alcanzado ? 'disabled title="Ha alcanzado el límite de productos"' : ''; ?>>
                            <i class="fas fa-file-import me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Importar</span>
                            <span class="d-sm-none">Importar</span>
                        </button>

                        <button class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="modal" data-bs-target="#reporteModal">
                            <i class="fas fa-chart-bar me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Reportes</span>
                            <span class="d-sm-none">Reportes</span>
                        </button>

                        <a href="Documentos/plantilla_productos.xlsx" class="btn btn-outline-secondary flex-grow-1 flex-md-grow-0" download="plantilla_productos.xlsx">
                            <i class="fas fa-download me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Descargar Plantilla</span>
                            <span class="d-sm-none">Plantilla</span>
                        </a>
                    </div>
                </div>

                <!-- Mensajes -->
                <?php if (isset($_SESSION['mensaje'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show">
                        <?php echo $_SESSION['mensaje']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
                <?php endif; ?>

                <!-- Alerta de límite -->
                <?php if ($empresa_plan != 'premium' && $productos_disponibles <= 10 && $productos_disponibles > 0): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>¡Atención!</strong> Solo le quedan <?php echo $productos_disponibles; ?> productos disponibles en su plan <?php echo ucfirst($empresa_plan); ?>.
                        <a href="actualizar_plan.php" class="alert-link">Considere actualizar su plan</a> para continuar agregando productos.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Productos</div>
                                        <div class="metric-value text-primary"><?php echo $total_productos; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-box fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Con Stock</div>
                                        <div class="metric-value text-success"><?php echo $con_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Stock Bajo</div>
                                        <div class="metric-value text-warning"><?php echo $bajo_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Sin Stock</div>
                                        <div class="metric-value text-danger"><?php echo $sin_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x text-danger opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra de búsqueda y filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center filtros-row">
                            <div class="col-md-3">
                                <div class="search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="text" class="form-control" placeholder="Buscar por código, nombre, marca..."
                                        id="searchInput" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                        data-current-value="<?php echo htmlspecialchars($search ?? ''); ?>">
                                    <div class="search-loading" id="searchLoading">
                                        <i class="fas fa-spinner fa-spin text-muted"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filterCategoria">
                                    <option value="">Todas las categorías</option>
                                    <?php foreach ($categorias as $categoria): ?>
                                        <option value="<?php echo $categoria['id']; ?>" <?php echo (isset($categoria_filtro) && $categoria_filtro == $categoria['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($categoria['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filterProveedor">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo $proveedor['id']; ?>" <?php echo (isset($proveedor_filtro) && $proveedor_filtro == $proveedor['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filterSucursal">
                                    <option value="">Todas las sucursales</option>
                                    <?php foreach ($sucursales as $sucursal): ?>
                                        <option value="<?php echo $sucursal['id']; ?>" <?php echo (isset($sucursal_filtro) && $sucursal_filtro == $sucursal['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="showInactive" <?php echo $show_inactive ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="showInactive">Mostrar inactivos</label>
                                </div>
                            </div>
                            <div class="col-md-1">
                                <button class="btn btn-outline-secondary w-100" id="btnClearFilters" title="Limpiar filtros">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-12">
                                <small class="result-count" id="resultCount">Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla - Desktop -->
                <div class="card producto-grid">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Lista de Productos</h5>
                        <div class="d-flex align-items-center">
                            <small class="result-count me-3" id="resultCountDesktop">
                                Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                            </small>
                            <?php if ($total_paginas > 1): ?>
                                <span class="badge bg-primary">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="productsTable">
                                <thead>
                                    <tr>
                                        <th>Imagen</th>
                                        <th>Código</th>
                                        <th>Producto</th>
                                        <th>Unidad Medida</th>
                                        <th>Marca</th>
                                        <th>Categoría</th>
                                        <th>Subprecio</th>
                                        <th>Descuento</th>
                                        <th>Precio Final</th>
                                        <th>Stock Total</th>
                                        <th>Fecha Caducidad</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody id="productsTableBody">
                                    <?php if (empty($productos)): ?>
                                        <tr>
                                            <td colspan="14" class="text-center text-muted py-4">
                                                <i class="fas fa-box fa-3x mb-3"></i>
                                                <p>No se encontraron productos</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($productos as $producto):
                                            $imagenes_producto = $imagenes_por_producto[$producto['id']] ?? [];
                                            $precios_mayoreo = $precios_mayoreo_por_producto[$producto['id']] ?? [];
                                        ?>
                                            <tr data-categoria="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                                data-proveedor="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                                data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                                data-activo="<?php echo $producto['activo']; ?>"
                                                class="producto-row">
                                                <td>
                                                    <?php if (!empty($imagenes_producto)): ?>
                                                        <div id="carouselSmall-<?php echo $producto['id']; ?>" class="carousel slide producto-imagen-carousel" data-bs-ride="false" data-bs-interval="false">
                                                            <div class="carousel-inner">
                                                                <?php foreach ($imagenes_producto as $index => $img):
                                                                    $activeClass = ($index === 0) ? 'active' : '';
                                                                ?>
                                                                    <div class="carousel-item <?php echo $activeClass; ?>">
                                                                        <img src="<?php echo htmlspecialchars($img['ruta_imagen']); ?>"
                                                                            class="d-block w-100"
                                                                            alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                                            onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', <?php echo $index; ?>, event)">
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <?php if (count($imagenes_producto) > 1): ?>
                                                                <button class="carousel-control-prev" type="button" data-bs-target="#carouselSmall-<?php echo $producto['id']; ?>" data-bs-slide="prev">
                                                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                                                    <span class="visually-hidden">Anterior</span>
                                                                </button>
                                                                <button class="carousel-control-next" type="button" data-bs-target="#carouselSmall-<?php echo $producto['id']; ?>" data-bs-slide="next">
                                                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                                                    <span class="visually-hidden">Siguiente</span>
                                                                </button>
                                                                <div class="carousel-indicators">
                                                                    <?php for ($i = 0; $i < count($imagenes_producto); $i++): ?>
                                                                        <button type="button" data-bs-target="#carouselSmall-<?php echo $producto['id']; ?>" data-bs-slide-to="<?php echo $i; ?>" class="<?php echo ($i === 0) ? 'active' : ''; ?>" aria-current="<?php echo ($i === 0) ? 'true' : 'false'; ?>" aria-label="Slide <?php echo $i + 1; ?>"></button>
                                                                    <?php endfor; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="producto-imagen bg-light d-flex align-items-center justify-content-center no-imagen-container"
                                                            style="width: 60px; height: 60px;"
                                                            title="No hay imagen disponible"
                                                            onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', 0, event)">
                                                            <i class="fas fa-image text-muted"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($producto['codigo']); ?></strong>
                                                    <?php if (!empty($precios_mayoreo)): ?>
                                                        <span class="badge mayoreo-badge ms-1" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;">
                                                            <i class="fas fa-tags"></i> Mayoreo
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($producto['nombre']); ?></strong>
                                                        <?php if ($producto['descripcion']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($producto['descripcion']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $unidad = $producto['unidad_medida'] ?? 'pieza';
                                                    $badge_class = '';
                                                    switch ($unidad) {
                                                        case 'pieza': $badge_class = 'unidad-pieza'; break;
                                                        case 'kilo':  $badge_class = 'unidad-kilo';  break;
                                                        case 'litro': $badge_class = 'unidad-litro'; break;
                                                        default:      $badge_class = 'unidad-pieza';
                                                    }
                                                    ?>
                                                    <span class="badge unidad-medida-badge <?php echo $badge_class; ?>">
                                                        <?php echo ucfirst(htmlspecialchars($unidad)); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($producto['marca'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></td>
                                                <td>
                                                    <span class="badge badge-subprecio">$<?php echo number_format($producto['subprecio'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($producto['descuento'] > 0): ?>
                                                        <span class="badge badge-descuento">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0%</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $precio_final = $producto['precio'];
                                                    $subprecio = $producto['subprecio'];
                                                    if ($producto['descuento'] > 0 && $subprecio > 0) {
                                                        $precio_final = $subprecio - ($subprecio * ($producto['descuento'] / 100));
                                                    }
                                                    ?>
                                                    <span class="badge badge-precio <?php echo $producto['descuento'] > 0 ? 'text-danger fw-bold' : ''; ?>">
                                                        $<?php echo number_format($precio_final, 2); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php $stock_formateado = formatearStockPorUnidad($producto['stock_total'], $producto['unidad_medida'] ?? 'pieza'); ?>
                                                    <?php if ($producto['stock_total'] <= 0): ?>
                                                        <span class="badge bg-danger badge-stock"><?php echo $stock_formateado; ?></span>
                                                    <?php elseif ($producto['stock_total'] <= $stock_minimo_global): ?>
                                                        <span class="badge bg-warning badge-stock"><?php echo $stock_formateado; ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success badge-stock"><?php echo $stock_formateado; ?></span>
                                                    <?php endif; ?>
                                                    <br><small class="text-muted">Mín: <?php echo number_format($stock_minimo_global, 0); ?></small>
                                                    <?php if ($mostrar_merma && ($producto['porcentaje_merma_danado'] > 0 || $producto['porcentaje_merma_deshidratacion'] > 0)): ?>
                                                        <br><small class="text-muted merma-badge">Merma: <?php echo $producto['porcentaje_merma_danado'] + $producto['porcentaje_merma_deshidratacion']; ?>%</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($producto['fecha_caducidad'])):
                                                        $fecha_cad = new DateTime($producto['fecha_caducidad']);
                                                        $hoy = new DateTime();
                                                        $dias_restantes = $hoy->diff($fecha_cad)->days;

                                                        if ($fecha_cad < $hoy): ?>
                                                            <span class="badge bg-danger" title="Producto vencido">
                                                                <i class="fas fa-exclamation-triangle"></i> Vencido
                                                            </span>
                                                        <?php elseif ($dias_restantes <= 7): ?>
                                                            <span class="badge bg-warning" title="<?php echo $dias_restantes; ?> días para vencer">
                                                                <i class="fas fa-clock"></i> <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $producto['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                    <button class="btn btn-outline-primary btn-sm edit-producto d-none"
                                                        data-id="<?php echo $producto['id']; ?>"
                                                        data-activo="<?php echo $producto['activo']; ?>"
                                                        data-codigo="<?php echo htmlspecialchars($producto['codigo']); ?>"
                                                        data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                        data-descripcion="<?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?>"
                                                        data-marca="<?php echo htmlspecialchars($producto['marca'] ?? ''); ?>"
                                                        data-precio="<?php echo $precio_final; ?>"
                                                        data-subprecio="<?php echo $producto['subprecio']; ?>"
                                                        data-descuento="<?php echo $producto['descuento']; ?>"
                                                        data-costo="<?php echo $producto['costo']; ?>"
                                                        data-categoria_id="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                                        data-proveedor_id="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                                        data-unidad_medida="<?php echo $producto['unidad_medida'] ?? 'pieza'; ?>"
                                                        data-peso_kg="<?php echo $producto['peso_kg'] ?? 1.0; ?>"
                                                        data-permite_fracciones="<?php echo $producto['permite_fracciones'] ?? 0; ?>"
                                                        data-fecha_caducidad="<?php echo !empty($producto['fecha_caducidad']) ? $producto['fecha_caducidad'] : ''; ?>"
                                                        data-tipo_producto="<?php echo htmlspecialchars($producto['tipo_producto'] ?? 'Estandar'); ?>"
                                                        data-porcentaje_merma_danado="<?php echo $producto['porcentaje_merma_danado'] ?? 0; ?>"
                                                        data-porcentaje_merma_deshidratacion="<?php echo $producto['porcentaje_merma_deshidratacion'] ?? 0; ?>"
                                                        data-aplicar_merma_venta="<?php echo $producto['aplicar_merma_venta'] ?? 0; ?>"
                                                        data-aplicar_merma_compra="<?php echo $producto['aplicar_merma_compra'] ?? 0; ?>"
                                                        data-imagenes='<?php echo json_encode($imagenes_producto); ?>'
                                                        data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                                        data-precios-mayoreo='<?php echo json_encode($precios_mayoreo); ?>'
                                                        data-utilidad="<?php echo $producto['utilidad'] ?? 0; ?>"
                                                        data-stocks='<?php
                                                                        $stock_data = [];
                                                                        if (isset($stock_por_sucursal[$producto['id']])) {
                                                                            foreach ($stock_por_sucursal[$producto['id']] as $sucursal_id => $stock_info) {
                                                                                $stock_data[$sucursal_id] = $stock_info;
                                                                            }
                                                                        }
                                                                        echo htmlspecialchars(json_encode($stock_data));
                                                                        ?>'
                                                        title="Editar">
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación Desktop -->
                        <?php if ($total_paginas > 1): ?>
                            <div class="pagination-container" id="desktopPagination">
                                <div class="pagination-info">
                                    Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                                </div>
                                <nav>
                                    <ul class="pagination mb-0">
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = 1; echo http_build_query($query_params); ?>" title="Primera página">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = max(1, $pagina_actual - 1); echo http_build_query($query_params); ?>" title="Página anterior">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                        <?php
                                        $inicio = max(1, $pagina_actual - 2);
                                        $fin = min($total_paginas, $pagina_actual + 2);
                                        for ($i = $inicio; $i <= $fin; $i++):
                                        ?>
                                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = $i; echo http_build_query($query_params); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = min($total_paginas, $pagina_actual + 1); echo http_build_query($query_params); ?>" title="Página siguiente">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = $total_paginas; echo http_build_query($query_params); ?>" title="Última página">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                        <div class="no-results" id="noResultsDesktop" style="display: none;">
                            <i class="fas fa-search fa-3x mb-3"></i>
                            <h5>No se encontraron productos</h5>
                            <p>Intenta ajustar los filtros de búsqueda</p>
                        </div>
                    </div>
                </div>

                <!-- Cards Móvil -->
                <div class="producto-cards" id="mobileProductsContainer">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Lista de Productos</h5>
                        <?php if ($total_paginas > 1): ?>
                            <span class="badge bg-primary">Pág. <?php echo $pagina_actual; ?>/<?php echo $total_paginas; ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($productos)): ?>
                        <div class="card text-center text-muted py-4">
                            <i class="fas fa-box fa-3x mb-3"></i>
                            <p>No se encontraron productos</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($productos as $producto):
                            $imagenes_producto = $imagenes_por_producto[$producto['id']] ?? [];
                            $precios_mayoreo = $precios_mayoreo_por_producto[$producto['id']] ?? [];
                            $precio_final = $producto['precio'];
                            $subprecio = $producto['subprecio'];
                            if ($producto['descuento'] > 0 && $subprecio > 0) {
                                $precio_final = $subprecio - ($subprecio * ($producto['descuento'] / 100));
                            }
                        ?>
                            <div class="producto-card-mobile" data-categoria="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                data-proveedor="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                data-activo="<?php echo $producto['activo']; ?>">
                                <div class="producto-card-header">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($imagenes_producto)): ?>
                                                <div id="carouselMobile-<?php echo $producto['id']; ?>" class="carousel slide producto-imagen-carousel me-2" style="width: 80px;" data-bs-ride="false" data-bs-interval="false">
                                                    <div class="carousel-inner">
                                                        <?php foreach ($imagenes_producto as $index => $img):
                                                            $activeClass = ($index === 0) ? 'active' : '';
                                                        ?>
                                                            <div class="carousel-item <?php echo $activeClass; ?>">
                                                                <img src="<?php echo htmlspecialchars($img['ruta_imagen']); ?>"
                                                                    class="d-block w-100"
                                                                    alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                                    onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', <?php echo $index; ?>, event)">
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php if (count($imagenes_producto) > 1): ?>
                                                        <button class="carousel-control-prev" type="button" data-bs-target="#carouselMobile-<?php echo $producto['id']; ?>" data-bs-slide="prev" style="width: 15px;">
                                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                                            <span class="visually-hidden">Anterior</span>
                                                        </button>
                                                        <button class="carousel-control-next" type="button" data-bs-target="#carouselMobile-<?php echo $producto['id']; ?>" data-bs-slide="next" style="width: 15px;">
                                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                                            <span class="visually-hidden">Siguiente</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="producto-imagen-mobile bg-light d-flex align-items-center justify-content-center me-2 no-imagen-container"
                                                    style="width: 70px; height: 70px;"
                                                    title="No hay imagen disponible"
                                                    onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', 0, event)">
                                                    <i class="fas fa-image text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h6 class="mb-0 text-white"><?php echo htmlspecialchars($producto['nombre']); ?></h6>
                                                <div class="d-flex align-items-center mt-1">
                                                    <span class="badge bg-light text-dark me-2"><?php echo htmlspecialchars($producto['codigo']); ?></span>
                                                    <?php if (!empty($precios_mayoreo)): ?>
                                                        <span class="badge mayoreo-badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;">
                                                            <i class="fas fa-tags"></i> Mayoreo
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($producto['tipo_producto']) && $mostrar_tipo_producto): ?>
                                                        <span class="badge tipo-producto-badge ms-1" style="font-size: 0.65rem;">
                                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($producto['tipo_producto']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <button class="btn btn-outline-light btn-sm edit-producto-mobile d-none"
                                                        data-id="<?php echo $producto['id']; ?>"
                                                        data-activo="<?php echo $producto['activo']; ?>"
                                                        data-codigo="<?php echo htmlspecialchars($producto['codigo']); ?>"
                                                        data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                        data-descripcion="<?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?>"
                                                        data-marca="<?php echo htmlspecialchars($producto['marca'] ?? ''); ?>"
                                                        data-precio="<?php echo $precio_final; ?>"
                                                        data-subprecio="<?php echo $producto['subprecio']; ?>"
                                                        data-descuento="<?php echo $producto['descuento']; ?>"
                                                        data-costo="<?php echo $producto['costo']; ?>"
                                                        data-categoria_id="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                                        data-proveedor_id="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                                        data-unidad_medida="<?php echo $producto['unidad_medida'] ?? 'pieza'; ?>"
                                                        data-peso_kg="<?php echo $producto['peso_kg'] ?? 1.0; ?>"
                                                        data-permite_fracciones="<?php echo $producto['permite_fracciones'] ?? 0; ?>"
                                                        data-fecha_caducidad="<?php echo !empty($producto['fecha_caducidad']) ? $producto['fecha_caducidad'] : ''; ?>"
                                                        data-tipo_producto="<?php echo htmlspecialchars($producto['tipo_producto'] ?? 'Estandar'); ?>"
                                                        data-porcentaje_merma_danado="<?php echo $producto['porcentaje_merma_danado'] ?? 0; ?>"
                                                        data-porcentaje_merma_deshidratacion="<?php echo $producto['porcentaje_merma_deshidratacion'] ?? 0; ?>"
                                                        data-aplicar_merma_venta="<?php echo $producto['aplicar_merma_venta'] ?? 0; ?>"
                                                        data-aplicar_merma_compra="<?php echo $producto['aplicar_merma_compra'] ?? 0; ?>"
                                                        data-imagenes='<?php echo json_encode($imagenes_producto); ?>'
                                                        data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                                        data-precios-mayoreo='<?php echo json_encode($precios_mayoreo); ?>'
                                                        data-utilidad="<?php echo $producto['utilidad'] ?? 0; ?>"
                                                        data-stocks='<?php
                                                                        $stock_data = [];
                                                                        if (isset($stock_por_sucursal[$producto['id']])) {
                                                                            foreach ($stock_por_sucursal[$producto['id']] as $sucursal_id => $stock_info) {
                                                                                $stock_data[$sucursal_id] = $stock_info;
                                                                            }
                                                                        }
                                                                        echo htmlspecialchars(json_encode($stock_data));
                                                                        ?>'>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="producto-card-body">
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Unidad Medida:</span>
                                        <span class="producto-info-value">
                                            <?php
                                            $unidad = $producto['unidad_medida'] ?? 'pieza';
                                            $badge_class = '';
                                            switch ($unidad) {
                                                case 'pieza': $badge_class = 'unidad-pieza'; break;
                                                case 'kilo':  $badge_class = 'unidad-kilo';  break;
                                                case 'litro': $badge_class = 'unidad-litro'; break;
                                                default:      $badge_class = 'unidad-pieza';
                                            }
                                            ?>
                                            <span class="badge unidad-medida-badge <?php echo $badge_class; ?>">
                                                <?php echo ucfirst(htmlspecialchars($unidad)); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <?php if ($mostrar_tipo_producto): ?>
                                        <div class="producto-info-row">
                                            <span class="producto-info-label">Tipo:</span>
                                            <span class="producto-info-value">
                                                <span class="badge tipo-producto-badge">
                                                    <?php echo htmlspecialchars($producto['tipo_producto'] ?? 'Estandar'); ?>
                                                </span>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($mostrar_merma && ($producto['porcentaje_merma_danado'] > 0 || $producto['porcentaje_merma_deshidratacion'] > 0)): ?>
                                        <div class="producto-info-row">
                                            <span class="producto-info-label">Merma:</span>
                                            <span class="producto-info-value">
                                                <span class="badge merma-badge">
                                                    <i class="fas fa-charging-station me-1"></i>
                                                    D: <?php echo $producto['porcentaje_merma_danado']; ?>% /
                                                    Des: <?php echo $producto['porcentaje_merma_deshidratacion']; ?>%
                                                </span>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Marca:</span>
                                        <span class="producto-info-value"><?php echo htmlspecialchars($producto['marca'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Categoría:</span>
                                        <span class="producto-info-value"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Subprecio:</span>
                                        <span class="producto-info-value text-dark">$<?php echo number_format($producto['subprecio'], 2); ?></span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Descuento:</span>
                                        <span class="producto-info-value">
                                            <?php if ($producto['descuento'] > 0): ?>
                                                <span class="badge bg-danger">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                            <?php else: ?>
                                                <span class="text-muted">0%</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Precio Final:</span>
                                        <span class="producto-info-value text-success fw-bold">
                                            $<?php echo number_format($precio_final, 2); ?>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Stock Total:</span>
                                        <span class="producto-info-value">
                                            <?php $stock_formateado = formatearStockPorUnidad($producto['stock_total'], $producto['unidad_medida'] ?? 'pieza'); ?>
                                            <?php if ($producto['stock_total'] <= 0): ?>
                                                <span class="badge bg-danger"><?php echo $stock_formateado; ?></span>
                                            <?php elseif ($producto['stock_total'] <= $stock_minimo_global): ?>
                                                <span class="badge bg-warning"><?php echo $stock_formateado; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><?php echo $stock_formateado; ?></span>
                                            <?php endif; ?>
                                            <small class="text-muted ms-2">Mín: <?php echo number_format($stock_minimo_global, 0); ?></small>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Fecha Caducidad:</span>
                                        <span class="producto-info-value">
                                            <?php if (!empty($producto['fecha_caducidad'])):
                                                $fecha_cad = new DateTime($producto['fecha_caducidad']);
                                                $hoy = new DateTime();
                                                $dias_restantes = $hoy->diff($fecha_cad)->days;

                                                if ($fecha_cad < $hoy): ?>
                                                    <span class="badge bg-danger" title="Producto vencido">
                                                        <i class="fas fa-exclamation-triangle"></i> Vencido
                                                    </span>
                                                <?php elseif ($dias_restantes <= 7): ?>
                                                    <span class="badge bg-warning" title="<?php echo $dias_restantes; ?> días para vencer">
                                                        <i class="fas fa-clock"></i> <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Estado:</span>
                                        <span class="producto-info-value">
                                            <span class="status-badge <?php echo $producto['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                                            </span>
                                        </span>
                                    </div>
                                    <?php if ($producto['descripcion']): ?>
                                        <div class="producto-info-row">
                                            <span class="producto-info-label">Descripción:</span>
                                            <span class="producto-info-value"><small><?php echo htmlspecialchars($producto['descripcion']); ?></small></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container" id="mobilePagination">
                            <div class="pagination-info">
                                <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                            </div>
                            <nav>
                                <ul class="pagination pagination-sm mb-0 justify-content-center">
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = 1; echo http_build_query($query_params); ?>" title="Primera página">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = max(1, $pagina_actual - 1); echo http_build_query($query_params); ?>" title="Página anterior">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item disabled">
                                        <span class="page-link text-dark">
                                            <strong><?php echo $pagina_actual; ?></strong> / <?php echo $total_paginas; ?>
                                        </span>
                                    </li>
                                    <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = min($total_paginas, $pagina_actual + 1); echo http_build_query($query_params); ?>" title="Siguiente">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php $query_params = $_GET; $query_params['pagina'] = $total_paginas; echo http_build_query($query_params); ?>" title="Última página">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>

                    <div class="no-results" id="noResultsMobile" style="display: none;">
                        <i class="fas fa-search fa-3x mb-3"></i>
                        <h5>No se encontraron productos</h5>
                        <p>Intenta ajustar los filtros de búsqueda</p>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Botón de filtros móvil -->
    <div class="filtros-mobile d-md-none">
        <button class="filtros-toggle" id="filtrosToggle">
            <i class="fas fa-filter"></i>
        </button>
        <div class="filtros-panel" id="filtrosPanel">
            <h6 class="mb-3">Filtros de Productos</h6>
            <div class="mb-3">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" class="form-control form-control-sm" placeholder="Buscar productos..."
                        id="searchInputMobile" value="<?php echo htmlspecialchars($search ?? ''); ?>">
                </div>
            </div>
            <div class="mb-3">
                <select class="form-select form-select-sm" id="filterCategoriaMobile">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($categorias as $categoria): ?>
                        <option value="<?php echo $categoria['id']; ?>" <?php echo (isset($categoria_filtro) && $categoria_filtro == $categoria['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($categoria['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <select class="form-select form-select-sm" id="filterProveedorMobile">
                    <option value="">Todos los proveedores</option>
                    <?php foreach ($proveedores as $proveedor): ?>
                        <option value="<?php echo $proveedor['id']; ?>" <?php echo (isset($proveedor_filtro) && $proveedor_filtro == $proveedor['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proveedor['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <select class="form-select form-select-sm" id="filterSucursalMobile">
                    <option value="">Todas las sucursales</option>
                    <?php foreach ($sucursales as $sucursal): ?>
                        <option value="<?php echo $sucursal['id']; ?>" <?php echo (isset($sucursal_filtro) && $sucursal_filtro == $sucursal['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sucursal['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="showInactiveMobile" <?php echo $show_inactive ? 'checked' : ''; ?>>
                <label class="form-check-label" for="showInactiveMobile">Mostrar inactivos</label>
            </div>
            <div class="d-grid gap-2">
                <button class="btn btn-primary btn-sm" id="btnAplicarFiltrosMobile">
                    <i class="fas fa-check me-1"></i>Aplicar Filtros
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="btnClearFiltersMobile">
                    <i class="fas fa-times me-1"></i>Limpiar Filtros
                </button>
            </div>
            <small class="result-count text-center d-block mt-2" id="resultCountMobile"><?php echo count($productos); ?> de <?php echo $total_registros; ?> productos</small>
        </div>
    </div>

    <!-- Modal Nuevo/Editar Producto -->
    <div class="modal fade" id="productoModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <i class="fas fa-box me-2"></i>Nuevo Producto
                    </h5>
                    <?php if ($empresa_plan != 'premium'): ?>
                        <span class="badge bg-white text-primary ms-2 d-none d-md-inline">
                            <?php echo $total_productos_activos; ?>/<?php echo $limite_productos; ?>
                        </span>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <form method="POST" id="productoForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="formAction" value="crear">
                        <input type="hidden" name="id" id="productoId">
                        <input type="hidden" id="productoActivo" value="1">
                        <input type="hidden" name="imagenes_existentes" id="imagenes_existentes" value="[]">
                        <input type="hidden" name="imagen_principal" id="imagen_principal" value="0">
                        <input type="hidden" name="precios_mayoreo" id="precios_mayoreo" value="[]">

                        <input type="hidden" name="subprecio" id="subprecio_hidden">
                        <input type="hidden" name="descuento" id="descuento_hidden">
                        <input type="hidden" name="precio" id="precio_hidden">
                        <input type="hidden" name="costo" id="costo_hidden">
                        <input type="hidden" name="utilidad" id="utilidad_hidden">

                        <!-- Imágenes -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-images me-2"></i>Imágenes del Producto
                                            <small class="text-muted ms-2">(Máximo 5 imágenes)</small>
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="galeriaImagenes" class="row mb-3"></div>
                                        <div id="nuevasImagenesPreview" class="row mb-3"></div>
                                        <div class="mb-3 mobile-image-buttons">
                                            <button type="button" class="btn btn-gallery-mobile w-100" id="btnSeleccionarGaleria">
                                                <i class="fas fa-images me-2"></i>Seleccionar de Galería
                                            </button>
                                            <button type="button" class="btn btn-camera-mobile w-100" id="btnTomarFoto">
                                                <i class="fas fa-camera me-2"></i>Tomar Foto
                                            </button>
                                        </div>
                                        <div class="desktop-file-input">
                                            <label class="form-label">Agregar nuevas imágenes</label>
                                            <input type="file" class="form-control" name="imagenes[]" id="imagenes"
                                                accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                                            <small class="form-text text-muted">
                                                Formatos permitidos: JPG, PNG, GIF, WebP. Tamaño máximo: 2MB por imagen. Puedes seleccionar hasta 5 imágenes.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Código y nombre -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Código *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="codigo" id="codigo" required>
                                        <button type="button" class="btn btn-outline-secondary" id="btnGenerarCodigo">
                                            <i class="fas fa-bolt"></i> Auto
                                        </button>
                                    </div>
                                    <small class="form-text text-muted">
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnSugerirCodigo">
                                            <i class="fas fa-lightbulb"></i> Sugerir código
                                        </button>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" name="nombre" id="nombre" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Marca</label>
                                    <input type="text" class="form-control" name="marca" id="marca" placeholder="Ej: Sony, Samsung, etc.">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Descripción</label>
                                    <textarea class="form-control" name="descripcion" id="descripcion" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Precios -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-tags me-2"></i>Información de Precios
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-4" <?php echo $hide_precio_compra_style; ?>>
                                                <div class="mb-3">
                                                    <label class="form-label">Costo</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">$</span>
                                                        <input type="text" class="form-control d-none d-md-block" name="costo_desktop" id="costo_desktop" placeholder="0.00">
                                                        <input type="number" class="form-control d-md-none" name="costo_mobile" id="costo_mobile" step="0.01" min="0" placeholder="0.00">
                                                    </div>
                                                    <small class="form-text text-muted">Precio de compra del producto</small>
                                                </div>
                                            </div>

                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Utilidad (%)</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control d-none d-md-block" name="utilidad_desktop" id="utilidad_desktop" placeholder="0.00">
                                                        <input type="number" class="form-control d-md-none" name="utilidad_mobile" id="utilidad_mobile" step="0.01" min="0" max="1000" placeholder="0.00">
                                                        <span class="input-group-text">%</span>
                                                    </div>
                                                    <small class="form-text text-muted" id="utilidad_helper">
                                                        Porcentaje de ganancia sobre el costo
                                                    </small>
                                                </div>
                                            </div>

                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Descuento (%)</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control d-none d-md-block" name="descuento_desktop" id="descuento_desktop" value="0">
                                                        <input type="number" class="form-control d-md-none" name="descuento_mobile" id="descuento_mobile" step="0.01" min="0" max="100" value="0">
                                                        <span class="input-group-text">%</span>
                                                    </div>
                                                    <small class="form-text text-muted" id="utilidad_helper">Descuento sobre el precio de venta</small>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6" style="display: none;">
                                                <div class="mb-3">
                                                    <label class="form-label">Precio Venta (Base) *</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">$</span>
                                                        <input type="text" class="form-control" name="subprecio_desktop" id="subprecio_desktop" readonly style="background-color: #e9ecef;">
                                                        <input type="number" class="form-control" name="subprecio_mobile" id="subprecio_mobile" step="0.01" min="0" readonly style="background-color: #e9ecef;">
                                                    </div>
                                                    <small class="form-text text-muted">Calculado automáticamente (Costo + Utilidad)</small>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Precio Venta (Final) *</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">$</span>
                                                        <input type="text" class="form-control d-none d-md-block" name="precio_desktop" id="precio_desktop">
                                                        <input type="number" class="form-control d-md-none" name="precio_mobile" id="precio_mobile" step="0.01" min="0">
                                                    </div>
                                                    <small class="form-text text-muted">Precio final con descuento aplicado</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Mayoreo -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <div class="mayoreo-header">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-chart-line me-2"></i>Precios de Mayoreo
                                            </h6>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="habilitarMayoreo">
                                                <label class="form-check-label" for="habilitarMayoreo">
                                                    <span class="badge mayoreo-badge">Habilitar precios por cantidad</span>
                                                </label>
                                            </div>
                                        </div>
                                        <small class="text-muted">Define precios especiales según la cantidad de compra</small>
                                    </div>
                                    <div class="card-body" id="mayoreoSection" style="display: none;">
                                        <div id="reglasMayoreoContainer" class="reglas-mayoreo-container mb-3"></div>
                                        <button type="button" class="btn btn-agregar-regla" id="btnAgregarReglaMayoreo" style="display: none;">
                                            <i class="fas fa-plus me-2"></i>Agregar regla de mayoreo
                                        </button>
                                        <small class="form-text text-muted d-block mt-2">
                                            <i class="fas fa-info-circle"></i> Las reglas se aplicarán automáticamente en ventas según la cantidad.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Configuraciones avanzadas -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-cogs me-2"></i>Configuraciones Avanzadas
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6" <?php echo $hide_unidad_medida_style; ?>>
                                                <div class="mb-3">
                                                    <label class="form-label">Unidad de Medida *</label>
                                                    <select class="form-select" name="unidad_medida" id="unidad_medida" required>
                                                        <?php foreach ($tipos_unidad_permitidos as $tipo): ?>
                                                            <option value="<?php echo $tipo; ?>">
                                                                <?php echo ucfirst($tipo); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Selecciona la unidad de medida del producto</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6" <?php echo $hide_unidad_medida_style; ?>>
                                                <div class="mb-3">
                                                    <label class="form-label" id="peso_label">Peso por Unidad (kg)</label>
                                                    <input type="number" class="form-control" name="peso_kg" id="peso_kg"
                                                        step="0.001" min="0.001" value="1.000">
                                                    <small class="form-text text-muted" id="peso_helper">Peso de cada unidad en kilogramos</small>
                                                </div>
                                            </div>
                                            <?php if (!$mostrar_unidad_medida): ?>
                                                <input type="hidden" name="unidad_medida" id="unidad_medida" value="pieza">
                                                <input type="hidden" name="peso_kg" id="peso_kg" value="1.000">
                                            <?php endif; ?>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6" <?php echo $hide_unidad_medida_style; ?>>
                                                <div class="mb-3">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="permite_fracciones" id="permite_fracciones" value="1">
                                                        <label class="form-check-label" for="permite_fracciones">
                                                            Permitir venta por fracciones
                                                        </label>
                                                        <small class="form-text text-muted d-block" id="fracciones_helper">
                                                            Para kilos y litros: permite vender fracciones (ej: 0.5 kg)<br>
                                                            Para piezas: normalmente se vende por unidad completa
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if (!$mostrar_unidad_medida): ?>
                                                <input type="hidden" name="permite_fracciones" value="0">
                                            <?php endif; ?>
                                            <div class="col-md-6" <?php echo $hide_fecha_caducidad_style; ?>>
                                                <div class="mb-3">
                                                    <label class="form-label">Fecha de Caducidad</label>
                                                    <input type="date" class="form-control" name="fecha_caducidad" id="fecha_caducidad">
                                                    <small class="form-text text-muted">Opcional - Fecha en que el producto caduca</small>
                                                </div>
                                            </div>
                                            <?php if (!$mostrar_fecha_caducidad): ?>
                                                <input type="hidden" name="fecha_caducidad" id="fecha_caducidad" value="">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Categoría y proveedor -->
                        <div class="row">
                            <div class="col-md-6" <?php echo $hide_categoria_style; ?>>
                                <div class="mb-3">
                                    <label class="form-label">Categoría</label>
                                    <div class="input-group">
                                        <select class="form-select" name="categoria_id" id="categoria_id">
                                            <option value="">Sin categoría</option>
                                            <?php foreach ($categorias as $categoria): ?>
                                                <option value="<?php echo $categoria['id']; ?>">
                                                    <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" id="btnNuevaCategoria">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php if (!$mostrar_categoria): ?>
                                <input type="hidden" name="categoria_id" id="categoria_id" value="">
                            <?php endif; ?>
                            <div class="col-md-6" <?php echo $hide_proveedor_style; ?>>
                                <div class="mb-3">
                                    <label class="form-label">Proveedor</label>
                                    <div class="input-group">
                                        <select class="form-select" name="proveedor_id" id="proveedor_id">
                                            <option value="">Sin proveedor</option>
                                            <?php foreach ($proveedores as $proveedor): ?>
                                                <option value="<?php echo $proveedor['id']; ?>">
                                                    <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" id="btnNuevoProveedor">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php if (!$mostrar_proveedor): ?>
                                <input type="hidden" name="proveedor_id" id="proveedor_id" value="">
                            <?php endif; ?>
                        </div>

                        <!-- Sucursales -->
                        <div class="sucursal-stock-section">
                            <h6 class="sucursal-stock-header">
                                <i class="fas fa-store me-2"></i>Sucursales y Stock
                                <small class="text-muted">(Stock mínimo global: <?php echo $stock_minimo_global; ?>)</small>
                            </h6>
                            <div class="row">
                                <?php foreach ($sucursales as $sucursal): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input sucursal-checkbox" type="checkbox"
                                                name="sucursales[]" value="<?php echo $sucursal['id']; ?>"
                                                id="sucursal_<?php echo $sucursal['id']; ?>">
                                            <label class="form-check-label fw-bold" for="sucursal_<?php echo $sucursal['id']; ?>">
                                                <?php
                                                echo htmlspecialchars($sucursal['nombre']);
                                                if (strtolower(trim($sucursal['nombre'])) == 'matriz') {
                                                    echo ' <span class="badge bg-primary ms-1" style="font-size: 0.65rem;">Sucursal principal</span>';
                                                }
                                                ?>
                                            </label>
                                        </div>
                                        <div class="row mt-2 stock-fields" id="stock_fields_<?php echo $sucursal['id']; ?>" style="display: none;">
                                            <div class="col-12">
                                                <label class="form-label small">Stock</label>
                                                <input type="number" class="form-control form-control-sm stock-input"
                                                    name="stock_<?php echo $sucursal['id']; ?>"
                                                    id="stock_<?php echo $sucursal['id']; ?>"
                                                    min="0" value="0"
                                                    step="any"
                                                    data-unidad="pieza">
                                                <small class="form-text text-muted stock-unidad-indicador">Stock en unidades enteras (piezas)</small>
                                            </div>
                                            <input type="hidden" class="stock-minimo-field"
                                                name="stock_minimo_<?php echo $sucursal['id']; ?>"
                                                value="<?php echo $stock_minimo_global; ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Transferencia de stock -->
                        <div id="seccionTransferenciaStock" style="display:none;">
                            <hr class="my-3">
                            <h6 class="sucursal-stock-header">
                                <i class="fas fa-exchange-alt me-2"></i>Transferir Stock entre Sucursales
                            </h6>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4 col-sm-6">
                                    <label class="form-label small fw-semibold">Sucursal Origen</label>
                                    <select class="form-select form-select-sm" id="trans_sucursal_origen">
                                        <option value="">— Seleccionar —</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>" data-nombre="<?php echo htmlspecialchars($sucursal['nombre']); ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 col-sm-6">
                                    <label class="form-label small fw-semibold">Sucursal Destino</label>
                                    <select class="form-select form-select-sm" id="trans_sucursal_destino">
                                        <option value="">— Seleccionar —</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>" data-nombre="<?php echo htmlspecialchars($sucursal['nombre']); ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 col-sm-4">
                                    <label class="form-label small fw-semibold">Cantidad</label>
                                    <input type="number" class="form-control form-control-sm" id="trans_cantidad" min="0.01" step="any" placeholder="0">
                                </div>
                                <div class="col-md-2 col-sm-8">
                                    <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnEjecutarTransferencia">
                                        <i class="fas fa-paper-plane me-1"></i>Transferir
                                    </button>
                                </div>
                                <div class="col-12">
                                    <input type="text" class="form-control form-control-sm" id="trans_observaciones" placeholder="Observaciones (opcional)">
                                </div>
                                <div class="col-12" id="trans_resultado" style="display:none;"></div>
                            </div>
                        </div>

                        <!-- Nueva categoría -->
                        <div class="row" id="nuevaCategoriaRow" style="display: none;">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Nueva Categoría *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control nueva-categoria-field" id="nuevaCategoriaNombre" placeholder="Nombre de la nueva categoría">
                                        <button type="button" class="btn btn-primary" id="btnGuardarCategoria">
                                            <i class="fas fa-save me-2"></i>Guardar
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="btnCancelarCategoria">
                                            <i class="fas fa-times me-2"></i>Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Nuevo proveedor -->
                        <div class="row" id="nuevoProveedorRow" style="display: none;">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Nuevo Proveedor *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control nuevo-proveedor-field" id="nuevoProveedorNombre" placeholder="Nombre del nuevo proveedor">
                                        <button type="button" class="btn btn-primary" id="btnGuardarProveedor">
                                            <i class="fas fa-save me-2"></i>Guardar
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="btnCancelarProveedor">
                                            <i class="fas fa-times me-2"></i>Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger me-auto" id="btnEliminarProducto" style="display: none;" title="Eliminar Producto">
                            <i class="fas fa-trash-alt"></i><span class="d-none d-sm-inline ms-2">Eliminar Producto</span>
                        </button>
                        <button type="button" class="btn btn-outline-info" id="btnClonarProductoModal" style="display: none;" title="Clonar">
                            <i class="fas fa-clone"></i><span class="d-none d-sm-inline ms-2">Clonar</span>
                        </button>
                        <button type="button" class="btn btn-outline-warning" id="btnToggleEstadoModal" style="display: none;" title="Activar/Desactivar">
                            <i class="fas fa-toggle-on" id="btnToggleEstadoIcono"></i><span class="d-none d-sm-inline ms-2" id="btnToggleEstadoTexto">Desactivar</span>
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i><span class="d-none d-sm-inline ms-2">Cancelar</span>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i><span class="d-none d-sm-inline ms-2">Guardar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal imagen ampliada -->
    <div class="modal fade imagen-ampliada-modal" id="imagenAmpliadaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content" style="background-color: transparent; border: none;">
                <button type="button" class="btn-close-imagen" data-bs-dismiss="modal" aria-label="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
                <button type="button" class="btn-download-imagen" id="btnDescargarImagen" title="Descargar imagen">
                    <i class="fas fa-download"></i>
                </button>
                <div class="modal-body p-0">
                    <div id="imagenAmpliadaCarousel" class="carousel slide" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-inner" id="imagenAmpliadaCarouselInner"></div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#imagenAmpliadaCarousel" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Anterior</span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#imagenAmpliadaCarousel" data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Siguiente</span>
                        </button>
                        <div class="carousel-indicators" id="imagenAmpliadaCarouselIndicators"></div>
                    </div>
                    <div id="imagenCargando" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                        <div class="spinner-border text-light mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="text-light">Cargando imágenes...</p>
                    </div>
                    <div id="sinImagenMensaje" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                        <div class="text-center text-light">
                            <i class="fas fa-image fa-4x mb-3 opacity-50"></i>
                            <h5>Sin imagen disponible</h5>
                            <p class="opacity-75">Este producto no tiene imágenes asociadas</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Importar -->
    <div class="modal fade" id="importarModal" tabindex="-1" aria-labelledby="importarModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importarModalLabel">
                        <i class="fas fa-file-import me-2"></i>Importar Productos
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Instrucciones:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Formatos permitidos: XLS, XLSX</li>
                            <li>La primera fila debe contener los encabezados</li>
                            <li>Campos requeridos: <strong>código, nombre, precio, costo</strong></li>
                            <li>Campos opcionales: descripción, marca, subprecio, descuento, stock, categoría, proveedor, unidad_medida, peso_kg, permite_fracciones, fecha_caducidad</li>
                        </ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">1. Descarga la plantilla</label>
                        <a href="Documentos/plantilla_productos.xlsx" class="btn btn-sm btn-outline-success d-block" download="plantilla_productos.xlsx">
                            <i class="fas fa-download me-2"></i>Descargar Plantilla
                        </a>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">2. Selecciona el archivo</label>
                        <input type="file" class="form-control" id="archivoImportar" accept=".xls,.xlsx">
                        <small class="text-muted">Máximo 5MB</small>
                    </div>
                    <div class="progress mb-3" id="importProgress" style="display: none;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                            role="progressbar" style="width: 0%" id="importProgressBar">0%</div>
                    </div>
                    <div id="importResult" style="display: none;">
                        <div class="alert" id="importResultAlert" role="alert">
                            <h6 class="alert-heading" id="importResultTitle"></h6>
                            <p id="importResultMessage"></p>
                            <div id="importResultErrors" style="max-height: 200px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cerrar
                    </button>
                    <button type="button" class="btn btn-primary" id="btnProcesarImportacion">
                        <i class="fas fa-upload me-2"></i>Importar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Reportes -->
    <div class="modal fade" id="reporteModal" tabindex="-1" aria-labelledby="reporteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white" id="reporteModalLabel">
                        <i class="fas fa-chart-bar me-2"></i>Reportes de Inventario
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-boxes fa-3x text-success mb-3"></i>
                                    <h5>Inventario de Productos</h5>
                                    <p class="text-muted">Lista completa de productos en inventario</p>
                                    <form action="reporte_inventario_completo.php" method="GET" target="_blank" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Categoría:</label>
                                            <select class="form-select" name="categoria_id">
                                                <option value="">Todas las categorías</option>
                                                <?php foreach ($categorias as $categoria): ?>
                                                    <option value="<?php echo $categoria['id']; ?>">
                                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Filtrar por Stock:</label>
                                            <select class="form-select" name="stock_filter">
                                                <option value="">Todos</option>
                                                <option value="bajo">Bajo Stock</option>
                                                <option value="sin">Sin Stock</option>
                                                <option value="normal">Stock Normal</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                                    <h5>Productos Bajo Stock</h5>
                                    <p class="text-muted">Lista de productos que requieren reabastecimiento</p>
                                    <form action="reporte_inventario_bajo_stock.php" method="GET" target="_blank" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-chart-line fa-3x text-primary mb-3"></i>
                                    <h5>Movimientos de Inventario</h5>
                                    <p class="text-muted">Historial de entradas y salidas</p>
                                    <form action="reporte_movimientos_inventario.php" method="GET" target="_blank" class="mt-3">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Fecha Inicio:</label>
                                                <input type="date" class="form-control" name="fecha_inicio"
                                                    value="<?php echo date('Y-m-01'); ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Fecha Fin:</label>
                                                <input type="date" class="form-control" name="fecha_fin"
                                                    value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Producto (opcional):</label>
                                            <select class="form-select" name="producto_id">
                                                <option value="">Todos los productos</option>
                                                <?php foreach ($productos as $producto): ?>
                                                    <option value="<?php echo $producto['id']; ?>">
                                                        <?php echo htmlspecialchars($producto['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-chart-pie fa-3x text-info mb-3"></i>
                                    <h5>Estadísticas Rápidas</h5>
                                    <p class="text-muted">Resumen del estado del inventario</p>
                                    <div class="mt-3">
                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="metric-value text-primary"><?php echo $total_productos; ?></div>
                                                <small class="text-muted">Total Productos</small>
                                            </div>
                                            <div class="col-6">
                                                <div class="metric-value text-warning"><?php echo $bajo_stock; ?></div>
                                                <small class="text-muted">Bajo Stock</small>
                                            </div>
                                        </div>
                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="metric-value text-danger"><?php echo $sin_stock; ?></div>
                                                <small class="text-muted">Sin Stock</small>
                                            </div>
                                            <div class="col-6">
                                                <div class="metric-value text-success">$<?php echo number_format($valor_total_inventario, 2); ?></div>
                                                <small class="text-muted">Valor Inventario</small>
                                            </div>
                                        </div>
                                        <div class="alert alert-info">
                                            <small>
                                                <i class="fas fa-info-circle me-1"></i>
                                                Estos datos reflejan el estado actual del inventario
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Total Productos -->
    <div class="modal fade" id="modalTotalProductos" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-box me-2"></i>Lista de Productos
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body" id="listaTotalProductos">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2 text-muted">Cargando productos...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Con Stock -->
    <div class="modal fade" id="modalConStock" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Productos con Stock
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body" id="listaConStock">
                    <div class="text-center py-4">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2 text-muted">Cargando productos...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Stock Bajo -->
    <div class="modal fade" id="modalStockBajo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #ffc107, #fd7e14); color: #856404;">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Productos con Stock Bajo
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body" id="listaStockBajo">
                    <div class="text-center py-4">
                        <div class="spinner-border text-warning" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2 text-muted">Cargando productos...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Sin Stock -->
    <div class="modal fade" id="modalSinStock" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-times-circle me-2"></i>Productos Sin Stock
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body" id="listaSinStock">
                    <div class="text-center py-4">
                        <div class="spinner-border text-danger" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2 text-muted">Cargando productos...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <script>
        window.LFG_MULTISUCURSAL      = <?php echo (count($sucursales) >= 2) ? 'true' : 'false'; ?>;
        window.LFG_LIMITE_ALCANZADO   = <?php echo $limite_alcanzado ? 'true' : 'false'; ?>;
        window.LFG_LIMITE_PRODUCTOS   = <?php echo (int)$limite_productos; ?>;
    </script>
    <script src="js/producto.js"></script>
</body>

</html>