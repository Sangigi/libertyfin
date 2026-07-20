<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Incluir TCPDF
require_once 'vendor/autoload.php';

use TCPDF;

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Obtener parámetros
$sucursal_id = $_GET['sucursal_id'] ?? null;
$categoria_id = $_GET['categoria_id'] ?? null;
$stock_filter = $_GET['stock_filter'] ?? '';

try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener información de la empresa
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch_assoc();

    // Obtener información de sucursal si es específica
    $sucursal_nombre = 'Todas las sucursales';
    if (!empty($sucursal_id) && is_numeric($sucursal_id)) {
        $sql_sucursal = "SELECT nombre FROM sucursales WHERE id = ?";
        $stmt_sucursal = $conn->prepare($sql_sucursal);
        $stmt_sucursal->bind_param("i", $sucursal_id);
        $stmt_sucursal->execute();
        $result_sucursal = $stmt_sucursal->get_result();
        if ($row = $result_sucursal->fetch_assoc()) {
            $sucursal_nombre = $row['nombre'];
        }
    }

    // Obtener información de categoría si es específica
    $categoria_nombre = 'Todas las categorías';
    if (!empty($categoria_id) && is_numeric($categoria_id)) {
        $sql_categoria = "SELECT nombre FROM categorias WHERE id = ?";
        $stmt_categoria = $conn->prepare($sql_categoria);
        $stmt_categoria->bind_param("i", $categoria_id);
        $stmt_categoria->execute();
        $result_categoria = $stmt_categoria->get_result();
        if ($row = $result_categoria->fetch_assoc()) {
            $categoria_nombre = $row['nombre'];
        }
    }

    // Construir consulta base
    $sql_base = "
        SELECT 
            p.codigo,
            p.nombre,
            c.nombre as categoria_nombre,
            p.descripcion,
            p.precio,
            p.subprecio,
            p.descuento,
            COALESCE(SUM(ps.stock), 0) as stock_total,
            COALESCE(MIN(ps.stock_minimo), 0) as stock_minimo_total,
            CASE 
                WHEN p.activo = 1 THEN 'Activo'
                ELSE 'Inactivo'
            END as estado,
            DATE_FORMAT(p.fecha_actualizacion, '%d/%m/%Y %H:%i') as fecha_actualizacion
    ";

    $params = [];
    $types = "";

    // Si hay una sucursal específica, obtener stock específico
    if ($sucursal_id && is_numeric($sucursal_id)) {
        $sql_base .= ", 
            COALESCE(
                (SELECT ps2.stock FROM producto_sucursal ps2 
                 WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?), 
                0
            ) as stock_sucursal
        ";
        $params[] = $sucursal_id;
        $types .= "i";
    }

    $sql_base .= "
        FROM productos p 
        LEFT JOIN categorias c ON p.categoria_id = c.id 
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
        WHERE 1=1
    ";

    // Filtro por sucursal
    if ($sucursal_id && is_numeric($sucursal_id)) {
        $sql_base .= " AND EXISTS (
            SELECT 1 FROM producto_sucursal ps2 
            WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?
        )";
        $params[] = $sucursal_id;
        $types .= "i";
    }

    // Filtro por categoría
    if ($categoria_id && is_numeric($categoria_id)) {
        $sql_base .= " AND p.categoria_id = ?";
        $params[] = $categoria_id;
        $types .= "i";
    }

    $sql_base .= " GROUP BY p.id, p.codigo, p.nombre, p.descripcion, p.precio, p.subprecio, p.descuento, p.activo, p.fecha_actualizacion, c.nombre";

    // Aplicar filtro de stock después del GROUP BY
    $having_clause = "";
    switch ($stock_filter) {
        case 'bajo':
            $having_clause = " HAVING (stock_total <= stock_minimo_total AND stock_total > 0)";
            break;
        case 'sin':
            $having_clause = " HAVING stock_total = 0";
            break;
        case 'normal':
            $having_clause = " HAVING stock_total > stock_minimo_total";
            break;
    }

    $sql = $sql_base . $having_clause . " ORDER BY p.nombre ASC";

    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    // Procesar datos para estadísticas
    $total_productos = 0;
    $total_stock = 0;
    $total_valor_inventario = 0;
    $productos_activos = 0;
    $productos_inactivos = 0;
    $bajo_stock_count = 0;
    $sin_stock_count = 0;

    $productos_data = [];
    while ($row_data = $result->fetch_assoc()) {
        $stock = $sucursal_id ? $row_data['stock_sucursal'] : $row_data['stock_total'];
        $valor_stock = $row_data['precio'] * $stock;
        
        $productos_data[] = [
            'codigo' => $row_data['codigo'],
            'nombre' => $row_data['nombre'],
            'categoria' => $row_data['categoria_nombre'] ?? 'Sin categoría',
            'descripcion' => $row_data['descripcion'] ?? '',
            'precio' => $row_data['precio'],
            'stock' => $stock,
            'stock_minimo' => $row_data['stock_minimo_total'],
            'valor_stock' => $valor_stock,
            'estado' => $row_data['estado'],
            'fecha_actualizacion' => $row_data['fecha_actualizacion']
        ];
        
        // Calcular estadísticas
        $total_productos++;
        $total_stock += $stock;
        $total_valor_inventario += $valor_stock;
        
        if ($row_data['estado'] == 'Activo') {
            $productos_activos++;
        } else {
            $productos_inactivos++;
        }
        
        if ($stock == 0) {
            $sin_stock_count++;
        } elseif ($stock <= $row_data['stock_minimo_total']) {
            $bajo_stock_count++;
        }
    }

    // Crear nuevo PDF
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

    // Configurar información del documento
    $pdf->SetCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS');
    $pdf->SetAuthor($_SESSION['usuario_nombre'] ?? 'Usuario');
    $pdf->SetTitle('Reporte de Inventario Completo');
    $pdf->SetSubject('Reporte generado automáticamente');
    $pdf->SetKeywords('Inventario, Productos, Stock');

    // Configurar márgenes
    $pdf->SetMargins(8, 15, 8);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Agregar página
    $pdf->AddPage();

    // ============================================
    // ENCABEZADO DEL REPORTE
    // ============================================
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(46, 134, 193);
    $pdf->Cell(0, 8, strtoupper($empresa_info['nombre_empresa'] ?? 'MI EMPRESA'), 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 7, 'REPORTE DE INVENTARIO COMPLETO', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 5, 'Generado el: ' . date('d/m/Y H:i:s') . ' | Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'), 0, 1, 'C');
    $pdf->Ln(5);

    // ============================================
    // INFORMACIÓN DE FILTROS - Usando MultiCell para mejor alineación
    // ============================================
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Fila 1
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(35, 6, 'Sucursal:', 1, 0, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(75, 6, $sucursal_nombre, 1, 0, 'L', true);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(35, 6, 'Categoría:', 1, 0, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(75, 6, $categoria_nombre, 1, 1, 'L', true);
    
    // Fila 2
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(35, 6, 'Filtro de Stock:', 1, 0, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(75, 6, getStockFilterName($stock_filter), 1, 0, 'L', true);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(35, 6, 'Total Productos:', 1, 0, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(75, 6, number_format($total_productos), 1, 1, 'L', true);
    
    $pdf->Ln(8);

    // ============================================
    // TABLA DE PRODUCTOS - Usando MultiCell para datos largos
    // ============================================
    
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(46, 134, 193);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(200, 200, 200);
    
    // Encabezados
    $pdf->Cell(25, 8, 'Código', 1, 0, 'C', true);
    $pdf->Cell(55, 8, 'Producto', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Categoría', 1, 0, 'C', true);
    $pdf->Cell(22, 8, 'Precio', 1, 0, 'C', true);
    $pdf->Cell(22, 8, 'Stock', 1, 0, 'C', true);
    $pdf->Cell(22, 8, 'Stock Mín.', 1, 0, 'C', true);
    $pdf->Cell(28, 8, 'Valor Stock', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Estado', 1, 1, 'C', true);
    
    // Datos
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetTextColor(0, 0, 0);
    
    $fill = false;
    foreach ($productos_data as $index => $producto) {
        $stock = $producto['stock'];
        
        // Alternar color de fondo para filas
        $bgColor = $fill ? 240 : 255;
        $pdf->SetFillColor($bgColor, $bgColor, $bgColor);
        
        // Columna 1: Código
        $pdf->Cell(25, 7, substr($producto['codigo'], 0, 15), 1, 0, 'L', $fill);
        
        // Columna 2: Producto
        $pdf->Cell(55, 7, substr($producto['nombre'], 0, 40), 1, 0, 'L', $fill);
        
        // Columna 3: Categoría
        $pdf->Cell(35, 7, substr($producto['categoria'], 0, 25), 1, 0, 'L', $fill);
        
        // Columna 4: Precio
        $pdf->Cell(22, 7, '$ ' . number_format($producto['precio'], 2), 1, 0, 'R', $fill);
        
        // Columna 5: Stock (con color según nivel)
        if ($stock == 0) {
            $pdf->SetTextColor(231, 76, 60);
        } elseif ($stock <= $producto['stock_minimo']) {
            $pdf->SetTextColor(243, 156, 18);
        } else {
            $pdf->SetTextColor(0, 0, 0);
        }
        $stock_text = number_format($stock, ($stock == floor($stock) ? 0 : 2));
        $pdf->Cell(22, 7, $stock_text, 1, 0, 'R', $fill);
        
        // Columna 6: Stock Mínimo
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(22, 7, number_format($producto['stock_minimo'], 0), 1, 0, 'R', $fill);
        
        // Columna 7: Valor Stock
        $pdf->Cell(28, 7, '$ ' . number_format($producto['valor_stock'], 2), 1, 0, 'R', $fill);
        
        // Columna 8: Estado
        if ($producto['estado'] == 'Activo') {
            $pdf->SetTextColor(39, 174, 96);
        } else {
            $pdf->SetTextColor(231, 76, 60);
        }
        $pdf->Cell(20, 7, $producto['estado'], 1, 1, 'C', $fill);
        
        $pdf->SetTextColor(0, 0, 0);
        $fill = !$fill;
    }
    
    if (empty($productos_data)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(229, 10, 'No se encontraron productos con los filtros seleccionados', 1, 1, 'C', true);
    }
    
    $pdf->Ln(8);
    
    // ============================================
    // RESUMEN ESTADÍSTICO
    // ============================================
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(39, 174, 96);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 7, ' RESUMEN ESTADÍSTICO', 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Tabla de resumen - 2 columnas
    $resumen_data = [
        ['Total de Productos', number_format($total_productos), false],
        ['Productos Activos', number_format($productos_activos), 'success'],
        ['Productos Inactivos', number_format($productos_inactivos), 'danger'],
        ['Total Stock (Unidades)', number_format($total_stock, 2), false],
        ['Productos Bajo Stock', number_format($bajo_stock_count), 'warning'],
        ['Productos Sin Stock', number_format($sin_stock_count), 'danger'],
        ['Valor Total del Inventario', '$ ' . number_format($total_valor_inventario, 2), 'success']
    ];
    
    $fill = true;
    foreach ($resumen_data as $item) {
        $pdf->SetFillColor($fill ? 242 : 255, $fill ? 242 : 255, $fill ? 242 : 255);
        
        // Primera columna
        $pdf->Cell(80, 7, $item[0], 1, 0, 'L', true);
        
        // Segunda columna con color
        if ($item[2] == 'success') {
            $pdf->SetTextColor(39, 174, 96);
        } elseif ($item[2] == 'danger') {
            $pdf->SetTextColor(231, 76, 60);
        } elseif ($item[2] == 'warning') {
            $pdf->SetTextColor(243, 156, 18);
        } else {
            $pdf->SetTextColor(0, 0, 0);
        }
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 7, $item[1], 1, 1, 'R', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        $fill = !$fill;
    }
    
    // Salida del PDF
    $filename = 'inventario_completo_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'D');

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$conn->close();

// Función para obtener el nombre del filtro de stock
function getStockFilterName($filter)
{
    switch ($filter) {
        case 'bajo':
            return 'Bajo Stock';
        case 'sin':
            return 'Sin Stock';
        case 'normal':
            return 'Stock Normal';
        default:
            return 'Todos';
    }
}
?>