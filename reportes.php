<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// Función segura para htmlspecialchars
function safe_html($string) {
    if ($string === null || $string === '') {
        return '';
    }
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Función para validar colores
function validar_color($color) {
    if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
        return $color;
    }
    return '#27ae60';
}

// Función para validar fechas
function validar_fecha($fecha) {
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// Función helper para formatear dinero
function formatMoney($amount) {
    if ($amount === null || $amount === '') {
        return '$0.00';
    }
    return '$' . number_format(floatval($amount), 2);
}

// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$conn_main = getDBConnection();

// Valores por defecto
$empresa_plan = "prueba";
$timbres_totales = 0;
$timbres_disponibles = 0;

if ($conn_main) {
    $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([$_SESSION['empresa_id']]);
    $result_empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);

    if ($result_empresa) {
        $empresa_plan = $result_empresa['plan'];
        $timbres_totales = $result_empresa['timbres_totales'] ?? 0;
        $timbres_disponibles = $result_empresa['timbres_disponibles'] ?? 0;
    }
    $stmt_empresa = null;
    $conn_main = null;
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Conectar a la base de datos de la empresa
try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Obtener información de la empresa y colores
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, iva, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch(PDO::FETCH_ASSOC);

    // OBTENER LOGO DE LA EMPRESA
    $logo_empresa = null;
    $logo_src_base64 = null;
    
    if (!empty($empresa_info['logo'])) {
        $empresa_logo = $empresa_info['logo'];
        $logo_path = '';
        $rutas_posibles = [
            $empresa_logo,
            '../' . $empresa_logo,
            '../../' . $empresa_logo,
            'admin/' . $empresa_logo,
            '../admin/' . $empresa_logo,
            'logos/' . $empresa_logo,
            'img/' . $empresa_logo,
            'images/' . $empresa_logo,
            'assets/' . $empresa_logo,
            'uploads/' . $empresa_logo,
            '../logos/' . $empresa_logo,
            '../img/' . $empresa_logo,
            '../images/' . $empresa_logo,
            '../assets/' . $empresa_logo,
            '../uploads/' . $empresa_logo
        ];

        foreach ($rutas_posibles as $ruta) {
            if (file_exists($ruta) && is_file($ruta)) {
                $logo_path = $ruta;
                break;
            }
        }

        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
    }

    // Obtener lista de sucursales disponibles para el usuario
    if ($_SESSION['usuario_rol'] === 'admin') {
        $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE activo = TRUE ORDER BY nombre";
        $result_sucursales = $conn->query($sql_sucursales);
    } else {
        $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE id = ? AND activo = TRUE";
        $stmt_sucursales = $conn->prepare($sql_sucursales);
        $stmt_sucursales->execute([$_SESSION['sucursal_id']]);
        $result_sucursales = $stmt_sucursales;
    }

    $sucursales = [];
    while ($row = $result_sucursales->fetch(PDO::FETCH_ASSOC)) {
        $sucursales[] = $row;
    }
    if (isset($stmt_sucursales)) $stmt_sucursales = null;

    // ============================================
    // PROCESAR PETICIONES AJAX
    // ============================================
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        
        // Obtener parámetros AJAX
        $fecha_inicio = isset($_GET['fecha_inicio']) && validar_fecha($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
        $fecha_fin = isset($_GET['fecha_fin']) && validar_fecha($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
        $tipo_reporte = isset($_GET['tipo_reporte']) ? $_GET['tipo_reporte'] : 'general';
        $sucursal_id_ajax = isset($_GET['sucursal_id']) && is_numeric($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : '';
        // Filtro por colaborador: aplica SOLO al bloque de comisiones.
        $colaborador_id = isset($_GET['colaborador_id']) && is_numeric($_GET['colaborador_id']) ? (int)$_GET['colaborador_id'] : 0;
        
        // Validar tipo_reporte contra lista blanca
        $tipos_permitidos = ['general', 'ventas', 'inventario', 'clientes'];
        if (!in_array($tipo_reporte, $tipos_permitidos)) {
            $tipo_reporte = 'general';
        }
        
        // Determinar qué sucursal usar
        $sucursal_filtro = '';
        $params_ajax = [];
        
        if ($_SESSION['usuario_rol'] !== 'admin') {
            $sucursal_filtro = " AND v.sucursal_id = ?";
            $params_ajax[] = $_SESSION['sucursal_id'];
        } elseif (!empty($sucursal_id_ajax)) {
            $sucursal_filtro = " AND v.sucursal_id = ?";
            $params_ajax[] = $sucursal_id_ajax;
        }
        
        $params_comunes = array_merge([$fecha_inicio, $fecha_fin], $params_ajax);
        
        $response = [];
        
        // Ejecutar consultas según el tipo de reporte
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
                AND v.estado = 'completada'
                $sucursal_filtro";
            
            $stmt_estadisticas = $conn->prepare($sql_estadisticas);
            if ($stmt_estadisticas) {
                if (!empty($params_comunes)) {
                    $stmt_estadisticas->execute($params_comunes);
                }
                $estadisticas = $stmt_estadisticas->fetch(PDO::FETCH_ASSOC);
                $stmt_estadisticas = null;
                
                $response['estadisticas'] = [
                    'ingresos_totales' => formatMoney($estadisticas['ingresos_totales'] ?? 0),
                    'total_ventas' => number_format($estadisticas['total_ventas'] ?? 0),
                    'clientes_activos' => number_format($estadisticas['clientes_activos'] ?? 0),
                    'productos_bajo_stock' => number_format($estadisticas['productos_bajo_stock'] ?? 0),
                    'iva_recaudado' => formatMoney($estadisticas['iva_recaudado'] ?? 0),
                    'productos_stock' => number_format($estadisticas['productos_stock'] ?? 0),
                    'productos_sin_stock' => number_format($estadisticas['productos_sin_stock'] ?? 0),
                    'promedio_venta' => formatMoney($estadisticas['promedio_venta'] ?? 0)
                ];
            }
            
            // Ventas por día (gráfica)
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
                $sucursal_filtro
                GROUP BY DATE(v.fecha)
                ORDER BY fecha";
            
            $stmt_ventas_dia = $conn->prepare($sql_ventas_dia);
            if ($stmt_ventas_dia) {
                if (!empty($params_comunes)) {
                    $stmt_ventas_dia->execute($params_comunes);
                }
                
                $labels_grafica = [];
                $data_grafica = [];
                $data_subtotal = [];
                $data_iva = [];
                
                while ($row = $stmt_ventas_dia->fetch(PDO::FETCH_ASSOC)) {
                    $labels_grafica[] = date('d M', strtotime($row['fecha']));
                    $data_grafica[] = floatval($row['total_dia']);
                    $data_subtotal[] = floatval($row['subtotal_dia']);
                    $data_iva[] = floatval($row['iva_dia']);
                }
                $stmt_ventas_dia = null;
                
                $response['grafica'] = [
                    'labels' => $labels_grafica,
                    'data_total' => $data_grafica,
                    'data_subtotal' => $data_subtotal,
                    'data_iva' => $data_iva
                ];
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
                $sucursal_filtro
                GROUP BY v.metodo_pago
                ORDER BY total_metodo DESC";
            
            $stmt_metodos = $conn->prepare($sql_metodos_pago);
            if ($stmt_metodos) {
                if (!empty($params_comunes)) {
                    $stmt_metodos->execute($params_comunes);
                }
                
                $metodos_pago = [];
                $total_metodos = 0;
                
                while ($row = $stmt_metodos->fetch(PDO::FETCH_ASSOC)) {
                    $metodos_pago[] = [
                        'metodo' => ucfirst($row['metodo_pago']),
                        'cantidad' => $row['cantidad_ventas'],
                        'total' => floatval($row['total_metodo']),
                        'total_formateado' => formatMoney($row['total_metodo'])
                    ];
                    $total_metodos += floatval($row['total_metodo']);
                }
                $stmt_metodos = null;
                
                foreach ($metodos_pago as &$metodo) {
                    $metodo['porcentaje'] = $total_metodos > 0 ? round(($metodo['total'] / $total_metodos) * 100, 1) : 0;
                    $metodo['icono'] = $metodo['metodo'] == 'Efectivo' ? 'money-bill' : ($metodo['metodo'] == 'Tarjeta' ? 'credit-card' : 'exchange-alt');
                }
                
                $response['metodos_pago'] = $metodos_pago;
            }
            
            // Ventas por hora
            $sql_ventas_hora = "
                SELECT 
                    HOUR(v.fecha) as hora,
                    COUNT(*) as cantidad_ventas,
                    COALESCE(SUM(v.total), 0) as total_hora
                FROM ventas v
                WHERE DATE(v.fecha) BETWEEN ? AND ?
                AND v.estado = 'completada'
                $sucursal_filtro
                GROUP BY HOUR(v.fecha)
                ORDER BY hora";
            
            $stmt_horas = $conn->prepare($sql_ventas_hora);
            if ($stmt_horas) {
                if (!empty($params_comunes)) {
                    $stmt_horas->execute($params_comunes);
                }
                
                $labels_horas = [];
                $data_horas = [];
                
                while ($row = $stmt_horas->fetch(PDO::FETCH_ASSOC)) {
                    $labels_horas[] = $row['hora'] . ':00';
                    $data_horas[] = floatval($row['total_hora']);
                }
                $stmt_horas = null;
                
                $response['grafica_horas'] = [
                    'labels' => $labels_horas,
                    'data' => $data_horas
                ];
            }
            
            // Ventas por sucursal (solo para admin)
            if ($_SESSION['usuario_rol'] === 'admin') {
                $sql_sucursales_ventas = "
                    SELECT 
                        s.nombre as sucursal,
                        COUNT(*) as total_ventas,
                        COALESCE(SUM(v.total), 0) as ingresos_totales
                    FROM ventas v
                    INNER JOIN sucursales s ON v.sucursal_id = s.id
                    WHERE DATE(v.fecha) BETWEEN ? AND ?
                    AND v.estado = 'completada'
                    GROUP BY s.id, s.nombre
                    ORDER BY ingresos_totales DESC";
                
                $stmt_suc = $conn->prepare($sql_sucursales_ventas);
                if ($stmt_suc) {
                    $stmt_suc->execute([$fecha_inicio, $fecha_fin]);
                    
                    $sucursales_ventas = [];
                    $total_ingresos = 0;
                    
                    while ($row = $stmt_suc->fetch(PDO::FETCH_ASSOC)) {
                        $sucursales_ventas[] = [
                            'nombre' => $row['sucursal'],
                            'total' => floatval($row['ingresos_totales']),
                            'total_formateado' => formatMoney($row['ingresos_totales'])
                        ];
                        $total_ingresos += floatval($row['ingresos_totales']);
                    }
                    $stmt_suc = null;
                    
                    foreach ($sucursales_ventas as &$suc) {
                        $suc['porcentaje'] = $total_ingresos > 0 ? round(($suc['total'] / $total_ingresos) * 100, 1) : 0;
                    }
                    
                    $response['sucursales'] = $sucursales_ventas;
                }
            }
        }
        
        if ($tipo_reporte === 'general' || $tipo_reporte === 'ventas' || $tipo_reporte === 'inventario') {
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
                $sucursal_filtro
                GROUP BY p.id, p.nombre, p.codigo, c.nombre
                ORDER BY total_vendido DESC
                LIMIT 10";
            
            $stmt_productos = $conn->prepare($sql_productos_vendidos);
            if ($stmt_productos) {
                if (!empty($params_comunes)) {
                    $stmt_productos->execute($params_comunes);
                }
                
                $productos = [];
                $index = 1;
                while ($row = $stmt_productos->fetch(PDO::FETCH_ASSOC)) {
                    $productos[] = [
                        'rank' => $index++,
                        'nombre' => safe_html($row['nombre']),
                        'codigo' => safe_html($row['codigo']),
                        'categoria' => safe_html($row['categoria'] ?? ''),
                        'total_vendido' => number_format($row['total_vendido']),
                        'ingresos_totales' => formatMoney($row['ingresos_totales'])
                    ];
                }
                $stmt_productos = null;
                
                $response['productos_vendidos'] = $productos;
            }
        }
        
        if ($tipo_reporte === 'general' || $tipo_reporte === 'ventas') {
            // Vendedores top
            $sql_vendedores = "
                SELECT 
                    u.nombre as vendedor,
                    s.nombre as sucursal,
                    COUNT(*) as ventas_realizadas,
                    COALESCE(SUM(v.total), 0) as total_vendido
                FROM ventas v
                INNER JOIN usuarios u ON v.usuario_id = u.id
                INNER JOIN sucursales s ON v.sucursal_id = s.id
                WHERE DATE(v.fecha) BETWEEN ? AND ?
                AND v.estado = 'completada'
                $sucursal_filtro
                GROUP BY u.id, u.nombre, s.nombre
                ORDER BY total_vendido DESC
                LIMIT 10";
            
            $stmt_vendedores = $conn->prepare($sql_vendedores);
            if ($stmt_vendedores) {
                if (!empty($params_comunes)) {
                    $stmt_vendedores->execute($params_comunes);
                }
                
                $vendedores = [];
                $index = 1;
                while ($row = $stmt_vendedores->fetch(PDO::FETCH_ASSOC)) {
                    $vendedores[] = [
                        'rank' => $index++,
                        'nombre' => safe_html($row['vendedor']),
                        'sucursal' => safe_html($row['sucursal']),
                        'ventas' => $row['ventas_realizadas'],
                        'total' => formatMoney($row['total_vendido'])
                    ];
                }
                $stmt_vendedores = null;
                
                $response['vendedores_top'] = $vendedores;
            }

            // El filtro por colaborador se arma aparte porque solo aplica a
            // comisiones, no al resto del reporte.
            $colaborador_filtro  = '';
            $params_comisiones   = $params_comunes;
            if (!empty($colaborador_id)) {
                $colaborador_filtro = " AND vc.colaborador_id = ?";
                $params_comisiones[] = $colaborador_id;
            }

            // =========================================================
            // COMISIONES · tabla cruzada por area (mismo formato que el
            // Excel): una fila por venta, una columna por colaborador.
            //   TOTAL A COMISIONAR = DEPOSITADO - COSTO SERVICIO - IVA
            // =========================================================
            $sql_comisiones = "
                SELECT
                    vc.area_nombre,
                    vc.colaborador_nombre,
                    vc.monto_base,
                    vc.monto_comision,
                    vc.venta_detalle_id,
                    vc.precio_unitario,
                    vc.descuento_linea,
                    vc.costo_unitario,
                    vc.cantidad,
                    vc.gasto_operacion,
                    v.descuento AS venta_descuento,
                    v.id AS venta_id,
                    v.codigo_venta,
                    v.fecha,
                    v.metodo_pago,
                    v.total AS venta_total,
                    v.iva   AS venta_iva,
                    COALESCE(cl.nombre, 'Cliente General') AS cliente_nombre
                FROM venta_comisiones vc
                INNER JOIN ventas v ON vc.venta_id = v.id
                LEFT JOIN clientes cl ON v.cliente_id = cl.id
                WHERE DATE(v.fecha) BETWEEN ? AND ?
                AND v.estado = 'completada'
                AND vc.cancelada = 0
                $sucursal_filtro
                $colaborador_filtro
                ORDER BY vc.area_nombre, v.fecha, v.id";

            $stmt_comisiones = $conn->prepare($sql_comisiones);
            if ($stmt_comisiones) {
                $stmt_comisiones->execute($params_comisiones);

                $areas_com = [];
                $ventas_vist = [];
                while ($r = $stmt_comisiones->fetch(PDO::FETCH_ASSOC)) {
                    $area  = $r['area_nombre'] !== '' ? $r['area_nombre'] : 'Sin área';
                    $vid   = (int)$r['venta_id'];
                    $colab = $r['colaborador_nombre'];

                    if (!isset($areas_com[$area])) {
                        $areas_com[$area] = ['ventas' => [], 'colaboradores' => []];
                    }
                    if (!isset($areas_com[$area]['ventas'][$vid])) {
                        $areas_com[$area]['ventas'][$vid] = [
                            'folio'      => $r['codigo_venta'],
                            'concepto'   => $r['cliente_nombre'],
                            'fecha'      => $r['fecha'],
                            'banco'      => ucfirst($r['metodo_pago']),
                            'depositado' => (float)$r['venta_total'],
                            'descuento'  => (float)$r['venta_descuento'],
                            'iva'        => (float)$r['venta_iva'],
                            'bases'      => [],
                            'subtot'     => [],
                            'costos'     => [],
                            'gastos'     => [],
                            'descs'      => [],
                            'colab'      => []
                        ];
                    }
                    // Se indexan por venta_detalle_id porque se repiten en
                    // cada fila del mismo producto (una por colaborador).
                    $did = (int)$r['venta_detalle_id'];
                    $areas_com[$area]['ventas'][$vid]['bases'][$did]  = (float)$r['monto_base'];
                    $areas_com[$area]['ventas'][$vid]['subtot'][$did] = (float)$r['precio_unitario'] * (float)$r['cantidad'];
                    $areas_com[$area]['ventas'][$vid]['costos'][$did] = (float)$r['costo_unitario']  * (float)$r['cantidad'];
                    $areas_com[$area]['ventas'][$vid]['gastos'][$did] = (float)$r['gasto_operacion'];
                    $areas_com[$area]['ventas'][$vid]['descs'][$did]  = (float)$r['descuento_linea'];
                    $areas_com[$area]['ventas'][$vid]['colab'][$colab] =
                        ($areas_com[$area]['ventas'][$vid]['colab'][$colab] ?? 0) + (float)$r['monto_comision'];
                    $areas_com[$area]['colaboradores'][$colab] = true;
                    $ventas_vist[$vid] = true;
                }
                $stmt_comisiones = null;

                // Gastos de operación manuales por venta ("costo de servicio")
                $gastos_venta = [];
                if (!empty($ventas_vist)) {
                    $ids = implode(',', array_map('intval', array_keys($ventas_vist)));
                    $res_g = $conn->query("
                        SELECT venta_id, SUM(monto) AS gasto
                        FROM gastos
                        WHERE venta_id IN ($ids)
                          AND tipo = 'manual'
                          AND categoria <> 'Costo de venta'
                        GROUP BY venta_id");
                    if ($res_g) {
                        while ($g = $res_g->fetch(PDO::FETCH_ASSOC)) {
                            $gastos_venta[(int)$g['venta_id']] = (float)$g['gasto'];
                        }
                    }
                }

                // Armar la respuesta ya formateada para el front
                $comisiones_areas = [];
                $gran = ['venta' => 0, 'desc' => 0, 'dep' => 0, 'costo' => 0,
                         'gasto' => 0, 'iva' => 0, 'base' => 0];
                $gran_colab   = [];
                $colab_areas  = [];   // colaborador => [area => true]
                $colab_conteo = [];   // colaborador => cuantas comisiones tuvo
                $ventas_gt = [];

                foreach ($areas_com as $area => $datos) {
                    $colabs = array_keys($datos['colaboradores']);
                    sort($colabs);

                    $filas = [];
                    $tot = ['venta' => 0, 'desc' => 0, 'dep' => 0, 'costo' => 0,
                            'gasto' => 0, 'iva' => 0, 'base' => 0];
                    $tot_colab = array_fill_keys($colabs, 0.0);

                    foreach ($datos['ventas'] as $vid => $vta) {
                        // Del snapshot de la comisión, no de la tabla gastos:
                        // así la resta cuadra exacto con monto_base.
                        $gasto    = array_sum($vta['gastos']);
                        $base     = array_sum($vta['bases']);
                        $subtotal = array_sum($vta['subtot']);
                        $costo    = array_sum($vta['costos']);
                        $descuento = array_sum($vta['descs']);

                        $montos = [];
                        foreach ($colabs as $c) {
                            $m = $vta['colab'][$c] ?? 0;
                            $montos[] = $m > 0 ? formatMoney($m) : '';
                            $tot_colab[$c] += $m;
                            if ($m > 0) {
                                $colab_areas[$c][$area] = true;
                                $colab_conteo[$c] = ($colab_conteo[$c] ?? 0) + 1;
                            }
                        }

                        $filas[] = [
                            'folio'      => safe_html($vta['folio']),
                            'concepto'   => safe_html($vta['concepto']),
                            'fecha'      => date('d/m/Y', strtotime($vta['fecha'])),
                            'banco'      => safe_html($vta['banco']),
                            'venta'      => formatMoney($subtotal),
                            'descuento'  => $descuento > 0 ? formatMoney($descuento) : '',
                            'depositado' => formatMoney($vta['depositado']),
                            'costo'      => $costo > 0 ? formatMoney($costo) : '',
                            'gasto'      => $gasto > 0 ? formatMoney($gasto) : '',
                            'iva'        => $vta['iva'] > 0 ? formatMoney($vta['iva']) : '',
                            'base'       => formatMoney($base),
                            'montos'     => $montos
                        ];

                        $tot['venta'] += $subtotal;
                        $tot['desc']  += $descuento;
                        $tot['dep']   += $vta['depositado'];
                        $tot['costo'] += $costo;
                        $tot['gasto'] += $gasto;
                        $tot['iva']   += $vta['iva'];
                        $tot['base']  += $base;

                        if (!isset($ventas_gt[$vid])) {
                            $gran['dep']  += $vta['depositado'];
                            $gran['desc'] += $descuento;
                            $gran['iva']  += $vta['iva'];
                            $ventas_gt[$vid] = true;
                        }
                        $gran['venta'] += $subtotal;
                        $gran['costo'] += $costo;
                        $gran['gasto'] += $gasto;
                        $gran['base']  += $base;
                        foreach ($colabs as $c) {
                            $gran_colab[$c] = ($gran_colab[$c] ?? 0) + ($vta['colab'][$c] ?? 0);
                        }
                    }

                    $comisiones_areas[] = [
                        'area'          => safe_html($area),
                        'colaboradores' => array_map('safe_html', $colabs),
                        'filas'         => $filas,
                        'totales'       => [
                            'venta'      => formatMoney($tot['venta']),
                            'descuento'  => formatMoney($tot['desc']),
                            'depositado' => formatMoney($tot['dep']),
                            'costo'      => formatMoney($tot['costo']),
                            'gasto'      => formatMoney($tot['gasto']),
                            'iva'        => formatMoney($tot['iva']),
                            'base'       => formatMoney($tot['base']),
                            'montos'     => array_map(function ($c) use ($tot_colab) {
                                return formatMoney($tot_colab[$c]);
                            }, $colabs)
                        ]
                    ];
                }

                $por_pagar = array_sum($gran_colab);
                arsort($gran_colab);   // de mayor a menor para el desglose de pago
                $colabs_gt = array_keys($gran_colab);

                // Si se filtró por una persona, se manda su nombre para
                // encabezar el reporte individual.
                $colaborador_nombre_filtro = '';
                if (!empty($colaborador_id)) {
                    $stmt_cn = $conn->prepare("SELECT nombre FROM comision_colaboradores WHERE id = ?");
                    $stmt_cn->execute([$colaborador_id]);
                    $colaborador_nombre_filtro = (string)$stmt_cn->fetchColumn();
                }

                $response['comisiones_colaborador'] = safe_html($colaborador_nombre_filtro);
                $response['comisiones_areas'] = $comisiones_areas;
                $response['comisiones_resumen'] = [
                    'periodo'      => strtoupper(date('m/Y', strtotime($fecha_inicio))),
                    'venta'        => formatMoney($gran['venta']),
                    'descuento'    => formatMoney($gran['desc']),
                    'depositado'   => formatMoney($gran['dep']),
                    'costo'        => formatMoney($gran['costo']),
                    'gasto'        => formatMoney($gran['gasto']),
                    'iva'          => formatMoney($gran['iva']),
                    'base'         => formatMoney($gran['base']),
                    'por_pagar'    => formatMoney($por_pagar),
                    'colaboradores'=> array_map(function ($c) use ($gran_colab, $colab_areas, $colab_conteo) {
                        return [
                            'nombre' => safe_html($c),
                            'areas'  => safe_html(implode(', ', array_keys($colab_areas[$c] ?? []))),
                            'coms'   => (int)($colab_conteo[$c] ?? 0),
                            'monto'  => formatMoney($gran_colab[$c])
                        ];
                    }, $colabs_gt)
                ];
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
                    MAX(v.fecha) as ultima_compra
                FROM ventas v
                INNER JOIN clientes c ON v.cliente_id = c.id
                WHERE DATE(v.fecha) BETWEEN ? AND ?
                AND v.estado = 'completada'
                $sucursal_filtro
                GROUP BY c.id, c.nombre, c.tipo, c.telefono
                ORDER BY total_gastado DESC
                LIMIT 10";
            
            $stmt_clientes = $conn->prepare($sql_clientes);
            if ($stmt_clientes) {
                if (!empty($params_comunes)) {
                    $stmt_clientes->execute($params_comunes);
                }
                
                $clientes = [];
                while ($row = $stmt_clientes->fetch(PDO::FETCH_ASSOC)) {
                    $clientes[] = [
                        'nombre' => safe_html($row['cliente']),
                        'tipo' => safe_html($row['tipo']),
                        'telefono' => safe_html($row['telefono'] ?? ''),
                        'compras' => $row['compras_realizadas'],
                        'total' => formatMoney($row['total_gastado']),
                        'ultima_compra' => date('d/m/Y', strtotime($row['ultima_compra']))
                    ];
                }
                $stmt_clientes = null;
                
                $response['clientes_frecuentes'] = $clientes;
            }
        }
        
        if ($tipo_reporte === 'general' || $tipo_reporte === 'inventario') {
            // Productos con stock bajo
            $sql_stock_bajo = "
                SELECT 
                    p.nombre,
                    p.codigo,
                    c.nombre as categoria,
                    p.stock,
                    p.stock_minimo
                FROM productos p
                LEFT JOIN categorias c ON p.categoria_id = c.id
                WHERE p.activo = TRUE 
                AND p.stock <= p.stock_minimo
                ORDER BY p.stock ASC
                LIMIT 15";
            
            $result_stock_bajo = $conn->query($sql_stock_bajo);
            $productos_stock = [];
            
            while ($row = $result_stock_bajo->fetch(PDO::FETCH_ASSOC)) {
                $productos_stock[] = [
                    'nombre' => safe_html($row['nombre']),
                    'codigo' => safe_html($row['codigo']),
                    'categoria' => safe_html($row['categoria'] ?? ''),
                    'stock' => $row['stock'],
                    'stock_minimo' => $row['stock_minimo']
                ];
            }
            
            $response['productos_stock_bajo'] = $productos_stock;
            $response['productos_stock_bajo_count'] = count($productos_stock);
        }
        
        // Enviar respuesta JSON y terminar
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // ============================================
    // CARGA NORMAL DE LA PÁGINA (NO AJAX)
    // ============================================
    
    // Procesar actualización de colores
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_apariencia'])) {
        $color_primario = validar_color($_POST['color_primario']);
        $color_secundario = validar_color($_POST['color_secundario']);

        $sql_update = "UPDATE sistema_config SET color_primario = ?, color_secundario = ?";
        $stmt_update = $conn->prepare($sql_update);
        
        if ($stmt_update) {
            $stmt_update->execute([$color_primario, $color_secundario]);
            
            if ($stmt_update->rowCount() >= 0) {
                $mensaje = "Colores actualizados correctamente";
                $tipo_mensaje = "success";
                $empresa_info['color_primario'] = $color_primario;
                $empresa_info['color_secundario'] = $color_secundario;
                
                $_SESSION['color_primario'] = $color_primario;
                $_SESSION['color_secundario'] = $color_secundario;
                
                header("Location:Reportes?" . $_SERVER['QUERY_STRING']);
                exit();
            } else {
                $mensaje = "Error al actualizar los colores";
                $tipo_mensaje = "danger";
            }
            $stmt_update = null;
        }
    }

    // Parámetros de fecha - Validar y sanitizar
    $fecha_inicio = isset($_GET['fecha_inicio']) && validar_fecha($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
    $fecha_fin = isset($_GET['fecha_fin']) && validar_fecha($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
    $tipo_reporte = isset($_GET['tipo_reporte']) ? $_GET['tipo_reporte'] : 'general';
    $sucursal_id = isset($_GET['sucursal_id']) && is_numeric($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : '';
    $colaborador_id = isset($_GET['colaborador_id']) && is_numeric($_GET['colaborador_id']) ? (int)$_GET['colaborador_id'] : 0;

    // Colaboradores para el filtro de comisiones
    $colaboradores_filtro = [];
    try {
        $colaboradores_filtro = $conn->query("
            SELECT id, nombre FROM comision_colaboradores WHERE activo = 1 ORDER BY nombre
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $colaboradores_filtro = [];
    }

    // Validar tipo_reporte contra lista blanca
    $tipos_permitidos = ['general', 'ventas', 'inventario', 'clientes'];
    if (!in_array($tipo_reporte, $tipos_permitidos)) {
        $tipo_reporte = 'general';
    }

    // Obtener nombre de sucursal actual para admin
    $sucursal_actual_nombre = '';
    if ($_SESSION['usuario_rol'] === 'admin' && !empty($sucursal_id) && is_numeric($sucursal_id)) {
        foreach ($sucursales as $suc) {
            if ($suc['id'] == $sucursal_id) {
                $sucursal_actual_nombre = $suc['nombre'];
                break;
            }
        }
    }
    
    // Variables iniciales para la vista
    $estadisticas = [];
    $labels_grafica = [];
    $data_grafica = [];
    $data_subtotal = [];
    $data_iva = [];
    $metodos_pago = [];
    $labels_horas = [];
    $data_horas = [];
    $ventas_por_sucursal = [];
    $productos_vendidos = [];
    $vendedores_top = [];
    $clientes_frecuentes = [];
    $productos_stock_bajo = [];

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - <?php echo safe_html($_SESSION['empresa_nombre'] ?? ''); ?></title>
        <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/crm-theme.css">
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin fa-3x mb-2"></i>
            <p class="mb-0">Cargando reportes...</p>
        </div>
    </div>

    <!-- Navbar -->
    <?php include 'includes/navbar.php'; ?>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-chart-bar me-2"></i>
                        <span id="reporteTitulo">
                            <?php 
                            $titulo_reporte = [
                                'general' => 'Reporte General',
                                'ventas' => 'Reporte de Ventas',
                                'inventario' => 'Reporte de Inventario',
                                'clientes' => 'Reporte de Clientes'
                            ];
                            echo $titulo_reporte[$tipo_reporte] ?? 'Reportes Avanzados';
                            ?>
                        </span>
                        <span id="sucursalBadge" class="text-muted fs-6">
                            <?php if ($_SESSION['usuario_rol'] === 'admin' && !empty($sucursal_id)): ?>
                                - <?php echo safe_html($sucursal_actual_nombre); ?>
                            <?php elseif ($_SESSION['usuario_rol'] === 'admin'): ?>
                                - Todas las sucursales
                            <?php endif; ?>
                        </span>
                    </h1>
                    <div>
                        <button class="btn btn-outline-primary me-2" onclick="exportarPDF()">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </button>
                        <button class="btn btn-outline-success" onclick="exportarExcel()">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card filtros-card mb-4">
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                                    value="<?php echo safe_html($fecha_inicio); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin"
                                    value="<?php echo safe_html($fecha_fin); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="sucursal_id" class="form-label">Sucursal</label>
                                <select class="form-select" id="sucursal_id" name="sucursal_id" <?php echo ($_SESSION['usuario_rol'] !== 'admin') ? 'disabled' : ''; ?>>
                                    <?php if ($_SESSION['usuario_rol'] !== 'admin'): ?>
                                        <option value="<?php echo safe_html($_SESSION['sucursal_id']); ?>" selected>
                                            <?php 
                                            $sucursal_nombre = '';
                                            foreach ($sucursales as $suc) {
                                                if ($suc['id'] == $_SESSION['sucursal_id']) {
                                                    $sucursal_nombre = $suc['nombre'];
                                                    break;
                                                }
                                            }
                                            echo safe_html($sucursal_nombre);
                                            ?>
                                        </option>
                                    <?php else: ?>
                                        <option value="">Todas las sucursales</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo safe_html($sucursal['id']); ?>"
                                                <?php echo $sucursal_id == $sucursal['id'] ? 'selected' : ''; ?>>
                                                <?php echo safe_html($sucursal['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="tipo_reporte" class="form-label">Tipo de Reporte</label>
                                <select class="form-select" id="tipo_reporte" name="tipo_reporte">
                                    <option value="general" <?php echo $tipo_reporte === 'general' ? 'selected' : ''; ?>>General</option>
                                    <option value="ventas" <?php echo $tipo_reporte === 'ventas' ? 'selected' : ''; ?>>Ventas</option>
                                    <option value="inventario" <?php echo $tipo_reporte === 'inventario' ? 'selected' : ''; ?>>Inventario</option>
                                    <option value="clientes" <?php echo $tipo_reporte === 'clientes' ? 'selected' : ''; ?>>Clientes</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="colaborador_id" class="form-label">
                                    Comisiones de
                                    <i class="fas fa-info-circle text-muted" title="Solo filtra el bloque de comisiones"></i>
                                </label>
                                <select class="form-select" id="colaborador_id" name="colaborador_id">
                                    <option value="">Todos los colaboradores</option>
                                    <?php foreach ($colaboradores_filtro as $c): ?>
                                        <option value="<?php echo safe_html($c['id']); ?>"
                                            <?php echo $colaborador_id == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo safe_html($c['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8 d-flex align-items-end gap-2">
                                <button type="button" class="btn btn-light flex-grow-1" id="btnAplicarFiltros">
                                    <i class="fas fa-filter me-2"></i>Aplicar Filtros y Generar Reporte
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btnImprimirComisiones"
                                        title="Imprime solo el bloque de comisiones">
                                    <i class="fas fa-print me-2"></i>Imprimir comisiones
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CONTENEDOR DE REPORTES (se actualiza vía AJAX) -->
                <div id="reportesContainer">
                    <!-- El contenido se cargará dinámicamente -->
                </div>

                <!-- Plantilla HTML para los reportes (se clona y llena con datos) -->
                <template id="templateGeneralVentas">
                    <div>
                        <!-- KPI Cards -->
                        <div class="row mb-4" id="kpiContainer"></div>
                        
                        <!-- Gráficas principales -->
                        <div class="row mb-4">
                            <div class="col-lg-8">
                                <div class="card">
                                    <div class="card-header bg-gradient-primary text-white">
                                        <h5 class="card-title mb-0"><i class="fas fa-chart-line me-2"></i>Evolución de Ventas</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="chart-container">
                                            <canvas id="graficaVentas"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header bg-gradient-primary text-white">
                                        <h5 class="card-title mb-0"><i class="fas fa-credit-card me-2"></i>Métodos de Pago</h5>
                                    </div>
                                    <div class="card-body" id="metodosPagoContainer"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-lg-6">
                                <div class="card">
                                    <div class="card-header bg-gradient-primary text-white">
                                        <h5 class="card-title mb-0"><i class="fas fa-chart-bar me-2"></i>Ventas por Hora</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="chart-container-sm">
                                            <canvas id="graficaHoras"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6" id="sucursalesContainer"></div>
                        </div>
                        
                        <!-- Productos y Vendedores -->
                        <div class="row mb-4">
                            <div class="col-lg-6" id="productosContainer"></div>
                            <div class="col-lg-6" id="vendedoresContainer"></div>
                        </div>
                        
                        <!-- Comisiones por colaborador -->
                        <div class="row mb-4">
                            <div class="col-12" id="comisionesContainer"></div>
                        </div>
                        
                        <!-- Clientes y Stock -->
                        <div class="row">
                            <div class="col-lg-6" id="clientesContainer"></div>
                            <div class="col-lg-6" id="stockBajoContainer"></div>
                        </div>
                    </div>
                </template>

                <template id="templateInventario">
                    <div>
                        <div class="row mb-4" id="inventarioStatsContainer"></div>
                        <div class="row">
                            <div class="col-12" id="inventarioTablaContainer"></div>
                        </div>
                    </div>
                </template>

                <template id="templateClientes">
                    <div>
                        <div class="row">
                            <div class="col-12" id="clientesTablaContainer"></div>
                        </div>
                    </div>
                </template>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        // Variables globales para las gráficas
        let chartVentas = null;
        let chartHoras = null;
        let chartSucursales = null;

        // Control del sidebar en móvil
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function toggleSidebar() {
            sidebar.classList.toggle('show');
            sidebarBackdrop.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
        }

        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', toggleSidebar);

        // Mostrar/ocultar loading
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Función principal para cargar reportes vía AJAX
        function cargarReportes() {
            showLoading();
            
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            const sucursalId = document.getElementById('sucursal_id').value;
            const tipoReporte = document.getElementById('tipo_reporte').value;
            const colaboradorId = document.getElementById('colaborador_id')?.value || '';
            
            // Actualizar título
            const titulos = {
                'general': 'Reporte General',
                'ventas': 'Reporte de Ventas',
                'inventario': 'Reporte de Inventario',
                'clientes': 'Reporte de Clientes'
            };
            document.getElementById('reporteTitulo').innerText = titulos[tipoReporte] || 'Reportes Avanzados';
            
            // Actualizar badge de sucursal
            const sucursalSelect = document.getElementById('sucursal_id');
            const selectedOption = sucursalSelect.options[sucursalSelect.selectedIndex];
            let sucursalTexto = '';
            if (sucursalId && sucursalId !== '') {
                sucursalTexto = '- ' + selectedOption.text;
            } else if (sucursalId === '') {
                sucursalTexto = '- Todas las sucursales';
            }
            document.getElementById('sucursalBadge').innerText = sucursalTexto;
            
            // Realizar petición AJAX
            $.ajax({
                url: window.location.pathname,
                method: 'GET',
                data: {
                    fecha_inicio: fechaInicio,
                    fecha_fin: fechaFin,
                    sucursal_id: sucursalId,
                    tipo_reporte: tipoReporte,
                    colaborador_id: colaboradorId
                },
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                dataType: 'json',
                success: function(response) {
                    if (tipoReporte === 'general' || tipoReporte === 'ventas') {
                        renderizarReporteGeneralVentas(response, tipoReporte);
                    } else if (tipoReporte === 'inventario') {
                        renderizarReporteInventario(response);
                    } else if (tipoReporte === 'clientes') {
                        renderizarReporteClientes(response);
                    }
                    hideLoading();
                },
                error: function(xhr, status, error) {
                    console.error('Error al cargar reportes:', error);
                    document.getElementById('reportesContainer').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error al cargar los reportes. Por favor, intente nuevamente.
                        </div>
                    `;
                    hideLoading();
                }
            });
        }

        // Renderizar reporte General/Ventas
        function renderizarReporteGeneralVentas(data, tipoReporte) {
            const template = document.getElementById('templateGeneralVentas');
            const clone = template.content.cloneNode(true);
            
            // Renderizar KPIs
            if (data.estadisticas) {
                const kpiContainer = clone.querySelector('#kpiContainer');
                kpiContainer.innerHTML = `
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">INGRESOS TOTALES</div>
                                        <div class="metric-value text-primary">${data.estadisticas.ingresos_totales}</div>
                                        <small class="text-muted">Período seleccionado</small>
                                    </div>
                                    <div><i class="fas fa-money-bill-wave fa-2x text-primary opacity-25"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">TOTAL VENTAS</div>
                                        <div class="metric-value text-info">${data.estadisticas.total_ventas}</div>
                                        <small class="text-muted">Transacciones completadas</small>
                                    </div>
                                    <div><i class="fas fa-shopping-cart fa-2x text-info opacity-25"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">CLIENTES ACTIVOS</div>
                                        <div class="metric-value text-warning">${data.estadisticas.clientes_activos}</div>
                                        <small class="text-muted">Clientes únicos</small>
                                    </div>
                                    <div><i class="fas fa-user-friends fa-2x text-warning opacity-25"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">PRODUCTOS BAJO STOCK</div>
                                        <div class="metric-value text-danger">${data.estadisticas.productos_bajo_stock}</div>
                                        <small class="text-muted">Necesitan atención</small>
                                    </div>
                                    <div><i class="fas fa-box-open fa-2x text-danger opacity-25"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Renderizar métodos de pago
            if (data.metodos_pago && data.metodos_pago.length > 0) {
                const metodosContainer = clone.querySelector('#metodosPagoContainer');
                let metodosHtml = '<div class="list-group list-group-flush">';
                data.metodos_pago.forEach(metodo => {
                    let claseColor = '';
                    if (metodo.metodo === 'Efectivo') claseColor = 'bg-efectivo';
                    else if (metodo.metodo === 'Tarjeta') claseColor = 'bg-tarjeta';
                    else claseColor = 'bg-transferencia';
                    
                    metodosHtml += `
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-capitalize">
                                    <i class="fas fa-${metodo.icono} me-2"></i>${metodo.metodo}
                                </span>
                                <span class="text-success fw-bold">${metodo.total_formateado}</span>
                            </div>
                            <div class="progress"><div class="progress-bar ${claseColor}" style="width: ${metodo.porcentaje}%"></div></div>
                            <small class="text-muted">${metodo.cantidad} ventas (${metodo.porcentaje}%)</small>
                        </div>
                    `;
                });
                metodosHtml += '</div>';
                metodosContainer.innerHTML = metodosHtml;
            } else {
                clone.querySelector('#metodosPagoContainer').innerHTML = '<p class="text-muted text-center">No hay datos de métodos de pago</p>';
            }
            
            // Renderizar sucursales (solo si hay datos)
            if (data.sucursales && data.sucursales.length > 0) {
                const sucursalesContainer = clone.querySelector('#sucursalesContainer');
                sucursalesContainer.innerHTML = `
                    <div class="card">
                        <div class="card-header bg-gradient-primary text-white">
                            <h5 class="card-title mb-0"><i class="fas fa-store me-2"></i>Desempeño por Sucursal</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container-sm"><canvas id="graficaSucursales"></canvas></div>
                        </div>
                    </div>
                `;
            } else if (clone.querySelector('#sucursalesContainer')) {
                clone.querySelector('#sucursalesContainer').innerHTML = '';
            }
            
            // Renderizar productos más vendidos
            if (data.productos_vendidos && data.productos_vendidos.length > 0) {
                const productosContainer = clone.querySelector('#productosContainer');
                let productosHtml = `
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-star me-2"></i>Top 10 Productos Más Vendidos</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light"><tr><th>Producto</th><th class="text-center">Vendidos</th><th class="text-end">Total</th></tr></thead>
                                    <tbody>
                `;
                data.productos_vendidos.forEach(prod => {
                    productosHtml += `
                        <tr>
                            <td><div class="d-flex align-items-center"><span class="badge bg-primary me-2">#${prod.rank}</span><div><strong>${prod.nombre}</strong>${prod.categoria ? '<br><small class="text-muted">'+prod.categoria+'</small>' : ''}</div></div></td>
                            <td class="text-center"><span class="badge badge-ventas">${prod.total_vendido}</span></td>
                            <td class="text-end text-success fw-bold">${prod.ingresos_totales}</td>
                        </tr>
                    `;
                });
                productosHtml += '</tbody></table></div></div></div>';
                productosContainer.innerHTML = productosHtml;
            } else {
                clone.querySelector('#productosContainer').innerHTML = '<div class="card"><div class="card-body"><p class="text-muted text-center">No hay datos de productos vendidos</p></div></div>';
            }
            
            // Renderizar vendedores top
            if (data.vendedores_top && data.vendedores_top.length > 0) {
                const vendedoresContainer = clone.querySelector('#vendedoresContainer');
                let vendedoresHtml = `
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-trophy me-2"></i>Top 10 Vendedores</h5>
                        </div>
                        <div class="card-body"><div class="list-group list-group-flush">
                `;
                data.vendedores_top.forEach(vendedor => {
                    vendedoresHtml += `
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <div><div class="d-flex align-items-center"><span class="badge bg-primary me-2">#${vendedor.rank}</span><div><span class="fw-bold">${vendedor.nombre}</span><br><small class="text-muted">${vendedor.sucursal}</small></div></div></div>
                                <div class="text-end"><div class="text-success fw-bold">${vendedor.total}</div><small class="text-muted">${vendedor.ventas} ventas</small></div>
                            </div>
                        </div>
                    `;
                });
                vendedoresHtml += '</div></div></div>';
                vendedoresContainer.innerHTML = vendedoresHtml;
            } else {
                clone.querySelector('#vendedoresContainer').innerHTML = '<div class="card"><div class="card-body"><p class="text-muted text-center">No hay datos de vendedores</p></div></div>';
            }

            // Renderizar comisiones: una tabla por área (mismo formato que
            // el Excel) más el resumen general por colaborador.
            const comisionesContainer = clone.querySelector('#comisionesContainer');
            if (comisionesContainer && data.comisiones_areas && data.comisiones_areas.length > 0) {
                const money = v => v && v !== '' ? v : '<span class="text-muted">–</span>';
                let html = '';

                // Encabezado cuando el reporte es de una sola persona
                if (data.comisiones_colaborador) {
                    html += `
                        <div class="alert alert-primary d-flex align-items-center">
                            <i class="fas fa-user-tag fa-lg me-3"></i>
                            <div>
                                <strong>Comisiones de ${data.comisiones_colaborador}</strong>
                                <div class="small">Solo se muestran las comisiones de esta persona en el período.</div>
                            </div>
                        </div>`;
                }

                data.comisiones_areas.forEach(area => {
                    let ths = '';
                    area.colaboradores.forEach(c => {
                        ths += `<th class="text-end text-nowrap">${c}</th>`;
                    });

                    let trs = '';
                    area.filas.forEach(f => {
                        let tds = '';
                        f.montos.forEach(m => { tds += `<td class="text-end">${money(m)}</td>`; });
                        trs += `
                            <tr>
                                <td class="text-nowrap"><code>${f.folio}</code></td>
                                <td>${f.concepto}</td>
                                <td class="text-nowrap">${f.fecha}</td>
                                <td>${f.banco}</td>
                                <td class="text-end">${f.venta}</td>
                                <td class="text-end text-warning">${money(f.descuento)}</td>
                                <td class="text-end">${f.depositado}</td>
                                <td class="text-end text-danger">${money(f.costo)}</td>
                                <td class="text-end text-danger">${money(f.gasto)}</td>
                                <td class="text-end">${money(f.iva)}</td>
                                <td class="text-end fw-bold">${f.base}</td>
                                ${tds}
                            </tr>`;
                    });

                    let totTds = '';
                    area.totales.montos.forEach(m => { totTds += `<td class="text-end fw-bold">${m}</td>`; });

                    html += `
                        <div class="card mb-3">
                            <div class="card-header bg-gradient-primary text-white">
                                <h6 class="card-title mb-0"><i class="fas fa-layer-group me-2"></i>${area.area}</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover table-bordered mb-0" style="font-size:.82rem;">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-nowrap">Folio</th>
                                                <th>Concepto</th>
                                                <th class="text-nowrap">Fecha de pago</th>
                                                <th>Banco / método</th>
                                                <th class="text-end text-nowrap">Precio de venta</th>
                                                <th class="text-end text-nowrap">Descuento</th>
                                                <th class="text-end text-nowrap">Depositado</th>
                                                <th class="text-end text-nowrap">Costo mercancía</th>
                                                <th class="text-end text-nowrap">Costo servicio</th>
                                                <th class="text-end">IVA</th>
                                                <th class="text-end text-nowrap">A comisionar</th>
                                                ${ths}
                                            </tr>
                                        </thead>
                                        <tbody>${trs}</tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <td colspan="4" class="text-end fw-bold">TOTAL DE COMISIONES</td>
                                                <td class="text-end fw-bold">${area.totales.venta}</td>
                                                <td class="text-end fw-bold text-warning">${area.totales.descuento}</td>
                                                <td class="text-end fw-bold">${area.totales.depositado}</td>
                                                <td class="text-end fw-bold text-danger">${area.totales.costo}</td>
                                                <td class="text-end fw-bold text-danger">${area.totales.gasto}</td>
                                                <td class="text-end fw-bold">${area.totales.iva}</td>
                                                <td class="text-end fw-bold">${area.totales.base}</td>
                                                ${totTds}
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>`;
                });

                // Resumen general
                const res = data.comisiones_resumen;
                if (res) {
                    let colabRows = '';
                    res.colaboradores.forEach(c => {
                        colabRows += `<tr>
                            <td>${c.nombre}</td>
                            <td class="text-muted small">${c.areas}</td>
                            <td class="text-center">${c.coms}</td>
                            <td class="text-end fw-bold">${c.monto}</td>
                        </tr>`;
                    });
                    html += `
                        <div class="card">
                            <div class="card-header bg-dark text-white">
                                <h6 class="card-title mb-0"><i class="fas fa-calculator me-2"></i>Comisiones por pagar</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 mb-2">
                                    <div class="col"><div class="border rounded p-2">
                                        <div class="metric-label">PRECIO DE VENTA</div>
                                        <div class="fw-bold">${res.venta}</div></div></div>
                                    <div class="col"><div class="border rounded p-2">
                                        <div class="metric-label">(−) DESCUENTOS</div>
                                        <div class="fw-bold text-warning">${res.descuento}</div></div></div>
                                    <div class="col"><div class="border rounded p-2">
                                        <div class="metric-label">(−) COSTO DE MERCANCÍA</div>
                                        <div class="fw-bold text-danger">${res.costo}</div></div></div>
                                    <div class="col"><div class="border rounded p-2">
                                        <div class="metric-label">(−) GASTOS DE OPERACIÓN</div>
                                        <div class="fw-bold text-danger">${res.gasto}</div></div></div>
                                    <div class="col"><div class="border rounded p-2 border-primary">
                                        <div class="metric-label">(=) TOTAL A COMISIONAR</div>
                                        <div class="fw-bold text-primary">${res.base}</div></div></div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6"><div class="border rounded p-2 bg-light">
                                        <div class="metric-label">DEPÓSITOS TOTALES (informativo)</div>
                                        <div class="fw-bold">${res.depositado}</div></div></div>
                                    <div class="col-md-6"><div class="border rounded p-2 bg-light">
                                        <div class="metric-label">IVA COBRADO (informativo)</div>
                                        <div class="fw-bold">${res.iva}</div></div></div>
                                </div>
                                <div class="alert alert-light border py-2 small mb-3">
                                    <i class="fas fa-info-circle me-1"></i>
                                    El descuento otorgado al cliente <strong>sí reduce la base de comisión</strong>:
                                    se comisiona sobre lo realmente cobrado, no sobre el precio de lista.
                                    El IVA no entra en la base.
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light"><tr>
                                            <th>Colaborador</th>
                                            <th>Área(s)</th>
                                            <th class="text-center">Coms.</th>
                                            <th class="text-end">Monto a pagar</th>
                                        </tr></thead>
                                        <tbody>${colabRows}</tbody>
                                        <tfoot><tr class="table-success">
                                            <td colspan="3" class="fw-bold text-end">TOTAL DE COMISIONES POR PAGAR</td>
                                            <td class="text-end fw-bold">${res.por_pagar}</td>
                                        </tr></tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>`;
                }

                comisionesContainer.innerHTML = html;
            } else if (comisionesContainer) {
                comisionesContainer.innerHTML = '<div class="card"><div class="card-body"><p class="text-muted text-center mb-0">No hay comisiones registradas en este periodo</p></div></div>';
            }

            // Renderizar clientes frecuentes
            if (data.clientes_frecuentes && data.clientes_frecuentes.length > 0) {
                const clientesContainer = clone.querySelector('#clientesContainer');
                let clientesHtml = `
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-crown me-2"></i>Clientes Más Frecuentes</h5>
                        </div>
                        <div class="card-body"><div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th>Cliente</th><th class="text-center">Compras</th><th class="text-end">Total Gastado</th></tr></thead><tbody>
                `;
                data.clientes_frecuentes.forEach(cliente => {
                    clientesHtml += `
                        <tr>
                            <td><div><strong>${cliente.nombre}</strong>${cliente.telefono ? '<br><small class="text-muted">'+cliente.telefono+'</small>' : ''}<br><small class="badge badge-cliente-frecuente">${cliente.tipo}</small></div></td>
                            <td class="text-center"><span class="badge bg-info">${cliente.compras}</span></td>
                            <td class="text-end text-success fw-bold">${cliente.total}</td>
                        </tr>
                    `;
                });
                clientesHtml += '</tbody></table></div></div></div>';
                clientesContainer.innerHTML = clientesHtml;
            } else {
                clone.querySelector('#clientesContainer').innerHTML = '<div class="card"><div class="card-body"><p class="text-muted text-center">No hay datos de clientes frecuentes</p></div></div>';
            }
            
            // Renderizar stock bajo
            if (data.productos_stock_bajo && data.productos_stock_bajo.length > 0) {
                const stockContainer = clone.querySelector('#stockBajoContainer');
                let stockHtml = `
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Productos con Stock Bajo</h5>
                        </div>
                        <div class="card-body"><div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th>Producto</th><th class="text-center">Stock Actual</th><th class="text-center">Mínimo</th></tr></thead><tbody>
                `;
                data.productos_stock_bajo.forEach(prod => {
                    stockHtml += `
                        <tr>
                            <td><div><strong>${prod.nombre}</strong>${prod.categoria ? '<br><small class="text-muted">'+prod.categoria+'</small>' : ''}</div></td>
                            <td class="text-center"><span class="badge badge-stock-bajo">${prod.stock}</span></td>
                            <td class="text-center"><span class="badge bg-secondary">${prod.stock_minimo}</span></td>
                        </tr>
                    `;
                });
                stockHtml += '</tbody></table></div></div></div>';
                stockContainer.innerHTML = stockHtml;
            } else if (data.productos_stock_bajo_count === 0) {
                clone.querySelector('#stockBajoContainer').innerHTML = `
                    <div class="card">
                        <div class="card-body text-center text-success py-4">
                            <i class="fas fa-check-circle fa-3x mb-3"></i>
                            <p class="mb-0">¡Excelente! No hay productos con stock bajo</p>
                        </div>
                    </div>
                `;
            } else {
                clone.querySelector('#stockBajoContainer').innerHTML = '<div class="card"><div class="card-body"><p class="text-muted text-center">No hay datos de productos con stock bajo</p></div></div>';
            }
            
            // Limpiar contenedor y agregar el clon
            const container = document.getElementById('reportesContainer');
            container.innerHTML = '';
            container.appendChild(clone);
            
            // Inicializar gráficas después de renderizar
            inicializarGraficas(data);
        }
        
        function renderizarReporteInventario(data) {
            const template = document.getElementById('templateInventario');
            const clone = template.content.cloneNode(true);
            
            // Tabla de inventario
            if (data.productos_stock_bajo && data.productos_stock_bajo.length > 0) {
                const tablaContainer = clone.querySelector('#inventarioTablaContainer');
                let tablaHtml = `
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-boxes me-2"></i>Inventario de Productos</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr><th>Código</th><th>Producto</th><th>Categoría</th><th>Stock Actual</th><th>Stock Mínimo</th><th>Estado</th></tr>
                                    </thead>
                                    <tbody>
                `;
                data.productos_stock_bajo.forEach(prod => {
                    let estadoClass = prod.stock <= 0 ? 'danger' : (prod.stock <= prod.stock_minimo ? 'warning' : 'success');
                    let estadoText = prod.stock <= 0 ? 'AGOTADO' : (prod.stock <= prod.stock_minimo ? 'BAJO STOCK' : 'DISPONIBLE');
                    tablaHtml += `
                        <tr>
                            <td><code>${prod.codigo}</code></td>
                            <td><strong>${prod.nombre}</strong>${prod.categoria ? '<br><small class="text-muted">'+prod.categoria+'</small>' : ''}</td>
                            <td>${prod.categoria || '-'}</td>
                            <td class="text-center"><span class="badge bg-${estadoClass}">${prod.stock}</span></td>
                            <td class="text-center">${prod.stock_minimo}</td>
                            <td><span class="badge bg-${estadoClass}">${estadoText}</span></td>
                        </tr>
                    `;
                });
                tablaHtml += '</tbody></table></div></div></div>';
                tablaContainer.innerHTML = tablaHtml;
            } else {
                clone.querySelector('#inventarioTablaContainer').innerHTML = `
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No hay productos registrados</h5>
                        </div>
                    </div>
                `;
            }
            
            const container = document.getElementById('reportesContainer');
            container.innerHTML = '';
            container.appendChild(clone);
        }
        
        function renderizarReporteClientes(data) {
            const template = document.getElementById('templateClientes');
            const clone = template.content.cloneNode(true);
            
            if (data.clientes_frecuentes && data.clientes_frecuentes.length > 0) {
                const tablaContainer = clone.querySelector('#clientesTablaContainer');
                let tablaHtml = `
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-users me-2"></i>Reporte de Clientes</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr><th>Nombre</th><th>Tipo</th><th>Teléfono</th><th>Compras</th><th>Total Gastado</th><th>Última Compra</th></tr>
                                    </thead>
                                    <tbody>
                `;
                data.clientes_frecuentes.forEach(cliente => {
                    tablaHtml += `
                        <tr>
                            <td><strong>${cliente.nombre}</strong></td>
                            <td><span class="badge badge-cliente-frecuente">${cliente.tipo}</span></td>
                            <td>${cliente.telefono || '-'}</td>
                            <td class="text-center">${cliente.compras}</td>
                            <td class="text-end text-success fw-bold">${cliente.total}</td>
                            <td>${cliente.ultima_compra}</td>
                        </tr>
                    `;
                });
                tablaHtml += '</tbody></table></div></div></div>';
                tablaContainer.innerHTML = tablaHtml;
            } else {
                clone.querySelector('#clientesTablaContainer').innerHTML = `
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-user-friends fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No hay clientes registrados</h5>
                        </div>
                    </div>
                `;
            }
            
            const container = document.getElementById('reportesContainer');
            container.innerHTML = '';
            container.appendChild(clone);
        }
        
        function inicializarGraficas(data) {
            // Destruir gráficas existentes
            if (chartVentas) chartVentas.destroy();
            if (chartHoras) chartHoras.destroy();
            if (chartSucursales) chartSucursales.destroy();
            
            // Gráfica de ventas diarias
            if (data.grafica && data.grafica.labels && data.grafica.labels.length > 0) {
                const ctxVentas = document.getElementById('graficaVentas');
                if (ctxVentas) {
                    chartVentas = new Chart(ctxVentas, {
                        type: 'line',
                        data: {
                            labels: data.grafica.labels,
                            datasets: [
                                { label: 'Subtotal', data: data.grafica.data_subtotal, borderColor: '#3498db', backgroundColor: 'rgba(52, 152, 219, 0.1)', borderWidth: 2, fill: true, tension: 0.4 },
                                { label: 'IVA', data: data.grafica.data_iva, borderColor: '#e74c3c', backgroundColor: 'rgba(231, 76, 60, 0.1)', borderWidth: 2, fill: true, tension: 0.4 },
                                { label: 'Total', data: data.grafica.data_total, borderColor: '#27ae60', backgroundColor: 'rgba(39, 174, 96, 0.1)', borderWidth: 3, fill: true, tension: 0.4 }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': $' + ctx.parsed.y.toFixed(2) } } },
                            scales: { y: { beginAtZero: true, ticks: { callback: (value) => '$' + value.toFixed(2) } } }
                        }
                    });
                }
            }
            
            // Gráfica de ventas por hora
            if (data.grafica_horas && data.grafica_horas.labels && data.grafica_horas.labels.length > 0) {
                const ctxHoras = document.getElementById('graficaHoras');
                if (ctxHoras) {
                    chartHoras = new Chart(ctxHoras, {
                        type: 'bar',
                        data: {
                            labels: data.grafica_horas.labels,
                            datasets: [{ label: 'Ventas por Hora', data: data.grafica_horas.data, backgroundColor: 'rgba(243, 156, 18, 0.8)', borderColor: 'rgba(243, 156, 18, 1)', borderWidth: 1 }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => 'Ventas: $' + ctx.parsed.y.toFixed(2) } } },
                            scales: { y: { beginAtZero: true, ticks: { callback: (value) => '$' + value.toFixed(2) } } }
                        }
                    });
                }
            }
            
            // Gráfica de sucursales
            if (data.sucursales && data.sucursales.length > 0) {
                const ctxSucursales = document.getElementById('graficaSucursales');
                if (ctxSucursales) {
                    const labels = data.sucursales.map(s => s.nombre);
                    const valores = data.sucursales.map(s => s.total);
                    chartSucursales = new Chart(ctxSucursales, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{ data: valores, backgroundColor: ['#27ae60', '#3498db', '#e74c3c', '#f39c12', '#9b59b6', '#1abc9c', '#d35400', '#c0392b', '#7f8c8d', '#34495e'], borderWidth: 2, borderColor: '#fff' }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: { callbacks: { label: (ctx) => ctx.label + ': $' + ctx.parsed.toFixed(2) } }
                            }
                        }
                    });
                }
            }
        }
        
        // Exportar funciones
        function exportarExcel() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            const sucursalId = document.getElementById('sucursal_id').value;
            const tipoReporte = document.getElementById('tipo_reporte').value;
            const colaboradorId = document.getElementById('colaborador_id')?.value || '';
            window.open(`exportar_excel.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&sucursal_id=${sucursalId}&tipo_reporte=${tipoReporte}&colaborador_id=${colaboradorId}`, '_blank');
        }
        
        function exportarPDF() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            const sucursalId = document.getElementById('sucursal_id').value;
            const tipoReporte = document.getElementById('tipo_reporte').value;
            const colaboradorId = document.getElementById('colaborador_id')?.value || '';
            window.open(`exportar_pdf.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&sucursal_id=${sucursalId}&tipo_reporte=${tipoReporte}&colaborador_id=${colaboradorId}`, '_blank');
        }
        
        window.exportarExcel = exportarExcel;
        window.exportarPDF = exportarPDF;
        
        // Imprimir solo el bloque de comisiones: abre el PDF con el filtro
        // de la persona ya aplicado.
        document.getElementById('btnImprimirComisiones')?.addEventListener('click', function () {
            exportarPDF();
        });

        // Event listeners
        document.getElementById('btnAplicarFiltros').addEventListener('click', cargarReportes);
        document.getElementById('colaborador_id')?.addEventListener('change', cargarReportes);
        document.getElementById('fecha_inicio').addEventListener('change', cargarReportes);
        document.getElementById('fecha_fin').addEventListener('change', cargarReportes);
        document.getElementById('tipo_reporte').addEventListener('change', cargarReportes);
        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
        document.getElementById('sucursal_id').addEventListener('change', cargarReportes);
        <?php endif; ?>
        
        // Cargar reportes al iniciar
        cargarReportes();
    </script>
</body>

</html>