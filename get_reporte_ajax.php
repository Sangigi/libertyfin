<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit();
}

require_once __DIR__ . '/config.php';

// Función segura para htmlspecialchars
function safe_html($string) {
    if ($string === null || $string === '') {
        return '';
    }
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    if ($amount === null || $amount === '') {
        return '$0.00';
    }
    return '$' . number_format(floatval($amount), 2);
}

function validar_fecha($fecha) {
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// Recibir datos por POST
$fecha_inicio = isset($_POST['fecha_inicio']) && validar_fecha($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_POST['fecha_fin']) && validar_fecha($_POST['fecha_fin']) ? $_POST['fecha_fin'] : date('Y-m-d');
$tipo_reporte = isset($_POST['tipo_reporte']) ? $_POST['tipo_reporte'] : 'general';
$sucursal_id = isset($_POST['sucursal_id']) && is_numeric($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : '';

// Validar tipo_reporte contra lista blanca
$tipos_permitidos = ['general', 'ventas', 'inventario', 'clientes'];
if (!in_array($tipo_reporte, $tipos_permitidos)) {
    $tipo_reporte = 'general';
}

// Configuración de la base de datos
$servername = config('db.servername');
$username = config('db.username');
$password = config('db.password');
$dbname = $_SESSION['empresa_db'];

// Conectar a la base de datos
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
    
    // Variables para almacenar datos
    $estadisticas = [];
    $datos_grafica = [];
    $labels_grafica = [];
    $data_grafica = [];
    $data_subtotal = [];
    $data_iva = [];
    $productos_vendidos = [];
    $metodos_pago = [];
    $vendedores_top = [];
    $clientes_frecuentes = [];
    $ventas_por_sucursal = [];
    $productos_stock_bajo = [];
    $ventas_por_hora = [];
    $labels_horas = [];
    $data_horas = [];
    
    // Ejecutar consultas según el tipo
    if ($tipo_reporte === 'general' || $tipo_reporte === 'ventas') {
        // Estadísticas generales
        $sql_estadisticas = "
            SELECT 
                COUNT(*) as total_ventas,
                COALESCE(SUM(v.total), 0) as ingresos_totales,
                COALESCE(AVG(v.total), 0) as promedio_venta,
                COUNT(DISTINCT v.cliente_id) as clientes_activos,
                COALESCE(SUM(v.iva), 0) as iva_recaudado,
                (SELECT COUNT(*) FROM productos WHERE activo = TRUE AND stock > 0) as productos_stock,
                (SELECT COUNT(*) FROM productos WHERE activo = TRUE AND stock <= 0) as productos_sin_stock,
                (SELECT COUNT(*) FROM productos WHERE activo = TRUE AND stock <= stock_minimo AND stock > 0) as productos_bajo_stock
            FROM ventas v
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_estadisticas = [$fecha_inicio, $fecha_fin];
        $types_estadisticas = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_estadisticas .= " AND v.sucursal_id = ?";
            $params_estadisticas[] = $_SESSION['sucursal_id'];
            $types_estadisticas .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_estadisticas .= " AND v.sucursal_id = ?";
            $params_estadisticas[] = $sucursal_id;
            $types_estadisticas .= "i";
        }
        
        $stmt_estadisticas = $conn->prepare($sql_estadisticas);
        if ($stmt_estadisticas) {
            $stmt_estadisticas->bind_param($types_estadisticas, ...$params_estadisticas);
            $stmt_estadisticas->execute();
            $result_estadisticas = $stmt_estadisticas->get_result();
            $estadisticas = $result_estadisticas->fetch_assoc();
            $stmt_estadisticas->close();
        }
        
        // Ventas por día
        $sql_ventas_dia = "
            SELECT 
                DATE(v.fecha) as fecha,
                COUNT(*) as cantidad_ventas,
                COALESCE(SUM(v.total), 0) as total_dia,
                COALESCE(SUM(v.subtotal), 0) as subtotal_dia,
                COALESCE(SUM(v.iva), 0) as iva_dia
            FROM ventas v
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_ventas_dia = [$fecha_inicio, $fecha_fin];
        $types_ventas_dia = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_ventas_dia .= " AND v.sucursal_id = ?";
            $params_ventas_dia[] = $_SESSION['sucursal_id'];
            $types_ventas_dia .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_ventas_dia .= " AND v.sucursal_id = ?";
            $params_ventas_dia[] = $sucursal_id;
            $types_ventas_dia .= "i";
        }
        
        $sql_ventas_dia .= " GROUP BY DATE(v.fecha) ORDER BY fecha";
        
        $stmt_ventas_dia = $conn->prepare($sql_ventas_dia);
        if ($stmt_ventas_dia) {
            $stmt_ventas_dia->bind_param($types_ventas_dia, ...$params_ventas_dia);
            $stmt_ventas_dia->execute();
            $result_ventas_dia = $stmt_ventas_dia->get_result();
            
            while ($row = $result_ventas_dia->fetch_assoc()) {
                $datos_grafica[] = $row;
                $labels_grafica[] = date('d M', strtotime($row['fecha']));
                $data_grafica[] = floatval($row['total_dia']);
                $data_subtotal[] = floatval($row['subtotal_dia']);
                $data_iva[] = floatval($row['iva_dia']);
            }
            $stmt_ventas_dia->close();
        }
        
        // Métodos de pago
        $sql_metodos_pago = "
            SELECT 
                v.metodo_pago,
                COUNT(*) as cantidad_ventas,
                COALESCE(SUM(v.total), 0) as total_metodo,
                COALESCE(SUM(v.subtotal), 0) as subtotal_metodo,
                COALESCE(SUM(v.iva), 0) as iva_metodo
            FROM ventas v
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_metodos = [$fecha_inicio, $fecha_fin];
        $types_metodos = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_metodos_pago .= " AND v.sucursal_id = ?";
            $params_metodos[] = $_SESSION['sucursal_id'];
            $types_metodos .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_metodos_pago .= " AND v.sucursal_id = ?";
            $params_metodos[] = $sucursal_id;
            $types_metodos .= "i";
        }
        
        $sql_metodos_pago .= " GROUP BY v.metodo_pago ORDER BY total_metodo DESC";
        
        $stmt_metodos = $conn->prepare($sql_metodos_pago);
        if ($stmt_metodos) {
            $stmt_metodos->bind_param($types_metodos, ...$params_metodos);
            $stmt_metodos->execute();
            $result_metodos = $stmt_metodos->get_result();
            while ($row = $result_metodos->fetch_assoc()) {
                $metodos_pago[] = $row;
            }
            $stmt_metodos->close();
        }
        
        // Ventas por hora
        $sql_ventas_hora = "
            SELECT 
                HOUR(v.fecha) as hora,
                COUNT(*) as cantidad_ventas,
                COALESCE(SUM(v.total), 0) as total_hora
            FROM ventas v
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_hora = [$fecha_inicio, $fecha_fin];
        $types_hora = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_ventas_hora .= " AND v.sucursal_id = ?";
            $params_hora[] = $_SESSION['sucursal_id'];
            $types_hora .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_ventas_hora .= " AND v.sucursal_id = ?";
            $params_hora[] = $sucursal_id;
            $types_hora .= "i";
        }
        
        $sql_ventas_hora .= " GROUP BY HOUR(v.fecha) ORDER BY hora";
        
        $stmt_ventas_hora = $conn->prepare($sql_ventas_hora);
        if ($stmt_ventas_hora) {
            $stmt_ventas_hora->bind_param($types_hora, ...$params_hora);
            $stmt_ventas_hora->execute();
            $result_ventas_hora = $stmt_ventas_hora->get_result();
            
            for ($i = 0; $i < 24; $i++) {
                $labels_horas[] = $i . ':00';
                $data_horas[] = 0;
            }
            
            while ($row = $result_ventas_hora->fetch_assoc()) {
                $hora = (int)$row['hora'];
                $ventas_por_hora[] = $row;
                $data_horas[$hora] = floatval($row['total_hora']);
            }
            $stmt_ventas_hora->close();
        }
        
        // Ventas por sucursal
        $sql_ventas_sucursal = "
            SELECT 
                s.nombre as sucursal,
                COUNT(*) as total_ventas,
                COALESCE(SUM(v.total), 0) as ingresos_totales,
                COALESCE(AVG(v.total), 0) as promedio_venta
            FROM ventas v
            INNER JOIN sucursales s ON v.sucursal_id = s.id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_sucursales_ventas = [$fecha_inicio, $fecha_fin];
        $types_sucursales_ventas = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_ventas_sucursal .= " AND v.sucursal_id = ?";
            $params_sucursales_ventas[] = $_SESSION['sucursal_id'];
            $types_sucursales_ventas .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_ventas_sucursal .= " AND v.sucursal_id = ?";
            $params_sucursales_ventas[] = $sucursal_id;
            $types_sucursales_ventas .= "i";
        }
        
        $sql_ventas_sucursal .= " GROUP BY s.id, s.nombre ORDER BY ingresos_totales DESC";
        
        $stmt_sucursales_ventas = $conn->prepare($sql_ventas_sucursal);
        if ($stmt_sucursales_ventas) {
            $stmt_sucursales_ventas->bind_param($types_sucursales_ventas, ...$params_sucursales_ventas);
            $stmt_sucursales_ventas->execute();
            $result_sucursales_ventas = $stmt_sucursales_ventas->get_result();
            while ($row = $result_sucursales_ventas->fetch_assoc()) {
                $ventas_por_sucursal[] = $row;
            }
            $stmt_sucursales_ventas->close();
        }
    }
    
    if ($tipo_reporte === 'general' || $tipo_reporte === 'ventas' || $tipo_reporte === 'inventario') {
        // Productos más vendidos
        $sql_productos_vendidos = "
            SELECT 
                p.nombre,
                p.codigo,
                p.precio,
                SUM(vd.cantidad) as total_vendido,
                SUM(vd.subtotal) as ingresos_totales,
                c.nombre as categoria_nombre
            FROM venta_detalles vd
            INNER JOIN productos p ON vd.producto_id = p.id
            INNER JOIN ventas v ON vd.venta_id = v.id
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_productos = [$fecha_inicio, $fecha_fin];
        $types_productos = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_productos_vendidos .= " AND v.sucursal_id = ?";
            $params_productos[] = $_SESSION['sucursal_id'];
            $types_productos .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_productos_vendidos .= " AND v.sucursal_id = ?";
            $params_productos[] = $sucursal_id;
            $types_productos .= "i";
        }
        
        $sql_productos_vendidos .= " GROUP BY p.id, p.nombre, p.codigo, p.precio, c.nombre ORDER BY total_vendido DESC LIMIT 10";
        
        $stmt_productos = $conn->prepare($sql_productos_vendidos);
        if ($stmt_productos) {
            $stmt_productos->bind_param($types_productos, ...$params_productos);
            $stmt_productos->execute();
            $result_productos = $stmt_productos->get_result();
            while ($row = $result_productos->fetch_assoc()) {
                $productos_vendidos[] = $row;
            }
            $stmt_productos->close();
        }
    }
    
    if ($tipo_reporte === 'general' || $tipo_reporte === 'ventas') {
        // Vendedores top
        $sql_vendedores = "
            SELECT 
                u.nombre as vendedor,
                s.nombre as sucursal,
                COUNT(*) as ventas_realizadas,
                COALESCE(SUM(v.total), 0) as total_vendido,
                COALESCE(AVG(v.total), 0) as promedio_venta
            FROM ventas v
            INNER JOIN usuarios u ON v.usuario_id = u.id
            INNER JOIN sucursales s ON v.sucursal_id = s.id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_vendedores = [$fecha_inicio, $fecha_fin];
        $types_vendedores = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_vendedores .= " AND v.sucursal_id = ?";
            $params_vendedores[] = $_SESSION['sucursal_id'];
            $types_vendedores .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_vendedores .= " AND v.sucursal_id = ?";
            $params_vendedores[] = $sucursal_id;
            $types_vendedores .= "i";
        }
        
        $sql_vendedores .= " GROUP BY u.id, u.nombre, s.nombre ORDER BY total_vendido DESC LIMIT 10";
        
        $stmt_vendedores = $conn->prepare($sql_vendedores);
        if ($stmt_vendedores) {
            $stmt_vendedores->bind_param($types_vendedores, ...$params_vendedores);
            $stmt_vendedores->execute();
            $result_vendedores = $stmt_vendedores->get_result();
            while ($row = $result_vendedores->fetch_assoc()) {
                $vendedores_top[] = $row;
            }
            $stmt_vendedores->close();
        }
    }
    
    if ($tipo_reporte === 'general' || $tipo_reporte === 'clientes') {
        // Clientes frecuentes
        $sql_clientes = "
            SELECT 
                c.nombre as cliente,
                c.tipo,
                c.telefono,
                COUNT(*) as compras_realizadas,
                COALESCE(SUM(v.total), 0) as total_gastado,
                COALESCE(AVG(v.total), 0) as promedio_compra,
                MAX(v.fecha) as ultima_compra
            FROM ventas v
            INNER JOIN clientes c ON v.cliente_id = c.id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'";
        
        $params_clientes = [$fecha_inicio, $fecha_fin];
        $types_clientes = "ss";
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sql_clientes .= " AND v.sucursal_id = ?";
            $params_clientes[] = $_SESSION['sucursal_id'];
            $types_clientes .= "i";
        } elseif (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $sql_clientes .= " AND v.sucursal_id = ?";
            $params_clientes[] = $sucursal_id;
            $types_clientes .= "i";
        }
        
        $sql_clientes .= " GROUP BY c.id, c.nombre, c.tipo, c.telefono ORDER BY total_gastado DESC LIMIT 10";
        
        $stmt_clientes = $conn->prepare($sql_clientes);
        if ($stmt_clientes) {
            $stmt_clientes->bind_param($types_clientes, ...$params_clientes);
            $stmt_clientes->execute();
            $result_clientes = $stmt_clientes->get_result();
            while ($row = $result_clientes->fetch_assoc()) {
                $clientes_frecuentes[] = $row;
            }
            $stmt_clientes->close();
        }
    }
    
    if ($tipo_reporte === 'general' || $tipo_reporte === 'inventario') {
        // Productos con stock bajo
        $sql_stock_bajo = "
            SELECT 
                p.nombre,
                p.codigo,
                p.stock,
                p.stock_minimo,
                c.nombre as categoria,
                'General' as sucursal
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE p.activo = TRUE 
            AND p.stock <= p.stock_minimo
            ORDER BY p.stock ASC
            LIMIT 15";
        
        $result_stock_bajo = $conn->query($sql_stock_bajo);
        if ($result_stock_bajo) {
            while ($row = $result_stock_bajo->fetch_assoc()) {
                $productos_stock_bajo[] = $row;
            }
        }
    }
    
    // Preparar datos para gráficas
    $graph_data = [
        'labels_grafica' => $labels_grafica,
        'data_grafica' => $data_grafica,
        'data_subtotal' => $data_subtotal,
        'data_iva' => $data_iva,
        'labels_horas' => $labels_horas,
        'data_horas' => $data_horas,
        'sucursales_nombres' => array_column($ventas_por_sucursal, 'sucursal'),
        'sucursales_ingresos' => array_column($ventas_por_sucursal, 'ingresos_totales')
    ];
    
    // Incluir el contenido HTML
    ob_start();
    include 'reporte_content.php';
    $html = ob_get_clean();
    
    echo json_encode([
        'success' => true,
        'html' => $html,
        'data' => $graph_data
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>