<?php
//reporte_inventario_bajo_stock.php
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

    // Crear un nuevo libro de Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS')
        ->setTitle('Reporte de Productos Bajo Stock')
        ->setSubject('Reporte generado automáticamente')
        ->setDescription('Reporte de productos que requieren reabastecimiento');

    // Obtener la hoja activa
    $sheet = $spreadsheet->getActiveSheet();
    
    // Configurar página
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    // Título del reporte
    $sheet->mergeCells('A1:H1');
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
    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', 'REPORTE DE PRODUCTOS BAJO STOCK');
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
    $sheet->mergeCells('A3:H3');
    $sheet->setCellValue('A3', 'Generado el: ' . date('d/m/Y H:i:s'));
    
    $row++;
    $sheet->mergeCells('A4:H4');
    $sheet->setCellValue('A4', 'Sucursal: ' . $sucursal_nombre);
    
    $row++;
    $sheet->mergeCells('A5:H5');
    $sheet->setCellValue('A5', 'Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    $row += 2; // Espacio

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
    if ($sucursal_id) {
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
    if ($sucursal_id) {
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
    
    if ($sucursal_id) {
        $stmt->bind_param("ii", $sucursal_id, $sucursal_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    // Encabezados de la tabla
    $headers = ['Código', 'Producto', 'Categoría', 'Descripción', 'Precio', 'Stock', 'Stock Mínimo', 'Estado'];
    
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
                'startColor' => ['rgb' => 'FF6B6B'],
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

    // Configurar la columna A como texto para que muestre códigos completos
    $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

    // Datos
    $total_productos = 0;
    $productos_agotados = 0;
    $productos_bajo_stock = 0;
    $valor_total = 0;

    while ($row_data = $result->fetch_assoc()) {
        $stock = $sucursal_id ? $row_data['stock_sucursal'] : $row_data['stock_total'];
        $stock_minimo = $row_data['stock_minimo_total'];
        $valor_producto = $row_data['precio'] * $stock;
        
        // Asignar código como texto usando setCellValueExplicit
        $sheet->setCellValueExplicit('A' . $row, $row_data['codigo'], DataType::TYPE_STRING);
        $sheet->setCellValue('B' . $row, $row_data['nombre']);
        $sheet->setCellValue('C' . $row, $row_data['categoria'] ?? 'Sin categoría');
        $sheet->setCellValue('D' . $row, $row_data['descripcion'] ?? '');
        $sheet->setCellValue('E' . $row, $row_data['precio']);
        $sheet->setCellValue('F' . $row, $stock);
        $sheet->setCellValue('G' . $row, $stock_minimo);
        $sheet->setCellValue('H' . $row, $row_data['estado_stock']);
        
        // Formato para precio
        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        
        // Color según estado
        $estado = $row_data['estado_stock'];
        if ($estado == 'AGOTADO') {
            $productos_agotados++;
            $sheet->getStyle('H' . $row)->getFont()->getColor()->setARGB('FF0000');
        } elseif ($estado == 'BAJO STOCK') {
            $productos_bajo_stock++;
            $sheet->getStyle('H' . $row)->getFont()->getColor()->setARGB('FF8C00');
        }
        
        $total_productos++;
        $valor_total += $valor_producto;
        $row++;
    }

    // Aplicar bordes a los datos
    if ($total_productos > 0) {
        $dataRange = 'A7:H' . ($row - 1);
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

    // Resumen
    $sheet->mergeCells('A' . $row . ':D' . $row);
    $sheet->setCellValue('A' . $row, 'RESUMEN DEL REPORTE');
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 12,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '2E86C1'],
        ],
    ]);
    $row++;

    $resumen_data = [
        ['Total de Productos con Problemas', $total_productos],
        ['Productos Agotados', $productos_agotados],
        ['Productos Bajo Stock', $productos_bajo_stock],
        ['Valor Total del Stock', $valor_total]
    ];

    foreach ($resumen_data as $index => $item) {
        $currentRow = $row + $index;
        $sheet->setCellValue('A' . $currentRow, $item[0]);
        $sheet->setCellValue('B' . $currentRow, $item[1]);
        
        // Formato para valor total
        if ($item[0] == 'Valor Total del Stock') {
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
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Crear el archivo Excel
    $filename = 'productos_bajo_stock_' . date('Ymd_His') . '.xlsx';
    
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
?>