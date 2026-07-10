<?php
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_secure', 1);   // ← cambiar a 1, tu sitio es HTTPS
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Obtener parámetros de la petición AJAX
$action = $_POST['action'] ?? '';
$search = $_POST['search'] ?? '';
$categoria = $_POST['categoria'] ?? '';
$proveedor = $_POST['proveedor'] ?? '';
$sucursal = $_POST['sucursal'] ?? '';
$show_inactive = isset($_POST['show_inactive']) ? (bool)$_POST['show_inactive'] : false;
$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$registros_por_pagina = isset($_POST['registros_por_pagina']) ? (int)$_POST['registros_por_pagina'] : 5;

if ($page < 1) $page = 1;
$offset = ($page - 1) * $registros_por_pagina;

try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener stock mínimo global
    $sql_config = "SELECT stock_minimo_global FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $config = $result_config->fetch_assoc();
    $stock_minimo_global = $config['stock_minimo_global'] ?? 5;

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

    if (!empty($categoria)) {
        $where_conditions .= " AND p.categoria_id = ?";
        $params[] = $categoria;
        $types .= "i";
    }

    if (!empty($proveedor)) {
        $where_conditions .= " AND p.proveedor_id = ?";
        $params[] = $proveedor;
        $types .= "i";
    }

    if (!empty($sucursal)) {
        $where_conditions .= " AND ps.sucursal_id = ?";
        $params[] = $sucursal;
        $types .= "i";
    }

    if (!$show_inactive) {
        $where_conditions .= " AND p.activo = 1";
    }

    // Obtener el total de registros
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

    // Calcular total de páginas
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($page > $total_paginas && $total_paginas > 0) {
        $page = $total_paginas;
        $offset = ($page - 1) * $registros_por_pagina;
    }

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

    // Agregar parámetros para LIMIT y OFFSET
    $params_limit = array_merge($params, [$registros_por_pagina, $offset]);
    $types_limit = $types . "ii";

    $stmt = $conn->prepare($sql_productos);
    if (!empty($params_limit)) {
        $stmt->bind_param($types_limit, ...$params_limit);
    }
    $stmt->execute();
    $result_productos = $stmt->get_result();
    $productos = [];
    while ($row = $result_productos->fetch_assoc()) {
        // Formatear fecha de caducidad
        $fecha_caducidad_formatted = '';
        if (!empty($row['fecha_caducidad'])) {
            $fecha_caducidad_formatted = date('d/m/Y', strtotime($row['fecha_caducidad']));
        }
        
        // Obtener imagen URL
        $imagen_url = '';
        if (!empty($row['imagen']) && file_exists($row['imagen'])) {
            $imagen_url = $row['imagen'];
        }
        
        // Obtener stock por sucursal
        $stocks = [];
        $sql_stocks = "SELECT sucursal_id, stock, stock_minimo FROM producto_sucursal WHERE producto_id = ?";
        $stmt_stocks = $conn->prepare($sql_stocks);
        $stmt_stocks->bind_param("i", $row['id']);
        $stmt_stocks->execute();
        $result_stocks = $stmt_stocks->get_result();
        while ($stock_row = $result_stocks->fetch_assoc()) {
            $stocks[$stock_row['sucursal_id']] = [
                'stock' => $stock_row['stock'],
                'stock_minimo' => $stock_row['stock_minimo']
            ];
        }
        $stmt_stocks->close();
        
        $productos[] = [
            'id' => $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
            'marca' => $row['marca'],
            'precio' => $row['precio'],
            'costo' => $row['costo'],
            'categoria_id' => $row['categoria_id'],
            'proveedor_id' => $row['proveedor_id'],
            'categoria_nombre' => $row['categoria_nombre'],
            'proveedor_nombre' => $row['proveedor_nombre'],
            'unidad_medida' => $row['unidad_medida'],
            'peso_kg' => $row['peso_kg'],
            'permite_fracciones' => $row['permite_fracciones'],
            'fecha_caducidad' => $row['fecha_caducidad'],
            'fecha_caducidad_formatted' => $fecha_caducidad_formatted,
            'imagen' => $row['imagen'],
            'imagen_url' => $imagen_url,
            'stock_total' => $row['stock_total'],
            'stock_minimo_total' => $row['stock_minimo_total'],
            'sucursales_ids' => $row['sucursales_ids'],
            'sucursales_nombres' => $row['sucursales_nombres'],
            'activo' => $row['activo'],
            'stocks' => $stocks
        ];
    }
    $stmt->close();

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
    $stmt_stats->bind_param("i", $stock_minimo_global);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    $stats = $result_stats->fetch_assoc();
    $stmt_stats->close();

    $conn->close();

    // Preparar respuesta
    $response = [
        'success' => true,
        'productos' => $productos,
        'total_registros' => $total_registros,
        'total_paginas' => $total_paginas,
        'pagina_actual' => $page,
        'stock_minimo_global' => $stock_minimo_global,
        'stats' => $stats
    ];

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>