<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Incluir PhpSpreadsheet
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

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
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email FROM sistema_config LIMIT 1";
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

    // Crear un nuevo libro de Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS')
        ->setTitle('Reporte de Inventario Completo')
        ->setSubject('Reporte generado automáticamente')
        ->setDescription('Reporte completo del inventario de productos');

    // Obtener la hoja activa
    $sheet = $spreadsheet->getActiveSheet();
    
    // Configurar página
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    // Título del reporte
    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', strtoupper($empresa_info['nombre_empresa'] ?? 'MI EMPRESA'));
    $sheet->getStyle('A1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '2E86C1'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    $row = 2;
    $sheet->mergeCells('A2:J2');
    $sheet->setCellValue('A2', 'REPORTE DE INVENTARIO COMPLETO');
    $sheet->getStyle('A2')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 14,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    $row++;
    $sheet->mergeCells('A3:J3');
    $sheet->setCellValue('A3', 'Generado el: ' . date('d/m/Y H:i:s'));
    
    $row++;
    $sheet->mergeCells('A4:J4');
    $sheet->setCellValue('A4', 'Sucursal: ' . $sucursal_nombre);
    
    $row++;
    $sheet->mergeCells('A5:J5');
    $sheet->setCellValue('A5', 'Categoría: ' . $categoria_nombre);
    
    $row++;
    $sheet->mergeCells('A6:J6');
    $sheet->setCellValue('A6', 'Filtro de Stock: ' . getStockFilterName($stock_filter));
    
    $row++;
    $sheet->mergeCells('A7:J7');
    $sheet->setCellValue('A7', 'Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    $row += 2; // Espacio

    // Construir consulta base
    $sql_base = "
        SELECT 
            p.codigo,
            p.nombre,
            c.nombre as categoria_nombre,
            p.descripcion,
            p.precio,
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

    $sql_base .= " GROUP BY p.id, p.codigo, p.nombre, p.descripcion, p.precio, p.activo, p.fecha_actualizacion, c.nombre";

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

    // Encabezados de la tabla
    $headers = [
        'Código',
        'Producto',
        'Categoría',
        'Descripción',
        'Precio',
        $sucursal_id ? 'Stock (Sucursal)' : 'Stock (Total)',
        'Stock Mínimo',
        'Valor en Stock',
        'Estado',
        'Última Actualización'
    ];
    
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '2E86C1'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
        $col++;
    }

    $row++;
    
    // *** SOLUCIÓN PARA EL CÓDIGO: Forzar formato de texto en la columna A ***
    // Aplicar formato de texto a toda la columna A antes de llenar los datos
    $sheet->getStyle('A')->getNumberFormat()->setFormatCode('@');

    // Datos
    $total_productos = 0;
    $total_stock = 0;
    $total_valor_inventario = 0;
    $productos_activos = 0;
    $productos_inactivos = 0;

    while ($row_data = $result->fetch_assoc()) {
        $stock = $sucursal_id ? $row_data['stock_sucursal'] : $row_data['stock_total'];
        $valor_stock = $row_data['precio'] * $stock;
        
        // *** USAR setCellValueExplicit para forzar el código como texto ***
        $sheet->setCellValueExplicit('A' . $row, (string)$row_data['codigo'], DataType::TYPE_STRING);
        
        $sheet->setCellValue('B' . $row, $row_data['nombre']);
        $sheet->setCellValue('C' . $row, $row_data['categoria_nombre'] ?? 'Sin categoría');
        $sheet->setCellValue('D' . $row, $row_data['descripcion'] ?? '');
        $sheet->setCellValue('E' . $row, $row_data['precio']);
        $sheet->setCellValue('F' . $row, $stock);
        $sheet->setCellValue('G' . $row, $row_data['stock_minimo_total']);
        $sheet->setCellValue('H' . $row, $valor_stock);
        $sheet->setCellValue('I' . $row, $row_data['estado']);
        $sheet->setCellValue('J' . $row, $row_data['fecha_actualizacion']);
        
        // Formato para precio y valor en stock
        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        
        // Color según estado del producto
        if ($row_data['estado'] == 'Activo') {
            $sheet->getStyle('I' . $row)->getFont()->getColor()->setARGB('008000');
            $productos_activos++;
        } else {
            $sheet->getStyle('I' . $row)->getFont()->getColor()->setARGB('FF0000');
            $productos_inactivos++;
        }
        
        // Color según nivel de stock
        if ($stock == 0) {
            $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF0000');
        } elseif ($stock <= $row_data['stock_minimo_total']) {
            $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF8C00');
        }
        
        $total_productos++;
        $total_stock += $stock;
        $total_valor_inventario += $valor_stock;
        $row++;
    }

    // Aplicar bordes a los datos
    if ($total_productos > 0) {
        $dataRange = 'A9:J' . ($row - 1);
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ]);
    }

    $row += 2;

    // Resumen estadístico
    $sheet->mergeCells('A' . $row . ':D' . $row);
    $sheet->setCellValue('A' . $row, 'RESUMEN ESTADÍSTICO');
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 12,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '27ae60'],
        ],
    ]);
    $row++;

    // Calcular productos bajo stock (incluyendo filtros)
    $sql_bajo_stock_base = "
        SELECT 
            p.id,
            COALESCE(SUM(ps.stock), 0) as stock_total,
            COALESCE(MIN(ps.stock_minimo), 0) as stock_minimo_total
        FROM productos p
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
        WHERE p.activo = 1
    ";

    $params_bajo = [];
    $types_bajo = "";

    // Aplicar mismos filtros que en la consulta principal
    if ($sucursal_id && is_numeric($sucursal_id)) {
        $sql_bajo_stock_base .= " AND EXISTS (
            SELECT 1 FROM producto_sucursal ps2 
            WHERE ps2.producto_id = p.id AND ps2.sucursal_id = ?
        )";
        $params_bajo[] = $sucursal_id;
        $types_bajo .= "i";
    }

    if ($categoria_id && is_numeric($categoria_id)) {
        $sql_bajo_stock_base .= " AND p.categoria_id = ?";
        $params_bajo[] = $categoria_id;
        $types_bajo .= "i";
    }

    $sql_bajo_stock_base .= " GROUP BY p.id";
    $sql_bajo_stock = "SELECT COUNT(*) as bajo_stock FROM (" . $sql_bajo_stock_base . ") as subquery WHERE stock_total <= stock_minimo_total AND stock_total > 0";
    
    $stmt_bajo = $conn->prepare($sql_bajo_stock);
    if (!empty($params_bajo)) {
        $stmt_bajo->bind_param($types_bajo, ...$params_bajo);
    }
    $stmt_bajo->execute();
    $result_bajo_stock = $stmt_bajo->get_result();
    $bajo_stock = $result_bajo_stock->fetch_assoc()['bajo_stock'] ?? 0;

    // Calcular productos sin stock (incluyendo filtros)
    $sql_sin_stock = "SELECT COUNT(*) as sin_stock FROM (" . $sql_bajo_stock_base . ") as subquery WHERE stock_total = 0";
    
    $stmt_sin = $conn->prepare($sql_sin_stock);
    if (!empty($params_bajo)) {
        $stmt_sin->bind_param($types_bajo, ...$params_bajo);
    }
    $stmt_sin->execute();
    $result_sin_stock = $stmt_sin->get_result();
    $sin_stock = $result_sin_stock->fetch_assoc()['sin_stock'] ?? 0;

    $resumen_data = [
        ['Total de Productos', $total_productos],
        ['Productos Activos', $productos_activos],
        ['Productos Inactivos', $productos_inactivos],
        ['Total Stock', $total_stock],
        ['Productos Bajo Stock', $bajo_stock],
        ['Productos Sin Stock', $sin_stock],
        ['Valor Total del Inventario', $total_valor_inventario]
    ];

    foreach ($resumen_data as $index => $item) {
        $currentRow = $row + $index;
        $sheet->setCellValue('A' . $currentRow, $item[0]);
        $sheet->setCellValue('B' . $currentRow, $item[1]);
        
        // Formato para valor total del inventario
        if ($item[0] == 'Valor Total del Inventario') {
            $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('$#,##0.00');
        }
        
        $cellRange = 'A' . $currentRow . ':B' . $currentRow;
        $sheet->getStyle($cellRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'DDDDDD'],
                ],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $index % 2 == 0 ? 'F2F2F2' : 'FFFFFF'],
            ],
        ]);
    }

    // Ajustar automáticamente el ancho de las columnas
    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Crear el archivo Excel
    $filename = 'inventario_completo_' . date('Ymd_His') . '.xlsx';
    
    // Configurar headers para descarga
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    // Guardar en output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

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