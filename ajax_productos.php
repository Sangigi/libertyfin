<?php
session_start();
require 'vendor/autoload.php';

use Facturapi\Facturapi;

// Verificar sesión
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

$response = [
    'success' => true,
    'productos' => [],
    'total_registros' => 0,
    'total_paginas' => 0,
    'pagina_actual' => 1,
    'stock_minimo_global' => 5,
    'estadisticas' => [],
    'valor_total_inventario' => 0
];

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
    
    // Obtener stock mínimo global
    $sql_config = "SELECT stock_minimo_global FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    if ($result_config && $result_config->num_rows > 0) {
        $config = $result_config->fetch_assoc();
        $response['stock_minimo_global'] = $config['stock_minimo_global'] ?? 5;
    }
    
    // Parámetros de filtro
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $categoria_filtro = isset($_GET['categoria']) ? intval($_GET['categoria']) : '';
    $proveedor_filtro = isset($_GET['proveedor']) ? intval($_GET['proveedor']) : '';
    $sucursal_filtro = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : '';
    $show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] == '1';
    $pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $registros_por_pagina = 5;
    $offset = ($pagina_actual - 1) * $registros_por_pagina;
    
    // Construir condiciones WHERE
    $where_conditions = "WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $search_term = "%" . $search . "%";
        $where_conditions .= " AND (p.codigo LIKE ? OR p.nombre LIKE ? OR p.marca LIKE ? OR p.descripcion LIKE ?)";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
        $types .= "ssss";
    }
    
    if (!empty($categoria_filtro)) {
        $where_conditions .= " AND p.categoria_id = ?";
        $params[] = $categoria_filtro;
        $types .= "i";
    }
    
    if (!empty($proveedor_filtro)) {
        $where_conditions .= " AND p.proveedor_id = ?";
        $params[] = $proveedor_filtro;
        $types .= "i";
    }
    
    if (!empty($sucursal_filtro)) {
        $where_conditions .= " AND ps.sucursal_id = ?";
        $params[] = $sucursal_filtro;
        $types .= "i";
    }
    
    if (!$show_inactive) {
        $where_conditions .= " AND p.activo = 1";
    }
    
    // Contar total de registros
    $sql_count = "SELECT COUNT(DISTINCT p.id) as total 
                  FROM productos p 
                  LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id 
                  $where_conditions";
    
    if (!empty($params)) {
        $stmt_count = $conn->prepare($sql_count);
        $stmt_count->bind_param($types, ...$params);
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
    } else {
        $result_count = $conn->query($sql_count);
    }
    
    $total_registros = $result_count->fetch_assoc()['total'];
    if (isset($stmt_count)) $stmt_count->close();
    
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }
    
    $response['total_registros'] = $total_registros;
    $response['total_paginas'] = $total_paginas;
    $response['pagina_actual'] = $pagina_actual;
    
    // Obtener productos
    $sql_productos = "
    SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre,
           COALESCE(GROUP_CONCAT(DISTINCT ps.sucursal_id), '') as sucursales_ids,
           COALESCE(GROUP_CONCAT(DISTINCT s.nombre SEPARATOR ', '), 'Sin sucursales') as sucursales_nombres,
           COALESCE(SUM(ps.stock), 0) as stock_total,
           COALESCE(MIN(ps.stock_minimo), 0) as stock_minimo_total
    FROM productos p 
    LEFT JOIN categorias c ON p.categoria_id = c.id 
    LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
    LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
    LEFT JOIN sucursales s ON ps.sucursal_id = s.id
    $where_conditions
    GROUP BY p.id
    ORDER BY p.fecha_creacion DESC, p.id DESC
    LIMIT ? OFFSET ?
    ";
    
    $params_limit = array_merge($params, [$registros_por_pagina, $offset]);
    $types_limit = $types . "ii";
    
    $stmt = $conn->prepare($sql_productos);
    if (!empty($params_limit)) {
        $stmt->bind_param($types_limit, ...$params_limit);
    }
    $stmt->execute();
    $result_productos = $stmt->get_result();
    
    // Obtener IDs de productos
    $productos_ids = [];
    $productos_data = [];
    while ($row = $result_productos->fetch_assoc()) {
        $productos_ids[] = $row['id'];
        $productos_data[$row['id']] = $row;
    }
    $stmt->close();
    
    // Obtener imágenes de productos
    $imagenes_por_producto = [];
    if (!empty($productos_ids)) {
        $ids_str = implode(',', $productos_ids);
        $sql_imagenes = "SELECT * FROM producto_imagenes WHERE producto_id IN ($ids_str) ORDER BY producto_id, es_principal DESC, orden ASC";
        $result_imagenes = $conn->query($sql_imagenes);
        while ($row_img = $result_imagenes->fetch_assoc()) {
            $producto_id = $row_img['producto_id'];
            if (!isset($imagenes_por_producto[$producto_id])) {
                $imagenes_por_producto[$producto_id] = [];
            }
            $imagenes_por_producto[$producto_id][] = $row_img;
        }
    }
    
    // Obtener precios de mayoreo
    $precios_mayoreo_por_producto = [];
    if (!empty($productos_ids)) {
        $ids_str = implode(',', $productos_ids);
        $sql_mayoreo = "SELECT * FROM producto_precios_mayoreo WHERE producto_id IN ($ids_str) AND activo = 1 ORDER BY cantidad_minima ASC";
        $result_mayoreo = $conn->query($sql_mayoreo);
        while ($row_mayoreo = $result_mayoreo->fetch_assoc()) {
            $producto_id = $row_mayoreo['producto_id'];
            if (!isset($precios_mayoreo_por_producto[$producto_id])) {
                $precios_mayoreo_por_producto[$producto_id] = [];
            }
            $precios_mayoreo_por_producto[$producto_id][] = $row_mayoreo;
        }
    }
    
    // Obtener stock por sucursal
    $stock_por_sucursal = [];
    if (!empty($productos_ids)) {
        $ids_str = implode(',', $productos_ids);
        $sql_stock = "SELECT producto_id, sucursal_id, stock, stock_minimo FROM producto_sucursal WHERE producto_id IN ($ids_str)";
        $result_stock = $conn->query($sql_stock);
        while ($row = $result_stock->fetch_assoc()) {
            $stock_por_sucursal[$row['producto_id']][$row['sucursal_id']] = [
                'stock' => $row['stock'],
                'stock_minimo' => $row['stock_minimo']
            ];
        }
    }
    
    // Construir array de productos para la respuesta
    foreach ($productos_data as $producto_id => $producto) {
        $tiene_mayoreo = isset($precios_mayoreo_por_producto[$producto_id]) && count($precios_mayoreo_por_producto[$producto_id]) > 0;
        
        $response['productos'][] = [
            'id' => $producto['id'],
            'codigo' => $producto['codigo'],
            'nombre' => $producto['nombre'],
            'descripcion' => $producto['descripcion'],
            'marca' => $producto['marca'],
            'precio' => floatval($producto['precio']),
            'subprecio' => floatval($producto['subprecio']),
            'descuento' => floatval($producto['descuento']),
            'costo' => floatval($producto['costo']),
            'categoria_id' => $producto['categoria_id'],
            'categoria_nombre' => $producto['categoria_nombre'],
            'proveedor_id' => $producto['proveedor_id'],
            'proveedor_nombre' => $producto['proveedor_nombre'],
            'unidad_medida' => $producto['unidad_medida'],
            'peso_kg' => floatval($producto['peso_kg']),
            'permite_fracciones' => intval($producto['permite_fracciones']),
            'fecha_caducidad' => $producto['fecha_caducidad'],
            'activo' => intval($producto['activo']),
            'stock_total' => floatval($producto['stock_total']),
            'sucursales_ids' => $producto['sucursales_ids'],
            'sucursales_nombres' => $producto['sucursales_nombres'],
            'tiene_mayoreo' => $tiene_mayoreo,
            'imagenes' => $imagenes_por_producto[$producto_id] ?? [],
            'precios_mayoreo' => $precios_mayoreo_por_producto[$producto_id] ?? [],
            'stocks_por_sucursal' => $stock_por_sucursal[$producto_id] ?? []
        ];
    }
    
    // Obtener estadísticas
    $sql_stats = "
        SELECT 
            COUNT(*) as total_productos,
            SUM(CASE WHEN p.stock > 0 THEN 1 ELSE 0 END) as con_stock,
            SUM(CASE WHEN p.stock = 0 THEN 1 ELSE 0 END) as sin_stock,
            SUM(CASE WHEN p.stock > 0 AND p.stock <= ? THEN 1 ELSE 0 END) as bajo_stock
        FROM productos p
        WHERE p.activo = 1
    ";
    $stmt_stats = $conn->prepare($sql_stats);
    $stock_minimo = $response['stock_minimo_global'];
    $stmt_stats->bind_param("i", $stock_minimo);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    $stats = $result_stats->fetch_assoc();
    $stmt_stats->close();
    
    $response['estadisticas'] = [
        'total_productos' => intval($stats['total_productos'] ?? 0),
        'con_stock' => intval($stats['con_stock'] ?? 0),
        'sin_stock' => intval($stats['sin_stock'] ?? 0),
        'bajo_stock' => intval($stats['bajo_stock'] ?? 0)
    ];
    
    // Valor total de inventario
    $sql_valor = "SELECT SUM(p.precio * ps.stock) as valor_total 
                  FROM productos p 
                  INNER JOIN producto_sucursal ps ON p.id = ps.producto_id 
                  WHERE p.activo = 1";
    $result_valor = $conn->query($sql_valor);
    $valor_row = $result_valor->fetch_assoc();
    $response['valor_total_inventario'] = floatval($valor_row['valor_total'] ?? 0);
    
    $conn->close();
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>