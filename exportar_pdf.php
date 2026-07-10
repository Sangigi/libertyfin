<?php
// Iniciar buffer de salida para evitar errores de "headers already sent"
ob_start();
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    header("Location: login.php");
    exit();
}

require 'vendor/autoload.php';

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Obtener parámetros
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$tipo_reporte = $_GET['tipo_reporte'] ?? 'general';
$sucursal_id = $_GET['sucursal_id'] ?? '';

try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener información de la empresa
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, iva FROM sistema_config LIMIT 1";
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

    // Construir condición WHERE para sucursal
    $where_sucursal = '';
    $params_sucursal = [];
    $types_sucursal = '';

    // Si el usuario no es admin, solo puede ver su sucursal
    if ($_SESSION['usuario_rol'] !== 'admin') {
        $where_sucursal = " AND v.sucursal_id = ?";
        $params_sucursal[] = $_SESSION['sucursal_id'];
        $types_sucursal .= 'i';
    } else {
        // Si es admin, puede filtrar por sucursal específica
        if (!empty($sucursal_id) && is_numeric($sucursal_id)) {
            $where_sucursal = " AND v.sucursal_id = ?";
            $params_sucursal[] = $sucursal_id;
            $types_sucursal .= 'i';
        }
    }

    // Parámetros comunes para las consultas
    $params = array_merge([$fecha_inicio, $fecha_fin], $params_sucursal);
    $types = 'ss' . $types_sucursal;

    // Título del reporte
    $titulo_reporte = [
        'general' => 'REPORTE GENERAL DEL SISTEMA',
        'ventas' => 'REPORTE DETALLADO DE VENTAS',
        'inventario' => 'REPORTE DE INVENTARIO',
        'clientes' => 'REPORTE DE CLIENTES'
    ];

    $titulo = $titulo_reporte[$tipo_reporte] ?? 'REPORTE DEL SISTEMA';

    // Limpiar cualquier salida anterior antes de crear el PDF
    ob_end_clean();
    
    // Crear nuevo documento PDF
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

    // Configurar información del documento
    $pdf->SetCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS');
    $pdf->SetAuthor($empresa_info['nombre_empresa'] ?? 'Sistema POS');
    $pdf->SetTitle('Reporte ' . ucfirst($tipo_reporte));
    $pdf->SetSubject('Reporte generado automáticamente');

    // Eliminar header y footer por defecto
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Establecer márgenes
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Agregar una página
    $pdf->AddPage();

    // Estilos
    $pdf->SetFont('helvetica', 'B', 16);
    
    // Logo (si existe)
    // $pdf->Image('logo.png', 15, 15, 30, 0, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    
    // Encabezado
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(46, 134, 193); // Azul
    $pdf->Cell(0, 10, strtoupper($empresa_info['nombre_empresa'] ?? 'MI EMPRESA'), 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 0, 0); // Negro
    $pdf->Cell(0, 8, $titulo, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Período: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($fecha_fin)), 0, 1, 'C');
    $pdf->Cell(0, 6, 'Sucursal: ' . $sucursal_nombre, 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generado el: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'), 0, 1, 'C');
    
    $pdf->Ln(5);

    // Función para formatear dinero
    function formatMoneyPDF($amount) {
        if ($amount === null || $amount === '') {
            return '$0.00';
        }
        return '$' . number_format(floatval($amount), 2);
    }

    // Función para agregar tabla al PDF
    function agregarTablaPDF($pdf, $titulo, $headers, $data, $moneyColumns = [], $widths = null) {
        // Título de la sección
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(235, 245, 251); // Azul claro
        $pdf->SetTextColor(46, 134, 193); // Azul
        $pdf->Cell(0, 8, $titulo, 0, 1, 'L', 1);
        $pdf->Ln(2);
        
        // Calcular ancho de columnas si no se especifica
        if ($widths === null) {
            $numColumns = count($headers);
            $columnWidth = (277 - 30) / $numColumns; // Ancho total - márgenes / columnas
            $widths = array_fill(0, $numColumns, $columnWidth);
        }
        
        // Encabezados de tabla
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(46, 134, 193); // Azul
        $pdf->SetTextColor(255, 255, 255); // Blanco
        
        for ($i = 0; $i < count($headers); $i++) {
            $pdf->Cell($widths[$i], 7, $headers[$i], 1, 0, 'C', 1);
        }
        $pdf->Ln();
        
        // Datos de la tabla
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0); // Negro
        $fill = false;
        
        foreach ($data as $row) {
            $pdf->SetFillColor($fill ? 245 : 255, 245, 255);
            
            for ($i = 0; $i < count($headers); $i++) {
                $value = $row[$i] ?? '';
                
                // Formatear valores monetarios
                if (in_array($i, $moneyColumns)) {
                    if (is_numeric($value)) {
                        $value = formatMoneyPDF($value);
                    }
                    $align = 'R';
                } else {
                    $align = 'L';
                }
                
                $pdf->Cell($widths[$i], 6, $value, 1, 0, $align, $fill);
            }
            
            $pdf->Ln();
            $fill = !$fill;
        }
        
        $pdf->Ln(8);
    }

    // Generar contenido según el tipo de reporte
    if ($tipo_reporte === 'general') {
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
            AND v.estado = 'completada'
            $where_sucursal
        ";

        $stmt_estadisticas = $conn->prepare($sql_estadisticas);
        if (!empty($params)) {
            $stmt_estadisticas->bind_param($types, ...$params);
        }
        $stmt_estadisticas->execute();
        $result_estadisticas = $stmt_estadisticas->get_result();
        $estadisticas = $result_estadisticas->fetch_assoc();

        // Estadísticas Resumen
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(39, 174, 96); // Verde
        $pdf->SetTextColor(255, 255, 255); // Blanco
        $pdf->Cell(0, 8, 'ESTADÍSTICAS RESUMEN', 0, 1, 'C', 1);
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0); // Negro
        
        // Crear tabla de estadísticas de 2 columnas
        $statsData = [
            ['Ingresos Totales', $estadisticas['ingresos_totales'] ?? 0],
            ['Total Ventas', $estadisticas['total_ventas'] ?? 0],
            ['Promedio por Venta', $estadisticas['promedio_venta'] ?? 0],
            ['Clientes Activos', $estadisticas['clientes_activos'] ?? 0],
            ['IVA Recaudado', $estadisticas['iva_recaudado'] ?? 0],
            ['Productos con Stock', $estadisticas['productos_stock'] ?? 0],
            ['Productos Sin Stock', $estadisticas['productos_sin_stock'] ?? 0],
            ['Productos Bajo Stock', $estadisticas['productos_bajo_stock'] ?? 0],
        ];

        // Crear tabla de 2 columnas para estadísticas
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(120, 7, 'Concepto', 1, 0, 'C', 1);
        $pdf->Cell(60, 7, 'Valor', 1, 1, 'C', 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $fill = false;
        
        foreach ($statsData as $stat) {
            $pdf->SetFillColor($fill ? 245 : 255, 245, 255);
            $pdf->Cell(120, 7, $stat[0], 1, 0, 'L', $fill);
            
            // Formatear valores
            if (in_array($stat[0], ['Ingresos Totales', 'Promedio por Venta', 'IVA Recaudado'])) {
                $value = formatMoneyPDF($stat[1]);
                $align = 'R';
            } else {
                $value = number_format($stat[1]);
                $align = 'C';
            }
            
            $pdf->Cell(60, 7, $value, 1, 1, $align, $fill);
            $fill = !$fill;
        }
        
        $pdf->Ln(10);

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
            AND v.estado = 'completada'
            $where_sucursal
            GROUP BY DATE(v.fecha)
            ORDER BY fecha
        ";

        $stmt_ventas_dia = $conn->prepare($sql_ventas_dia);
        if (!empty($params)) {
            $stmt_ventas_dia->bind_param($types, ...$params);
        }
        $stmt_ventas_dia->execute();
        $result_ventas_dia = $stmt_ventas_dia->get_result();

        $ventas_por_dia = [];
        while ($row_data = $result_ventas_dia->fetch_assoc()) {
            $ventas_por_dia[] = [
                date('d/m/Y', strtotime($row_data['fecha'])),
                $row_data['cantidad_ventas'],
                $row_data['subtotal_dia'],
                $row_data['iva_dia'],
                $row_data['total_dia']
            ];
        }

        if (!empty($ventas_por_dia)) {
            $headers = ['Fecha', 'Cant. Ventas', 'Subtotal', 'IVA', 'Total'];
            $widths = [40, 30, 50, 50, 50];
            agregarTablaPDF($pdf, 'VENTAS POR DÍA', $headers, $ventas_por_dia, [2, 3, 4], $widths);
        }

        // Productos más vendidos
        $sql_productos_vendidos = "
            SELECT 
                p.nombre,
                p.codigo,
                c.nombre as categoria,
                SUM(vd.cantidad) as total_vendido,
                SUM(vd.total) as ingresos_totales
            FROM venta_detalles vd
            INNER JOIN productos p ON vd.producto_id = p.id
            INNER JOIN ventas v ON vd.venta_id = v.id
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'
            $where_sucursal
            GROUP BY p.id, p.nombre, p.codigo, c.nombre
            ORDER BY total_vendido DESC
            LIMIT 10
        ";

        $stmt_productos = $conn->prepare($sql_productos_vendidos);
        if (!empty($params)) {
            $stmt_productos->bind_param($types, ...$params);
        }
        $stmt_productos->execute();
        $result_productos = $stmt_productos->get_result();

        $productos_vendidos = [];
        while ($row_data = $result_productos->fetch_assoc()) {
            $productos_vendidos[] = [
                $row_data['codigo'],
                $row_data['nombre'],
                $row_data['categoria'],
                $row_data['total_vendido'],
                $row_data['ingresos_totales']
            ];
        }

        if (!empty($productos_vendidos)) {
            $headers = ['Código', 'Producto', 'Categoría', 'Cantidad Vendida', 'Ingresos Totales'];
            $widths = [30, 70, 50, 40, 50];
            agregarTablaPDF($pdf, 'TOP 10 PRODUCTOS MÁS VENDIDOS', $headers, $productos_vendidos, [4], $widths);
        }

        // Métodos de pago
        $sql_metodos_pago = "
            SELECT 
                v.metodo_pago,
                COUNT(*) as cantidad_ventas,
                COALESCE(SUM(v.total), 0) as total_metodo
            FROM ventas v
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'
            $where_sucursal
            GROUP BY v.metodo_pago
            ORDER BY total_metodo DESC
        ";

        $stmt_metodos = $conn->prepare($sql_metodos_pago);
        if (!empty($params)) {
            $stmt_metodos->bind_param($types, ...$params);
        }
        $stmt_metodos->execute();
        $result_metodos = $stmt_metodos->get_result();

        $metodos_pago = [];
        while ($row_data = $result_metodos->fetch_assoc()) {
            $metodos_pago[] = [
                ucfirst($row_data['metodo_pago']),
                $row_data['cantidad_ventas'],
                $row_data['total_metodo']
            ];
        }

        if (!empty($metodos_pago)) {
            $headers = ['Método de Pago', 'Cantidad de Ventas', 'Total Recaudado'];
            $widths = [70, 50, 60];
            agregarTablaPDF($pdf, 'DISTRIBUCIÓN POR MÉTODO DE PAGO', $headers, $metodos_pago, [2], $widths);
        }

        // Clientes frecuentes
        $sql_clientes = "
            SELECT 
                c.nombre as cliente,
                c.tipo,
                c.telefono,
                COUNT(*) as compras_realizadas,
                COALESCE(SUM(v.total), 0) as total_gastado,
                MAX(v.fecha) as ultima_compra
            FROM ventas v
            INNER JOIN clientes c ON v.cliente_id = c.id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'
            $where_sucursal
            GROUP BY c.id, c.nombre, c.tipo, c.telefono
            ORDER BY total_gastado DESC
            LIMIT 10
        ";

        $stmt_clientes = $conn->prepare($sql_clientes);
        if (!empty($params)) {
            $stmt_clientes->bind_param($types, ...$params);
        }
        $stmt_clientes->execute();
        $result_clientes = $stmt_clientes->get_result();

        $clientes_frecuentes = [];
        while ($row_data = $result_clientes->fetch_assoc()) {
            $clientes_frecuentes[] = [
                $row_data['cliente'],
                $row_data['tipo'],
                $row_data['telefono'],
                $row_data['compras_realizadas'],
                $row_data['total_gastado'],
                date('d/m/Y', strtotime($row_data['ultima_compra']))
            ];
        }

        if (!empty($clientes_frecuentes)) {
            $pdf->AddPage();
            $headers = ['Cliente', 'Tipo', 'Teléfono', 'Compras Realizadas', 'Total Gastado', 'Última Compra'];
            $widths = [50, 30, 40, 40, 50, 40];
            agregarTablaPDF($pdf, 'TOP 10 CLIENTES MÁS FRECUENTES', $headers, $clientes_frecuentes, [4], $widths);
        }

        // Productos con stock bajo
        $sql_stock_bajo = "
            SELECT 
                p.nombre,
                p.codigo,
                c.nombre as categoria,
                p.stock,
                p.stock_minimo,
                'General' as sucursal
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE p.activo = TRUE 
            AND p.stock <= p.stock_minimo
            ORDER BY p.stock ASC
            LIMIT 15
        ";
        
        $result_stock_bajo = $conn->query($sql_stock_bajo);
        $productos_stock_bajo = [];
        
        while ($row_data = $result_stock_bajo->fetch_assoc()) {
            $productos_stock_bajo[] = [
                $row_data['codigo'],
                $row_data['nombre'],
                $row_data['categoria'],
                $row_data['stock'],
                $row_data['stock_minimo'],
                $row_data['sucursal']
            ];
        }

        if (!empty($productos_stock_bajo)) {
            $headers = ['Código', 'Producto', 'Categoría', 'Stock Actual', 'Stock Mínimo', 'Sucursal'];
            $widths = [30, 70, 50, 30, 30, 40];
            agregarTablaPDF($pdf, 'PRODUCTOS CON STOCK BAJO', $headers, $productos_stock_bajo, [], $widths);
        }

    } elseif ($tipo_reporte === 'ventas') {
        // Reporte detallado de ventas
        $sql_ventas_detalle = "
            SELECT 
                v.codigo_venta as folio,
                DATE(v.fecha) as fecha,
                TIME(v.fecha) as hora,
                c.nombre as cliente,
                v.metodo_pago,
                v.subtotal,
                v.iva,
                v.total,
                u.nombre as vendedor,
                s.nombre as sucursal
            FROM ventas v
            LEFT JOIN clientes c ON v.cliente_id = c.id
            INNER JOIN usuarios u ON v.usuario_id = u.id
            INNER JOIN sucursales s ON v.sucursal_id = s.id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            AND v.estado = 'completada'
            $where_sucursal
            ORDER BY v.fecha DESC
        ";

        $stmt_ventas = $conn->prepare($sql_ventas_detalle);
        if (!empty($params)) {
            $stmt_ventas->bind_param($types, ...$params);
        }
        $stmt_ventas->execute();
        $result_ventas = $stmt_ventas->get_result();

        $ventas_detalle = [];
        $total_subtotal = 0;
        $total_iva = 0;
        $total_total = 0;
        $contador = 0;
        
        while ($row_data = $result_ventas->fetch_assoc()) {
            $ventas_detalle[] = [
                $row_data['folio'],
                date('d/m/Y', strtotime($row_data['fecha'])),
                $row_data['hora'],
                $row_data['cliente'] ?? 'Consumidor Final',
                ucfirst($row_data['metodo_pago']),
                $row_data['subtotal'],
                $row_data['iva'],
                $row_data['total'],
                $row_data['vendedor'],
                $row_data['sucursal']
            ];
            
            $total_subtotal += $row_data['subtotal'];
            $total_iva += $row_data['iva'];
            $total_total += $row_data['total'];
            $contador++;
            
            // Si hay muchas ventas, crear páginas adicionales
            if ($contador % 15 == 0) {
                $pdf->AddPage();
            }
        }

        if (!empty($ventas_detalle)) {
            $headers = ['Folio', 'Fecha', 'Hora', 'Cliente', 'Método Pago', 'Subtotal', 'IVA', 'Total', 'Vendedor', 'Sucursal'];
            $widths = [25, 25, 20, 40, 25, 25, 25, 25, 30, 30];
            agregarTablaPDF($pdf, 'DETALLE DE VENTAS', $headers, $ventas_detalle, [5, 6, 7], $widths);

            // Totales
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(39, 174, 96); // Verde
            $pdf->SetTextColor(255, 255, 255); // Blanco
            
            // Ajustar celdas para totales (sumar anchos de las primeras 5 columnas)
            $totalWidth1 = array_sum(array_slice($widths, 0, 5));
            $pdf->Cell($totalWidth1, 8, 'TOTALES:', 1, 0, 'R', 1);
            
            $pdf->Cell($widths[5], 8, formatMoneyPDF($total_subtotal), 1, 0, 'R', 1);
            $pdf->Cell($widths[6], 8, formatMoneyPDF($total_iva), 1, 0, 'R', 1);
            $pdf->Cell($widths[7], 8, formatMoneyPDF($total_total), 1, 0, 'R', 1);
            
            // Espacio para columnas vacías
            $pdf->Cell($widths[8] + $widths[9], 8, '', 1, 1, 'R', 1);
            
            $pdf->Ln(10);
        }

    } elseif ($tipo_reporte === 'inventario') {
        // Reporte de inventario
        $sql_inventario = "
            SELECT 
                p.codigo,
                p.nombre,
                c.nombre as categoria,
                p.descripcion,
                p.precio,
                p.stock,
                p.stock_minimo,
                CASE 
                    WHEN p.stock <= 0 THEN 'AGOTADO'
                    WHEN p.stock <= p.stock_minimo THEN 'BAJO'
                    ELSE 'DISPONIBLE'
                END as estado_stock
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE p.activo = TRUE
            ORDER BY p.nombre
        ";

        $result_inventario = $conn->query($sql_inventario);
        $inventario = [];
        $contador = 0;
        
        while ($row_data = $result_inventario->fetch_assoc()) {
            $inventario[] = [
                $row_data['codigo'],
                $row_data['nombre'],
                $row_data['categoria'],
                substr($row_data['descripcion'] ?? '', 0, 30) . (strlen($row_data['descripcion'] ?? '') > 30 ? '...' : ''),
                $row_data['precio'],
                $row_data['stock'],
                $row_data['stock_minimo'],
                $row_data['estado_stock']
            ];
            
            $contador++;
            
            // Si hay muchos productos, crear páginas adicionales
            if ($contador % 20 == 0) {
                $pdf->AddPage();
            }
        }

        if (!empty($inventario)) {
            $headers = ['Código', 'Producto', 'Categoría', 'Descripción', 'Precio', 'Stock', 'Stock Mínimo', 'Estado'];
            $widths = [25, 50, 35, 50, 25, 20, 25, 25];
            agregarTablaPDF($pdf, 'INVENTARIO DE PRODUCTOS', $headers, $inventario, [4], $widths);

            // Resumen de inventario
            $sql_resumen_inventario = "
                SELECT 
                    COUNT(*) as total_productos,
                    SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) as productos_con_stock,
                    SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as productos_agotados,
                    SUM(CASE WHEN stock <= stock_minimo AND stock > 0 THEN 1 ELSE 0 END) as productos_bajo_stock,
                    SUM(stock * precio) as valor_inventario
                FROM productos
                WHERE activo = TRUE
            ";

            $result_resumen = $conn->query($sql_resumen_inventario);
            $resumen = $result_resumen->fetch_assoc();

            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetFillColor(46, 134, 193); // Azul
            $pdf->SetTextColor(255, 255, 255); // Blanco
            $pdf->Cell(0, 8, 'RESUMEN DE INVENTARIO', 0, 1, 'L', 1);
            $pdf->Ln(2);

            $resumen_data = [
                ['Total de Productos', $resumen['total_productos'] ?? 0],
                ['Productos con Stock', $resumen['productos_con_stock'] ?? 0],
                ['Productos Agotados', $resumen['productos_agotados'] ?? 0],
                ['Productos Bajo Stock', $resumen['productos_bajo_stock'] ?? 0],
                ['Valor Total del Inventario', formatMoneyPDF($resumen['valor_inventario'] ?? 0)]
            ];

            // Crear tabla de resumen
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(46, 134, 193); // Azul
            $pdf->SetTextColor(255, 255, 255); // Blanco
            $pdf->Cell(120, 7, 'Concepto', 1, 0, 'C', 1);
            $pdf->Cell(60, 7, 'Valor', 1, 1, 'C', 1);
            
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0); // Negro
            $fill = false;
            
            foreach ($resumen_data as $item) {
                $pdf->SetFillColor($fill ? 245 : 255, 245, 255);
                $pdf->Cell(120, 7, $item[0], 1, 0, 'L', $fill);
                
                if ($item[0] == 'Valor Total del Inventario') {
                    $align = 'R';
                    $value = $item[1];
                } else {
                    $align = 'C';
                    $value = number_format($item[1]);
                }
                
                $pdf->Cell(60, 7, $value, 1, 1, $align, $fill);
                $fill = !$fill;
            }
        }

    } elseif ($tipo_reporte === 'clientes') {
        // Reporte de clientes
        $sql_clientes_detalle = "
            SELECT 
                c.nombre,
                c.tipo,
                c.rfc,
                c.telefono,
                c.email,
                c.direccion,
                COUNT(v.id) as total_compras,
                COALESCE(SUM(v.total), 0) as total_gastado,
                MAX(v.fecha) as ultima_compra
            FROM clientes c
            LEFT JOIN ventas v ON c.id = v.cliente_id AND v.estado = 'completada'
            GROUP BY c.id, c.nombre, c.tipo, c.rfc, c.telefono, c.email, c.direccion
            ORDER BY total_gastado DESC
        ";

        $result_clientes = $conn->query($sql_clientes_detalle);
        $clientes = [];
        $contador = 0;
        
        while ($row_data = $result_clientes->fetch_assoc()) {
            $clientes[] = [
                $row_data['nombre'],
                $row_data['tipo'],
                $row_data['rfc'] ?? '',
                $row_data['telefono'] ?? '',
                $row_data['email'] ?? '',
                substr($row_data['direccion'] ?? '', 0, 30) . (strlen($row_data['direccion'] ?? '') > 30 ? '...' : ''),
                $row_data['total_compras'],
                $row_data['total_gastado'],
                $row_data['ultima_compra'] ? date('d/m/Y', strtotime($row_data['ultima_compra'])) : 'Sin compras'
            ];
            
            $contador++;
            
            // Si hay muchos clientes, crear páginas adicionales
            if ($contador % 15 == 0) {
                $pdf->AddPage();
            }
        }

        if (!empty($clientes)) {
            $headers = ['Nombre', 'Tipo', 'RFC', 'Teléfono', 'Email', 'Dirección', 'Total Compras', 'Total Gastado', 'Última Compra'];
            $widths = [40, 25, 35, 30, 40, 40, 25, 30, 30];
            agregarTablaPDF($pdf, 'REPORTE DE CLIENTES', $headers, $clientes, [7], $widths);
        }
    }

    // Pie de página
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 10, 'Página ' . $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'C');

    // Salida del PDF
    $filename = 'reporte_' . $tipo_reporte . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'D');

} catch (Exception $e) {
    // Limpiar buffer en caso de error
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    die("Error: " . $e->getMessage());
}

if (isset($conn)) {
    $conn->close();
}
?>