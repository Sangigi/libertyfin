<?php

$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

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

// Obtener parámetros de búsqueda
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$categoria_filtro = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;
$proveedor_filtro = isset($_GET['proveedor']) ? intval($_GET['proveedor']) : 0;
$sucursal_filtro = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : 0;
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] == '1';

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

    // Construir condiciones WHERE dinámicamente
    $where_conditions = "WHERE 1=1";
    $params = [];
    $types = "";

    // Aplicar filtros si existen
    if (!empty($search)) {
        $search_term = "%" . $search . "%";
        $where_conditions .= " AND (p.codigo LIKE ? OR p.nombre LIKE ? OR p.marca LIKE ? OR p.descripcion LIKE ?)";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
        $types .= "ssss";
    }

    if ($categoria_filtro > 0) {
        $where_conditions .= " AND p.categoria_id = ?";
        $params[] = $categoria_filtro;
        $types .= "i";
    }

    if ($proveedor_filtro > 0) {
        $where_conditions .= " AND p.proveedor_id = ?";
        $params[] = $proveedor_filtro;
        $types .= "i";
    }

    if ($sucursal_filtro > 0) {
        $where_conditions .= " AND ps.sucursal_id = ?";
        $params[] = $sucursal_filtro;
        $types .= "i";
    }

    if (!$show_inactive) {
        $where_conditions .= " AND p.activo = 1";
    }

    // Obtener el total de registros para paginación
    $sql_count = "SELECT COUNT(DISTINCT p.id) as total 
                  FROM productos p 
                  LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id 
                  $where_conditions";

    if (!empty($params)) {
        $stmt_count = $conn->prepare($sql_count);
        $stmt_count->bind_param($types, ...$params);
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
        $total_registros = $result_count->fetch_assoc()['total'];
        $stmt_count->close();
    } else {
        $result_count = $conn->query($sql_count);
        $total_registros = $result_count->fetch_assoc()['total'];
    }

    // Obtener productos (sin paginación para búsqueda en tiempo real)
    $sql_productos = "
        SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre,
               COALESCE(GROUP_CONCAT(DISTINCT ps.sucursal_id), '') as sucursales_ids,
               COALESCE(GROUP_CONCAT(DISTINCT s.nombre SEPARATOR ', '), 'Sin sucursales') as sucursales_nombres,
               COALESCE(SUM(ps.stock), 0) as stock_total
        FROM productos p 
        LEFT JOIN categorias c ON p.categoria_id = c.id 
        LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
        LEFT JOIN sucursales s ON ps.sucursal_id = s.id
        $where_conditions
        GROUP BY p.id
        ORDER BY p.fecha_creacion DESC
        LIMIT 100  -- Límite para evitar cargar demasiados datos
    ";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql_productos);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result_productos = $stmt->get_result();
        $productos = $result_productos->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $result_productos = $conn->query($sql_productos);
        $productos = [];
        while ($row = $result_productos->fetch_assoc()) {
            $productos[] = $row;
        }
    }

    $conn->close();

    // Devolver respuesta JSON
    echo json_encode([
        'success' => true,
        'productos' => $productos,
        'total_registros' => $total_registros
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>