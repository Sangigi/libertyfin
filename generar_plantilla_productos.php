<?php
// generar_plantilla_productos.php
session_start();
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Login");
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Obtener colores personalizados
$color_primario = '#27ae60';
$color_secundario = '#2ecc71';

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if (!$conn->connect_error) {
        $sql_config = "SELECT color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
        $result_config = $conn->query($sql_config);
        if ($result_config && $result_config->num_rows > 0) {
            $config = $result_config->fetch_assoc();
            $color_primario = $config['color_primario'] ?? '#27ae60';
            $color_secundario = $config['color_secundario'] ?? '#2ecc71';
            $logo_empresa = $config['logo'] ?? null;
        }
    }
} catch (Exception $e) {
    // Si hay error, usar colores por defecto
}

// Crear nuevo libro
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// =============================================
// 1. AGREGAR LOGO (si existe)
// =============================================
$logo_path = null;
if (!empty($logo_empresa)) {
    $rutas_posibles = [
        $_SERVER['DOCUMENT_ROOT'] . '/' . $logo_empresa,
        $_SERVER['DOCUMENT_ROOT'] . '/../' . $logo_empresa,
        dirname(__FILE__) . '/' . $logo_empresa,
        dirname(__FILE__) . '/../' . $logo_empresa,
        dirname(__FILE__) . '/admin/' . $logo_empresa,
        dirname(__FILE__) . '/logos/' . $logo_empresa,
        $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $logo_empresa,
    ];
    
    foreach ($rutas_posibles as $ruta) {
        if (file_exists($ruta) && is_file($ruta)) {
            $logo_path = $ruta;
            break;
        }
    }
}

if ($logo_path) {
    try {
        $drawing = new Drawing();
        $drawing->setName('Logo Empresa');
        $drawing->setDescription('Logo de la empresa');
        $drawing->setPath($logo_path);
        $drawing->setHeight(60);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(5);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    } catch (Exception $e) {
        // Si falla el logo, continuar sin él
    }
}

// =============================================
// 2. TÍTULO DEL DOCUMENTO
// =============================================
$nombre_empresa = $_SESSION['empresa_nombre'] ?? 'Mi Empresa';
$sheet->setCellValue('C1', $nombre_empresa);
$sheet->mergeCells('C1:H1');
$sheet->getStyle('C1:H1')->getFont()->setSize(18)->setBold(true);
$sheet->getStyle('C1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('C2', 'PLANTILLA DE PRODUCTOS');
$sheet->mergeCells('C2:H2');
$sheet->getStyle('C2:H2')->getFont()->setSize(14)->setBold(true);
$sheet->getStyle('C2:H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Fecha de generación
$sheet->setCellValue('C3', 'Generado: ' . date('d/m/Y H:i'));
$sheet->mergeCells('C3:H3');
$sheet->getStyle('C3:H3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('C3:H3')->getFont()->setSize(9)->setItalic(true);

// =============================================
// 3. ENCABEZADOS DE COLUMNAS
// =============================================
$headers = [
    'A' => 'codigo',
    'B' => 'nombre',
    'C' => 'descripcion',
    'D' => 'marca',
    'E' => 'precio',
    'F' => 'subprecio',
    'G' => 'costo',
    'H' => 'descuento',
    'I' => 'stock',
    'J' => 'unidad_medida',
    'K' => 'peso_kg',
    'L' => 'permite_fracciones',
    'M' => 'categoria',
    'N' => 'proveedor',
    'O' => 'fecha_caducidad'
];

// Colores
$color_primario_hex = ltrim($color_primario, '#');
$color_secundario_hex = ltrim($color_secundario, '#');

// Fila de encabezados (fila 5, después del título)
$fila_headers = 5;
$columna_actual = 'A';

foreach ($headers as $columna => $nombre) {
    $celda = $columna . $fila_headers;
    $sheet->setCellValue($celda, $nombre);
    $sheet->getColumnDimension($columna)->setWidth(18);
    $sheet->getStyle($celda)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE));
    $sheet->getStyle($celda)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color_primario_hex);
    $sheet->getStyle($celda)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($celda)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// =============================================
// 4. FILA DE EJEMPLO (con datos de muestra)
// =============================================
$ejemplo = [
    'A' => 'PROD0001',
    'B' => 'Teclado Mecánico RGB',
    'C' => 'Teclado mecánico con switches azules, iluminación RGB',
    'D' => 'Logitech',
    'E' => 850.50,
    'F' => 950.00,
    'G' => 450.00,
    'H' => 10.5,
    'I' => 25,
    'J' => 'pieza',
    'K' => 1,
    'L' => 'no',
    'M' => 'Electrónica',
    'N' => 'Logitech',
    'O' => '2026-12-31'
];

$fila_ejemplo = 6;
$sheet->getStyle($fila_ejemplo)->getFont()->setItalic(true);
$sheet->getStyle($fila_ejemplo)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0F8FF');

foreach ($ejemplo as $columna => $valor) {
    $celda = $columna . $fila_ejemplo;
    $sheet->setCellValue($celda, $valor);
    $sheet->getStyle($celda)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// =============================================
// 5. INSTRUCCIONES
// =============================================
$fila_instrucciones = 8;
$sheet->mergeCells('A' . $fila_instrucciones . ':O' . $fila_instrucciones);
$sheet->setCellValue('A' . $fila_instrucciones, '📌 INSTRUCCIONES DE IMPORTACIÓN');
$sheet->getStyle('A' . $fila_instrucciones)->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A' . $fila_instrucciones)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$fila_instrucciones++;
$instrucciones = [
    '🔴 Los campos marcados con * son OBLIGATORIOS',
    '📝 No modifiques los nombres de las columnas',
    '📊 Puedes agregar todas las filas que necesites',
    '💡 El código debe ser único para cada producto',
    '⚖️ Unidades válidas: pieza, kilo, litro',
    '📅 Fecha de caducidad en formato YYYY-MM-DD',
    '✅ Valores para "permite_fracciones": si / no (o 1 / 0)'
];

foreach ($instrucciones as $texto) {
    $sheet->setCellValue('A' . $fila_instrucciones, $texto);
    $sheet->mergeCells('A' . $fila_instrucciones . ':O' . $fila_instrucciones);
    $sheet->getStyle('A' . $fila_instrucciones)->getFont()->setSize(9);
    $fila_instrucciones++;
}

// =============================================
// 6. COLOREAR CAMPOS OBLIGATORIOS
// =============================================
// Resaltar en amarillo los campos obligatorios
$campos_obligatorios = ['A', 'B', 'E', 'G']; // codigo, nombre, precio, costo
foreach ($campos_obligatorios as $columna) {
    $celda = $columna . $fila_headers;
    $sheet->getStyle($celda)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFED9C');
    $sheet->getStyle($celda)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF000000'));
    
    // Agregar asterisco al final del nombre
    $nombre_actual = $sheet->getCell($celda)->getValue();
    $sheet->setCellValue($celda, $nombre_actual . ' *');
}

// =============================================
// 7. CONGELAR PANEL
// =============================================
$sheet->freezePane('A' . ($fila_headers + 1));

// =============================================
// 8. FILTROS AUTOMÁTICOS
// =============================================
$sheet->setAutoFilter('A' . $fila_headers . ':O' . $fila_headers);

// =============================================
// 9. ANCHO DE COLUMNAS AJUSTADO
// =============================================
foreach (range('A', 'O') as $columna) {
    $sheet->getColumnDimension($columna)->setAutoSize(false);
    $sheet->getColumnDimension($columna)->setWidth(18);
}
// Anchos especiales
$sheet->getColumnDimension('C')->setWidth(30);  // descripcion
$sheet->getColumnDimension('O')->setWidth(16);  // fecha_caducidad

// =============================================
// 10. PROTEGER HOJA (opcional - solo celdas de datos)
// =============================================
// Dejar desprotegidas las celdas donde el usuario debe escribir
// (desde la fila 6 en adelante, todas las columnas)
for ($fila = $fila_ejemplo; $fila <= 1000; $fila++) {
    foreach (range('A', 'O') as $columna) {
        $sheet->getStyle($columna . $fila)->getProtection()->setLocked(false);
    }
}
$sheet->getProtection()->setSheet(true);
$sheet->getProtection()->setPassword('');

// =============================================
// 11. GUARDAR ARCHIVO
// =============================================
$writer = new Xlsx($spreadsheet);

// Configurar headers para descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="plantilla_productos.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

$writer->save('php://output');
exit();
?>