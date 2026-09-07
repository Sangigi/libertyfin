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
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

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
// Filtro por colaborador: aplica SOLO al bloque de comisiones.
$colaborador_id = isset($_GET['colaborador_id']) && is_numeric($_GET['colaborador_id'])
                ? (int)$_GET['colaborador_id'] : 0;

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

    // Crear un nuevo libro de Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS')
        ->setTitle('Reporte ' . ucfirst($tipo_reporte))
        ->setSubject('Reporte generado automáticamente')
        ->setDescription('Reporte del sistema POS');

    // Crear estilos
    $headerStyle = [
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
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ];

    $titleStyle = [
        'font' => [
            'bold' => true,
            'size' => 14,
            'color' => ['rgb' => '2E86C1'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ];

    // Obtener la hoja activa
    $sheet = $spreadsheet->getActiveSheet();
    
    // Configurar página
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    // Título del reporte
    $titulo_reporte = [
        'general' => 'REPORTE GENERAL DEL SISTEMA',
        'ventas' => 'REPORTE DETALLADO DE VENTAS',
        'inventario' => 'REPORTE DE INVENTARIO',
        'clientes' => 'REPORTE DE CLIENTES'
    ];

    $titulo = $titulo_reporte[$tipo_reporte] ?? 'REPORTE DEL SISTEMA';

    // Cabecera del reporte
    $row = 1;
    $sheet->mergeCells('A1:F1');
    $sheet->setCellValue('A1', strtoupper($empresa_info['nombre_empresa'] ?? 'MI EMPRESA'));
    $sheet->getStyle('A1')->applyFromArray($titleStyle);

    $row++;
    $sheet->mergeCells('A2:F2');
    $sheet->setCellValue('A2', $titulo);
    $sheet->getStyle('A2')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 12,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    $row++;
    $sheet->mergeCells('A3:F3');
    $sheet->setCellValue('A3', 'Período: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($fecha_fin)));
    
    $row++;
    $sheet->mergeCells('A4:F4');
    $sheet->setCellValue('A4', 'Sucursal: ' . $sucursal_nombre);
    
    $row++;
    $sheet->mergeCells('A5:F5');
    $sheet->setCellValue('A5', 'Generado el: ' . date('d/m/Y H:i:s'));
    
    $row++;
    $sheet->mergeCells('A6:F6');
    $sheet->setCellValue('A6', 'Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    $row += 2; // Espacio


// Nombre del mes y año en español, para el titulo del reporte de comisiones.
function strftime_es($fecha) {
    $meses = [1=>'ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO',
              'AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
    $ts = strtotime($fecha);
    return $meses[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

    // Función para agregar tabla
    function agregarTabla($sheet, &$row, $titulo, $headers, $data, $moneyColumns = []) {
        // Título de la sección
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->setCellValue('A' . $row, $titulo);
        $sheet->getStyle('A' . $row)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => '2E86C1'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EBF5FB'],
            ],
        ]);
        $row++;

        // Encabezados
        $col = 'A';
        $headerCells = [];
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $headerCells[] = $col . $row;
            $col++;
        }
        
        $headerRange = 'A' . $row . ':' . chr(ord('A') + count($headers) - 1) . $row;
        $sheet->getStyle($headerRange)->applyFromArray([
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

        // Datos
        $row++;
        $startRow = $row;
        
        foreach ($data as $item) {
            $col = 'A';
            foreach ($headers as $key => $header) {
                $value = $item[$key] ?? '';
                
                // Formatear valores monetarios
                if (in_array($key, $moneyColumns)) {
                    $value = is_numeric($value) ? floatval($value) : 0;
                    $sheet->setCellValue($col . $row, $value);
                    // Formato de moneda mexicana
                    $sheet->getStyle($col . $row)->getNumberFormat()
                        ->setFormatCode('$#,##0.00');
                } else {
                    $sheet->setCellValue($col . $row, $value);
                }
                
                $col++;
            }
            $row++;
        }

        // Bordes para los datos
        $endRow = $row - 1;
        if ($endRow >= $startRow) {
            $dataRange = 'A' . $startRow . ':' . chr(ord('A') + count($headers) - 1) . $endRow;
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
            ]);
        }

        $row++; // Espacio entre tablas
        return $row;
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
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->setCellValue('A' . $row, 'ESTADÍSTICAS RESUMEN');
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
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ]);
        $row++;

        // Crear tabla de estadísticas
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

        $col = 'A';
        foreach ($statsData as $index => $stat) {
            if ($index % 2 == 0) {
                $currentRow = $row + floor($index / 2);
                $sheet->setCellValue($col . $currentRow, $stat[0]);
                $sheet->setCellValue(chr(ord($col) + 1) . $currentRow, $stat[1]);
                
                // Aplicar formato de moneda a los valores monetarios
                if (in_array($stat[0], ['Ingresos Totales', 'Promedio por Venta', 'IVA Recaudado'])) {
                    $sheet->getStyle(chr(ord($col) + 1) . $currentRow)->getNumberFormat()
                        ->setFormatCode('$#,##0.00');
                }
                
                // Alternar colores de fondo
                $style = [
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => ($index / 2) % 2 == 0 ? 'F2F2F2' : 'FFFFFF'],
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'DDDDDD'],
                        ],
                    ],
                ];
                
                $cellRange = $col . $currentRow . ':' . chr(ord($col) + 1) . $currentRow;
                $sheet->getStyle($cellRange)->applyFromArray($style);
            }
        }

        $row += ceil(count($statsData) / 2) + 2;

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
            $row = agregarTabla($sheet, $row, 'VENTAS POR DÍA', $headers, $ventas_por_dia, [2, 3, 4]);
        }

        // Productos más vendidos - CORREGIDO: agregado JOIN con ventas
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
            $row = agregarTabla($sheet, $row, 'TOP 10 PRODUCTOS MÁS VENDIDOS', $headers, $productos_vendidos, [4]);
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
            $row = agregarTabla($sheet, $row, 'DISTRIBUCIÓN POR MÉTODO DE PAGO', $headers, $metodos_pago, [2]);
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
            $headers = ['Cliente', 'Tipo', 'Teléfono', 'Compras Realizadas', 'Total Gastado', 'Última Compra'];
            $row = agregarTabla($sheet, $row, 'TOP 10 CLIENTES MÁS FRECUENTES', $headers, $clientes_frecuentes, [4]);
        }

        // =============================================================
        // COMISIONES · formato de reporte mensual por area (tabla cruzada)
        //
        // Una fila por venta, una columna por colaborador, agrupado por
        // area, con subtotal por area y gran total al final.
        //
        //   TOTAL A COMISIONAR = DEPOSITADO - COSTO DE SERVICIO - IVA
        //
        // El "costo de servicio" son los gastos de operacion manuales de
        // la venta (tipo='manual'); los automaticos son costo de
        // mercancia y ya vienen restados en monto_base.
        // =============================================================
        $where_colaborador = !empty($colaborador_id) ? " AND pc.colaborador_id = ?" : "";

        // =============================================================
        // COMISIONES Y COBRANZA · una fila por PAGO recibido
        //   cobrado - costo de venta - gastos de venta - comisiones
        //   = utilidad
        // Costo y gastos se prorratean por la proporcion cobrada.
        // =============================================================
        $sql_comisiones = "
            SELECT
                pc.area_nombre,
                pc.colaborador_nombre,
                pc.monto      AS monto_comision,
                p.id          AS pago_id,
                p.monto       AS cobrado,
                p.fecha_pago,
                p.tipo        AS tipo_pago,
                p.metodo_pago,
                v.id          AS venta_id,
                v.codigo_venta,
                v.fecha       AS fecha_venta,
                v.total       AS venta_total,
                COALESCE(cl.nombre, 'Cliente General') AS cliente_nombre
            FROM pago_comisiones pc
            INNER JOIN venta_pagos p ON p.id = pc.pago_id AND p.cancelado = 0
            INNER JOIN ventas v      ON v.id = pc.venta_id
            LEFT JOIN clientes cl    ON cl.id = v.cliente_id
            WHERE p.fecha_pago BETWEEN ? AND ?
            $where_sucursal
            $where_colaborador
            ORDER BY pc.area_nombre, p.fecha_pago, p.id
        ";

        // El filtro de colaborador se agrega aparte: solo aplica aqui.
        $params_com = $params;
        $types_com  = $types;
        if (!empty($colaborador_id)) {
            $params_com[] = $colaborador_id;
            $types_com   .= 'i';
        }

        $stmt_comisiones = $conn->prepare($sql_comisiones);
        if (!empty($params_com)) {
            $stmt_comisiones->bind_param($types_com, ...$params_com);
        }
        $stmt_comisiones->execute();
        $result_comisiones = $stmt_comisiones->get_result();

        // Armar la estructura: areas -> ventas -> colaboradores
        $areas_com   = [];
        $ventas_vist = [];
        while ($r = $result_comisiones->fetch_assoc()) {
            $area  = $r['area_nombre'] !== '' ? $r['area_nombre'] : 'SIN AREA';
            $pid   = (int)$r['pago_id'];
            $colab = $r['colaborador_nombre'];

            if (!isset($areas_com[$area])) {
                $areas_com[$area] = ['pagos' => [], 'colaboradores' => []];
            }
            if (!isset($areas_com[$area]['pagos'][$pid])) {
                $vt = (float)$r['venta_total'];
                $areas_com[$area]['pagos'][$pid] = [
                    'venta_id'    => (int)$r['venta_id'],
                    'folio'       => $r['codigo_venta'],
                    'concepto'    => $r['cliente_nombre'],
                    'fecha_venta' => $r['fecha_venta'],
                    'fecha_pago'  => $r['fecha_pago'],
                    'tipo'        => $r['tipo_pago'],
                    'banco'       => ucfirst((string)$r['metodo_pago']),
                    'cobrado'     => (float)$r['cobrado'],
                    'venta_total' => $vt,
                    'proporcion'  => $vt > 0 ? ((float)$r['cobrado'] / $vt) : 0,
                    'colab'       => []
                ];
            }
            $areas_com[$area]['pagos'][$pid]['colab'][$colab] =
                ($areas_com[$area]['pagos'][$pid]['colab'][$colab] ?? 0) + (float)$r['monto_comision'];
            $areas_com[$area]['colaboradores'][$colab] = true;
            $ventas_vist[(int)$r['venta_id']] = true;
        }

        // Costo y gastos totales por venta (una vez por linea de detalle)
        $costos_venta = [];
        if (!empty($ventas_vist)) {
            $ids = implode(',', array_map('intval', array_keys($ventas_vist)));
            $res_c = $conn->query("
                SELECT venta_id, SUM(costo) AS costo, SUM(gasto) AS gasto
                FROM (
                    SELECT venta_id, venta_detalle_id,
                           MAX(costo_unitario * cantidad) AS costo,
                           MAX(gasto_operacion)           AS gasto
                    FROM venta_comisiones
                    WHERE venta_id IN ($ids) AND cancelada = 0
                    GROUP BY venta_id, venta_detalle_id
                ) x GROUP BY venta_id");
            if ($res_c) {
                while ($g = $res_c->fetch_assoc()) {
                    $costos_venta[(int)$g['venta_id']] = [
                        'costo' => (float)$g['costo'],
                        'gasto' => (float)$g['gasto']
                    ];
                }
            }
        }

        if (!empty($areas_com)) {
            $mesTitulo = strtoupper(strftime_es($fecha_inicio));

            // Si se filtro por una persona, se rotula el reporte
            $colab_titulo = '';
            if (!empty($colaborador_id)) {
                $st = $conn->prepare("SELECT nombre FROM comision_colaboradores WHERE id = ?");
                $st->bind_param("i", $colaborador_id);
                $st->execute();
                $rs = $st->get_result();
                if ($rn = $rs->fetch_assoc()) {
                    $colab_titulo = ' · ' . mb_strtoupper($rn['nombre']);
                }
            }
            $mesTitulo .= $colab_titulo;

            // Encabezado del reporte de comisiones
            $sheet->setCellValue('B' . $row, $mesTitulo);
            $sheet->getStyle('B' . $row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row += 2;

            // El desglose completo, para que la fila se pueda reconstruir:
            //   PRECIO DE VENTA - COSTO DE MERCANCÍA - COSTO DE SERVICIO = A COMISIONAR
            // DESCUENTO y CANTIDAD DEPOSITADA son informativos: hoy el
            // descuento NO reduce la base de comisión.
            $fijas = ['FOLIO DE VENTA', 'CONCEPTO', 'FECHA VENTA', 'FECHA DE PAGO', 'TIPO',
                      'BANCO EN EL QUE ESTA', 'TOTAL DE LA VENTA', 'COBRADO', '% COBRADO',
                      'COSTO DE VENTA', 'GASTOS DE VENTA', 'COMISIONES', 'UTILIDAD'];
            $N_FIJAS   = count($fijas);
            $IDX_MONEY = 6;   // desde 'TOTAL DE LA VENTA' son montos

            $gran = ['cobrado' => 0, 'costo' => 0, 'gasto' => 0, 'comision' => 0];
            $gran_colab   = [];
            $colab_areas  = [];
            $colab_conteo = [];
            $pagos_gt     = [];

            foreach ($areas_com as $area => $datos) {
                $colabs = array_keys($datos['colaboradores']);
                sort($colabs);

                $sheet->setCellValue('B' . $row, strtoupper($area));
                $sheet->getStyle('B' . $row)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E86C1']],
                ]);
                $row++;

                $headers = array_merge($fijas, $colabs);
                $col = 'B';
                foreach ($headers as $h) {
                    $sheet->setCellValue($col . $row, $h);
                    $col = chr(ord($col) + 1);
                }
                $colFin = chr(ord('B') + count($headers) - 1);
                $sheet->getStyle('B' . $row . ':' . $colFin . $row)->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B9BD5']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                                    'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(32);
                $row++;
                $filaPrimerDato = $row;

                $tot = ['cobrado' => 0, 'costo' => 0, 'gasto' => 0, 'comision' => 0];
                $tot_colab = array_fill_keys($colabs, 0.0);

                foreach ($datos['pagos'] as $pid => $pg) {
                    $cv    = $costos_venta[$pg['venta_id']] ?? ['costo' => 0, 'gasto' => 0];
                    $costo = round($cv['costo'] * $pg['proporcion'], 2);
                    $gasto = round($cv['gasto'] * $pg['proporcion'], 2);

                    $comision_pago = 0;
                    foreach ($colabs as $c) { $comision_pago += ($pg['colab'][$c] ?? 0); }
                    $utilidad = round($pg['cobrado'] - $costo - $gasto - $comision_pago, 2);

                    $vals = [
                        $pg['folio'],
                        $pg['concepto'],
                        date('d/m/Y', strtotime($pg['fecha_venta'])),
                        date('d/m/Y', strtotime($pg['fecha_pago'])),
                        ucfirst($pg['tipo']),
                        $pg['banco'],
                        $pg['venta_total'],
                        $pg['cobrado'],
                        round($pg['proporcion'] * 100, 1) . '%',
                        $costo,
                        $gasto,
                        $comision_pago,
                        $utilidad,
                    ];
                    foreach ($colabs as $c) {
                        $vals[] = ($pg['colab'][$c] ?? null) ?: null;
                    }

                    $col = 'B';
                    foreach ($vals as $idx => $v) {
                        if ($v === null) { $col = chr(ord($col) + 1); continue; }
                        if ($idx === 0) {
                            // El folio son 14 digitos: como numero Excel lo
                            // muestra como 2.02608E+13
                            $sheet->setCellValueExplicit($col . $row, (string)$v,
                                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('@');
                            $col = chr(ord($col) + 1);
                            continue;
                        }
                        $sheet->setCellValue($col . $row, $v);
                        if ($idx >= $IDX_MONEY && $idx !== 8) {
                            $sheet->getStyle($col . $row)->getNumberFormat()
                                  ->setFormatCode('$#,##0.00;[Red]($#,##0.00);"-"');
                        }
                        $col = chr(ord($col) + 1);
                    }

                    $tot['cobrado']  += $pg['cobrado'];
                    $tot['costo']    += $costo;
                    $tot['gasto']    += $gasto;
                    $tot['comision'] += $comision_pago;

                    if (!isset($pagos_gt[$pid])) {
                        $gran['cobrado'] += $pg['cobrado'];
                        $gran['costo']   += $costo;
                        $gran['gasto']   += $gasto;
                        $pagos_gt[$pid] = true;
                    }
                    $gran['comision'] += $comision_pago;
                    foreach ($colabs as $c) {
                        $m = $pg['colab'][$c] ?? 0;
                        $tot_colab[$c]  += $m;
                        $gran_colab[$c]  = ($gran_colab[$c] ?? 0) + $m;
                        if ($m > 0) {
                            $colab_areas[$c][$area] = true;
                            $colab_conteo[$c] = ($colab_conteo[$c] ?? 0) + 1;
                        }
                    }
                    $row++;
                }

                if ($row > $filaPrimerDato) {
                    $sheet->getStyle('B' . $filaPrimerDato . ':' . $colFin . ($row - 1))
                          ->applyFromArray(['borders' => ['allBorders' =>
                              ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9D9']]]]);
                }

                // Total del area
                $ut_area = round($tot['cobrado'] - $tot['costo'] - $tot['gasto'] - $tot['comision'], 2);
                $sheet->setCellValue('B' . $row, 'TOTAL DEL AREA');
                $col = chr(ord('B') + $IDX_MONEY + 1);   // columna COBRADO
                $sheet->setCellValue($col . $row, $tot['cobrado']);
                $col = chr(ord($col) + 2);               // saltar % COBRADO
                foreach ([$tot['costo'], $tot['gasto'], $tot['comision'], $ut_area] as $v) {
                    $sheet->setCellValue($col . $row, $v);
                    $col = chr(ord($col) + 1);
                }
                foreach ($colabs as $c) {
                    $sheet->setCellValue($col . $row, $tot_colab[$c]);
                    $col = chr(ord($col) + 1);
                }
                $sheet->getStyle('B' . $row . ':' . $colFin . $row)->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);
                $sheet->getStyle(chr(ord('B') + $IDX_MONEY) . $row . ':' . $colFin . $row)
                      ->getNumberFormat()->setFormatCode('$#,##0.00;[Red]($#,##0.00);"-"');
                $row += 2;
            }

            // =========================================================
            // TOTALES DEL PERIODO · la cascada completa
            // =========================================================
            $utilidad_mes = round($gran['cobrado'] - $gran['costo'] - $gran['gasto'] - $gran['comision'], 2);

            $sheet->setCellValue('B' . $row, 'TOTALES DEL PERIODO');
            $sheet->mergeCells('B' . $row . ':F' . $row);
            $sheet->getStyle('B' . $row)->applyFromArray([
                'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $row++;

            $cascada = [
                ['COBRADO EN EL PERIODO',   $gran['cobrado'],  '27AE60'],
                ['(-) COSTO DE VENTA',      $gran['costo'],    'F2F2F2'],
                ['(-) GASTOS DE VENTA',     $gran['gasto'],    'FFFFFF'],
                ['(-) COMISIONES',          $gran['comision'], 'F2F2F2'],
                ['(=) UTILIDAD',            $utilidad_mes,     'FCE4D6'],
            ];
            foreach ($cascada as $linea) {
                $sheet->setCellValue('B' . $row, $linea[0]);
                $sheet->mergeCells('B' . $row . ':E' . $row);
                $sheet->setCellValue('F' . $row, $linea[1]);
                $sheet->getStyle('B' . $row . ':F' . $row)->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $linea[2]]],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);
                $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('$#,##0.00;[Red]($#,##0.00)');
                $row++;
            }

            $sheet->setCellValue('B' . $row, 'Sobre lo REALMENTE COBRADO en el periodo, no sobre lo vendido. Un abono de una venta de otro mes cuenta en el mes en que entro el dinero.');
            $sheet->getStyle('B' . $row)->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '555555']],
            ]);
            $row += 2;

            $colabs_gt = array_keys($gran_colab);
            sort($colabs_gt);
            $total_pagar_colab = array_sum($gran_colab);

            // =========================================================
            // TOTAL DE COMISIONES POR PAGAR · desglosado por persona
            // Es la hoja que se usa para dispersar los pagos, asi que va
            // una fila por colaborador con lo que le toca, y el total al
            // final como control.
            // =========================================================
            $sheet->setCellValue('B' . $row, 'COMISIONES POR PAGAR ' . $mesTitulo);
            $sheet->mergeCells('B' . $row . ':E' . $row);
            $sheet->getStyle('B' . $row)->applyFromArray([
                'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            // Encabezados del desglose
            $sheet->setCellValue('B' . $row, 'COLABORADOR');
            $sheet->setCellValue('C' . $row, 'ÁREA(S)');
            $sheet->setCellValue('D' . $row, 'COMISIONES');
            $sheet->setCellValue('E' . $row, 'MONTO A PAGAR');
            $sheet->getStyle('B' . $row . ':E' . $row)->applyFromArray([
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B9BD5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $row++;
            $filaPrimerPago = $row;

            // De mayor a menor, que es como se revisa una nomina
            arsort($gran_colab);

            $cebra = false;
            foreach ($gran_colab as $c => $monto) {
                $sheet->setCellValue('B' . $row, $c);
                $sheet->setCellValue('C' . $row, implode(', ', array_keys($colab_areas[$c] ?? [])));
                $sheet->setCellValue('D' . $row, $colab_conteo[$c] ?? 0);
                $sheet->setCellValue('E' . $row, $monto);

                $sheet->getStyle('B' . $row . ':E' . $row)->applyFromArray([
                    'fill'    => ['fillType' => Fill::FILL_SOLID,
                                  'startColor' => ['rgb' => $cebra ? 'F2F2F2' : 'FFFFFF']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                                   'color' => ['rgb' => 'D9D9D9']]],
                ]);
                $sheet->getStyle('D' . $row)->getAlignment()
                      ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
                $cebra = !$cebra;
                $row++;
            }

            // Total de control
            $sheet->setCellValue('B' . $row, 'TOTAL DE COMISIONES POR PAGAR');
            $sheet->mergeCells('B' . $row . ':D' . $row);
            $sheet->setCellValue('E' . $row, $total_pagar_colab);
            $sheet->getStyle('B' . $row . ':E' . $row)->applyFromArray([
                'font'    => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27AE60']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheet->getStyle('B' . $row)->getAlignment()
                  ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row += 3;
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
            $row = agregarTabla($sheet, $row, 'PRODUCTOS CON STOCK BAJO', $headers, $productos_stock_bajo);
        }

    } elseif ($tipo_reporte === 'ventas') {
        // Reporte detallado de ventas - CORREGIDO: v.folio por v.codigo_venta
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
        }

        if (!empty($ventas_detalle)) {
            $headers = ['Folio', 'Fecha', 'Hora', 'Cliente', 'Método Pago', 'Subtotal', 'IVA', 'Total', 'Vendedor', 'Sucursal'];
            $row = agregarTabla($sheet, $row, 'DETALLE DE VENTAS', $headers, $ventas_detalle, [5, 6, 7]);

            // Totales
            $sheet->mergeCells('A' . $row . ':E' . $row);
            $sheet->setCellValue('A' . $row, 'TOTALES:');
            $sheet->getStyle('A' . $row)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '27ae60'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
            ]);

            $sheet->setCellValue('F' . $row, $total_subtotal);
            $sheet->setCellValue('G' . $row, $total_iva);
            $sheet->setCellValue('H' . $row, $total_total);
            
            $sheet->getStyle('F' . $row . ':H' . $row)->applyFromArray([
                'font' => [
                    'bold' => true,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D5F4E6'],
                ],
            ]);
            
            // Aplicar formato de moneda a los totales
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
            
            $row += 2;
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
        
        while ($row_data = $result_inventario->fetch_assoc()) {
            $inventario[] = [
                $row_data['codigo'],
                $row_data['nombre'],
                $row_data['categoria'],
                $row_data['descripcion'] ?? '',
                $row_data['precio'],
                $row_data['stock'],
                $row_data['stock_minimo'],
                $row_data['estado_stock']
            ];
        }

        if (!empty($inventario)) {
            $headers = ['Código', 'Producto', 'Categoría', 'Descripción', 'Precio', 'Stock', 'Stock Mínimo', 'Estado'];
            $row = agregarTabla($sheet, $row, 'INVENTARIO DE PRODUCTOS', $headers, $inventario, [4]);

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

            $row += 2;
            $sheet->mergeCells('A' . $row . ':F' . $row);
            $sheet->setCellValue('A' . $row, 'RESUMEN DE INVENTARIO');
            $sheet->getStyle('A' . $row)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 11,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2E86C1'],
                ],
            ]);
            $row++;

            $resumen_data = [
                ['Total de Productos', $resumen['total_productos'] ?? 0],
                ['Productos con Stock', $resumen['productos_con_stock'] ?? 0],
                ['Productos Agotados', $resumen['productos_agotados'] ?? 0],
                ['Productos Bajo Stock', $resumen['productos_bajo_stock'] ?? 0],
                ['Valor Total del Inventario', $resumen['valor_inventario'] ?? 0]
            ];

            $col = 'A';
            foreach ($resumen_data as $index => $item) {
                $currentRow = $row + $index;
                $sheet->setCellValue($col . $currentRow, $item[0]);
                $sheet->setCellValue(chr(ord($col) + 1) . $currentRow, $item[1]);
                
                // Aplicar formato de moneda al valor del inventario
                if ($item[0] == 'Valor Total del Inventario') {
                    $sheet->getStyle(chr(ord($col) + 1) . $currentRow)->getNumberFormat()
                        ->setFormatCode('$#,##0.00');
                }
                
                $cellRange = $col . $currentRow . ':' . chr(ord($col) + 1) . $currentRow;
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
        
        while ($row_data = $result_clientes->fetch_assoc()) {
            $clientes[] = [
                $row_data['nombre'],
                $row_data['tipo'],
                $row_data['rfc'] ?? '',
                $row_data['telefono'] ?? '',
                $row_data['email'] ?? '',
                $row_data['direccion'] ?? '',
                $row_data['total_compras'],
                $row_data['total_gastado'],
                $row_data['ultima_compra'] ? date('d/m/Y', strtotime($row_data['ultima_compra'])) : 'Sin compras'
            ];
        }

        if (!empty($clientes)) {
            $headers = ['Nombre', 'Tipo', 'RFC', 'Teléfono', 'Email', 'Dirección', 'Total Compras', 'Total Gastado', 'Última Compra'];
            $row = agregarTabla($sheet, $row, 'REPORTE DE CLIENTES', $headers, $clientes, [7]);
        }
    }

    // Ajustar automáticamente el ancho de las columnas
    foreach (range('A', $sheet->getHighestDataColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    // La columna A se usa como margen visual del reporte de comisiones
    $sheet->getColumnDimension('A')->setAutoSize(false);
    $sheet->getColumnDimension('A')->setWidth(3);
    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

    // Crear el archivo Excel
    $filename = 'reporte_' . $tipo_reporte . '_' . date('Ymd_His') . '.xlsx';
    
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