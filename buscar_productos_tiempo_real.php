<?php

session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar sesión
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'] ?? '';

if (empty($dbname)) {
    echo json_encode(['success' => false, 'message' => 'Base de datos no especificada']);
    exit();
}

// Conectar a la base de datos
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener parámetros
    $busqueda = $_POST['busqueda'] ?? '';
    $categoria_id = $_POST['categoria_id'] ?? '';
    $sucursal_id = $_POST['sucursal_id'] ?? $_SESSION['sucursal_id'] ?? 0;

    // Construir consulta base
    $sql = "
        SELECT 
            p.id,
            p.codigo,
            p.nombre,
            p.descripcion,
            p.precio as precio_sin_iva,
            p.precio as precio,
            p.costo,
            p.categoria_id,
            p.activo,
            p.unidad_medida,
            p.peso_kg,
            p.permite_fracciones,
            p.imagen,
            c.nombre as categoria_nombre,
            COALESCE(ps.stock, 0) as stock_sucursal,
            COALESCE(ps.stock_minimo, 0) as stock_minimo,
            p.stock as stock_general
        FROM productos p
        INNER JOIN categorias c ON p.categoria_id = c.id
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
        WHERE p.activo = 1
        AND (COALESCE(ps.stock, 0) > 0)
    ";

    $params = [$sucursal_id];
    $types = "i";

    // Aplicar filtros
    if (!empty($busqueda)) {
        $sql .= " AND (p.nombre LIKE ? OR p.codigo LIKE ?)";
        $search_term = "%" . $busqueda . "%";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ss";
    }

    if (!empty($categoria_id)) {
        $sql .= " AND p.categoria_id = ?";
        $params[] = $categoria_id;
        $types .= "i";
    }

    $sql .= " ORDER BY p.nombre LIMIT 100";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $productos = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // PARA CADA PRODUCTO, OBTENER SU IMAGEN PRINCIPAL
        foreach ($productos as &$producto) {
            $sql_imagen = "SELECT ruta_imagen FROM producto_imagenes 
                           WHERE producto_id = ? 
                           ORDER BY es_principal DESC, orden ASC 
                           LIMIT 1";
            
            $stmt_imagen = $conn->prepare($sql_imagen);
            if ($stmt_imagen) {
                $stmt_imagen->bind_param("i", $producto['id']);
                $stmt_imagen->execute();
                $result_imagen = $stmt_imagen->get_result();
                
                if ($result_imagen->num_rows > 0) {
                    $imagen_data = $result_imagen->fetch_assoc();
                    $producto['imagen'] = $imagen_data['ruta_imagen'];
                }
                $stmt_imagen->close();
            }
        }

        echo json_encode([
            'success' => true,
            'productos' => $productos,
            'count' => count($productos)
        ]);
    } else {
        throw new Exception("Error en la consulta: " . $conn->error);
    }

} catch (Exception $e) {
    error_log("Error en buscar_productos_tiempo_real.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage(),
        'productos' => []
    ]);
}
?>