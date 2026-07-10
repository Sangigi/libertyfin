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
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$sucursal_id = $_GET['sucursal_id'] ?? null;
$producto_id = $_GET['producto_id'] ?? null;

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

    // Obtener información del producto si es específico
    $producto_nombre = 'Todos los productos';
    if (!empty($producto_id) && is_numeric($producto_id)) {
        $sql_producto = "SELECT nombre FROM productos WHERE id = ?";
        $stmt_producto = $conn->prepare($sql_producto);
        $stmt_producto->bind_param("i", $producto_id);
        $stmt_producto->execute();
        $result_producto = $stmt_producto->get_result();
        if ($row = $result_producto->fetch_assoc()) {
            $producto_nombre = $row['nombre'];
        }
    }

    // Crear un nuevo libro de Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS')
        ->setTitle('Reporte de Movimientos de Inventario')
        ->setSubject('Reporte generado automáticamente')
        ->setDescription('Reporte de entradas y salidas de inventario');

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
    $sheet->setCellValue('A2', 'REPORTE DE MOVIMIENTOS DE INVENTARIO');
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
    $sheet->setCellValue('A3', 'Período: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($fecha_fin)));
    
    $row++;
    $sheet->mergeCells('A4:J4');
    $sheet->setCellValue('A4', 'Sucursal: ' . $sucursal_nombre);
    
    $row++;
    $sheet->mergeCells('A5:J5');
    $sheet->setCellValue('A5', 'Producto: ' . $producto_nombre);
    
    $row++;
    $sheet->mergeCells('A6:J6');
    $sheet->setCellValue('A6', 'Generado el: ' . date('d/m/Y H:i:s'));
    
    $row++;
    $sheet->mergeCells('A7:J7');
    $sheet->setCellValue('A7', 'Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    $row += 2; // Espacio

    // Consulta para movimientos de inventario
    $sql = "
        SELECT 
            DATE_FORMAT(mi.fecha, '%d/%m/%Y %H:%i') as fecha_hora,
            p.codigo as producto_codigo,
            p.nombre as producto_nombre,
            s.nombre as sucursal_nombre,
            CASE mi.tipo
                WHEN 'entrada' THEN 'ENTRADA'
                WHEN 'salida' THEN 'SALIDA'
                WHEN 'ajuste' THEN 'AJUSTE'
                WHEN 'transferencia_entrada' THEN 'TRANSFERENCIA ENTRADA'
                WHEN 'transferencia_salida' THEN 'TRANSFERENCIA SALIDA'
                ELSE UPPER(mi.tipo)
            END as tipo_movimiento,
            mi.cantidad,
            mi.cantidad_anterior,
            mi.cantidad_nueva,
            u.nombre as usuario_nombre,
            mi.observaciones
        FROM movimientos_inventario mi
        LEFT JOIN productos p ON mi.producto_id = p.id
        LEFT JOIN sucursales s ON mi.sucursal_id = s.id
        LEFT JOIN usuarios u ON mi.usuario_id = u.id
        WHERE DATE(mi.fecha) BETWEEN ? AND ?
    ";

    $params = [$fecha_inicio, $fecha_fin];
    $types = "ss";

    if ($sucursal_id) {
        $sql .= " AND mi.sucursal_id = ?";
        $params[] = $sucursal_id;
        $types .= "i";
    }

    if ($producto_id) {
        $sql .= " AND mi.producto_id = ?";
        $params[] = $producto_id;
        $types .= "i";
    }

    $sql .= " ORDER BY mi.fecha DESC";

    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    // Encabezados de la tabla
    $headers = ['Fecha y Hora', 'Código Producto', 'Producto', 'Sucursal', 'Tipo Movimiento', 'Cantidad', 'Stock Anterior', 'Stock Nuevo', 'Usuario', 'Observaciones'];
    
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
    
    // *** SOLUCIÓN PARA EL CÓDIGO: Forzar formato de texto en la columna de código (columna B) ***
    // Aplicar formato de texto a toda la columna B antes de llenar los datos
    $sheet->getStyle('B')->getNumberFormat()->setFormatCode('@');

    // Datos
    $total_entradas = 0;
    $total_salidas = 0;
    $total_movimientos = 0;

    while ($row_data = $result->fetch_assoc()) {
        $tipo = strtolower($row_data['tipo_movimiento']);
        
        $sheet->setCellValue('A' . $row, $row_data['fecha_hora']);
        
        // *** USAR setCellValueExplicit para forzar el código como texto ***
        $sheet->setCellValueExplicit('B' . $row, (string)($row_data['producto_codigo'] ?? 'N/A'), DataType::TYPE_STRING);
        
        $sheet->setCellValue('C' . $row, $row_data['producto_nombre'] ?? 'N/A');
        $sheet->setCellValue('D' . $row, $row_data['sucursal_nombre'] ?? 'N/A');
        $sheet->setCellValue('E' . $row, $row_data['tipo_movimiento']);
        $sheet->setCellValue('F' . $row, $row_data['cantidad']);
        $sheet->setCellValue('G' . $row, $row_data['cantidad_anterior']);
        $sheet->setCellValue('H' . $row, $row_data['cantidad_nueva']);
        $sheet->setCellValue('I' . $row, $row_data['usuario_nombre'] ?? 'N/A');
        $sheet->setCellValue('J' . $row, $row_data['observaciones'] ?? '');
        
        // Color según tipo de movimiento
        if (strpos($tipo, 'entrada') !== false) {
            $sheet->getStyle('E' . $row)->getFont()->getColor()->setARGB('008000');
            $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('008000');
            $total_entradas += $row_data['cantidad'];
        } elseif (strpos($tipo, 'salida') !== false) {
            $sheet->getStyle('E' . $row)->getFont()->getColor()->setARGB('FF0000');
            $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF0000');
            $total_salidas += $row_data['cantidad'];
        } elseif ($tipo == 'ajuste') {
            $sheet->getStyle('E' . $row)->getFont()->getColor()->setARGB('FF8C00');
        }
        
        $total_movimientos++;
        $row++;
    }

    // Aplicar bordes a los datos
    if ($total_movimientos > 0) {
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

    // Resumen
    $sheet->mergeCells('A' . $row . ':C' . $row);
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
        ['Total de Movimientos', $total_movimientos],
        ['Total Entradas', $total_entradas],
        ['Total Salidas', $total_salidas],
        ['Balance Neto', $total_entradas - $total_salidas]
    ];

    foreach ($resumen_data as $index => $item) {
        $currentRow = $row + $index;
        $sheet->setCellValue('A' . $currentRow, $item[0]);
        $sheet->setCellValue('B' . $currentRow, $item[1]);
        
        // Color para balance neto
        if ($item[0] == 'Balance Neto') {
            $balance = $item[1];
            if ($balance > 0) {
                $sheet->getStyle('B' . $currentRow)->getFont()->getColor()->setARGB('008000');
            } elseif ($balance < 0) {
                $sheet->getStyle('B' . $currentRow)->getFont()->getColor()->setARGB('FF0000');
            }
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
    $filename = 'movimientos_inventario_' . date('Ymd_His') . '.xlsx';
    
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