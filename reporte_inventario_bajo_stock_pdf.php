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

    // Consulta para productos bajo stock
    $sql = "
        SELECT 
            p.codigo,
            p.nombre,
            c.nombre as categoria,
            p.descripcion,
            p.precio,
            SUM(COALESCE(ps.stock, 0)) as stock_total,
            MIN(COALESCE(ps.stock_minimo, 0)) as stock_minimo_total,
            CASE 
                WHEN SUM(COALESCE(ps.stock, 0)) <= 0 THEN 'AGOTADO'
                WHEN SUM(COALESCE(ps.stock, 0)) <= MIN(COALESCE(ps.stock_minimo, 0)) THEN 'BAJO STOCK'
                ELSE 'DISPONIBLE'
            END as estado_stock
    ";

    // Si hay una sucursal específica, obtener stock específico
    if ($sucursal_id && is_numeric($sucursal_id)) {
        $sql .= ", 
            COALESCE(
                (SELECT ps2.stock FROM producto_sucursal ps2 
                 WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?), 
                0
            ) as stock_sucursal
        ";
    }

    $sql .= "
        FROM productos p 
        LEFT JOIN categorias c ON p.categoria_id = c.id 
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
        WHERE p.activo = 1
    ";

    // Si hay una sucursal específica, agregar condición
    if ($sucursal_id && is_numeric($sucursal_id)) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM producto_sucursal ps2 
            WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?
        )";
    }

    $sql .= "
        GROUP BY p.id, p.codigo, p.nombre, p.descripcion, p.precio, c.nombre
        HAVING stock_total <= stock_minimo_total OR stock_total <= 0
        ORDER BY estado_stock, stock_total ASC
    ";

    $stmt = $conn->prepare($sql);
    
    if ($sucursal_id && is_numeric($sucursal_id)) {
        $stmt->bind_param("ii", $sucursal_id, $sucursal_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    // Procesar datos
    $productos_data = [];
    $total_productos = 0;
    $productos_agotados = 0;
    $productos_bajo_stock = 0;
    $valor_total = 0;

    while ($row_data = $result->fetch_assoc()) {
        $stock = ($sucursal_id && is_numeric($sucursal_id)) ? $row_data['stock_sucursal'] : $row_data['stock_total'];
        $stock_minimo = $row_data['stock_minimo_total'];
        $valor_producto = $row_data['precio'] * $stock;
        
        $productos_data[] = [
            'codigo' => $row_data['codigo'],
            'nombre' => $row_data['nombre'],
            'categoria' => $row_data['categoria'] ?? 'Sin categoría',
            'descripcion' => $row_data['descripcion'] ?? '',
            'precio' => $row_data['precio'],
            'stock' => $stock,
            'stock_minimo' => $stock_minimo,
            'valor_stock' => $valor_producto,
            'estado_stock' => $row_data['estado_stock']
        ];
        
        $total_productos++;
        $valor_total += $valor_producto;
        
        if ($row_data['estado_stock'] == 'AGOTADO') {
            $productos_agotados++;
        } elseif ($row_data['estado_stock'] == 'BAJO STOCK') {
            $productos_bajo_stock++;
        }
    }

    // Crear nuevo PDF
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

    // Configurar información del documento
    $pdf->SetCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS');
    $pdf->SetAuthor($_SESSION['usuario_nombre'] ?? 'Usuario');
    $pdf->SetTitle('Reporte de Productos Bajo Stock');
    $pdf->SetSubject('Reporte generado automáticamente');
    $pdf->SetKeywords('Inventario, Stock, Bajo Stock');

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
    $pdf->Cell(0, 7, 'REPORTE DE PRODUCTOS BAJO STOCK', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 5, 'Generado el: ' . date('d/m/Y H:i:s') . ' | Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'), 0, 1, 'C');
    $pdf->Ln(5);

    // ============================================
    // INFORMACIÓN DE FILTROS
    // ============================================
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->SetFont('helvetica', '', 9);
    
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(35, 6, 'Sucursal:', 1, 0, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(75, 6, $sucursal_nombre, 1, 1, 'L', true);
    
    $pdf->Ln(8);

    // ============================================
    // TABLA DE PRODUCTOS
    // ============================================
    
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(255, 107, 107); // Color naranja/rojo para el encabezado
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(200, 200, 200);
    
    // Encabezados (8 columnas)
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
        if ($producto['estado_stock'] == 'AGOTADO') {
            $pdf->SetTextColor(231, 76, 60); // Rojo
        } else {
            $pdf->SetTextColor(243, 156, 18); // Naranja
        }
        $stock_text = number_format($stock, ($stock == floor($stock) ? 0 : 2));
        $pdf->Cell(22, 7, $stock_text, 1, 0, 'R', $fill);
        
        // Columna 6: Stock Mínimo
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(22, 7, number_format($producto['stock_minimo'], 0), 1, 0, 'R', $fill);
        
        // Columna 7: Valor Stock
        $pdf->Cell(28, 7, '$ ' . number_format($producto['valor_stock'], 2), 1, 0, 'R', $fill);
        
        // Columna 8: Estado
        if ($producto['estado_stock'] == 'AGOTADO') {
            $pdf->SetTextColor(231, 76, 60);
        } else {
            $pdf->SetTextColor(243, 156, 18);
        }
        $pdf->Cell(20, 7, $producto['estado_stock'], 1, 1, 'C', $fill);
        
        $pdf->SetTextColor(0, 0, 0);
        $fill = !$fill;
    }
    
    if (empty($productos_data)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(229, 10, 'No se encontraron productos con problemas de stock', 1, 1, 'C', true);
    }
    
    $pdf->Ln(8);
    
    // ============================================
    // RESUMEN ESTADÍSTICO
    // ============================================
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(46, 134, 193);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 7, ' RESUMEN DEL REPORTE', 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Tabla de resumen
    $resumen_data = [
        ['Total de Productos con Problemas', number_format($total_productos), false],
        ['Productos Agotados', number_format($productos_agotados), 'danger'],
        ['Productos Bajo Stock', number_format($productos_bajo_stock), 'warning'],
        ['Valor Total del Stock', '$ ' . number_format($valor_total, 2), 'success']
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
    $filename = 'productos_bajo_stock_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'D');

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$conn->close();
?>