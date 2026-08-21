<?php
// comisiones.php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) && !isset($_SESSION['distribuidor_id'])) {
    header("Location: login-distribuidor.php");
    exit();
}

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Variables para filtros
$filtro_distribuidor = isset($_GET['distribuidor']) ? $_GET['distribuidor'] : 'todos';
$filtro_mes = isset($_GET['mes']) ? $_GET['mes'] : date('m');
$filtro_anio = isset($_GET['anio']) ? $_GET['anio'] : date('Y');
$filtro_plan = isset($_GET['plan']) ? $_GET['plan'] : 'todos';
$filtro_estado_pago = isset($_GET['estado_pago']) ? $_GET['estado_pago'] : 'todos';

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Determinar si es distribuidor o admin
$es_distribuidor = isset($_SESSION['distribuidor_id']);
$distribuidor_id = $es_distribuidor ? $_SESSION['distribuidor_id'] : 0;

// Obtener lista de distribuidores para el filtro (solo para admin)
$distribuidores = [];
if (!$es_distribuidor) {
    $sql_dist = "SELECT id, nombre_distribuidor, numero_control FROM distribuidores ORDER BY nombre_distribuidor";
    $result_dist = $conn->query($sql_dist);
    if ($result_dist->num_rows > 0) {
        while ($row = $result_dist->fetch_assoc()) {
            $distribuidores[] = $row;
        }
    }
}

// Obtener información del distribuidor si está logueado
$distribuidor_info = null;
if ($es_distribuidor) {
    $sql = "SELECT * FROM distribuidores WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $distribuidor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $distribuidor_info = $result->fetch_assoc();
    $stmt->close();
}

// Función para obtener el nombre del mes en español
function getNombreMes($mes)
{
    $meses = [
        '01' => 'Enero',
        '02' => 'Febrero',
        '03' => 'Marzo',
        '04' => 'Abril',
        '05' => 'Mayo',
        '06' => 'Junio',
        '07' => 'Julio',
        '08' => 'Agosto',
        '09' => 'Septiembre',
        '10' => 'Octubre',
        '11' => 'Noviembre',
        '12' => 'Diciembre'
    ];
    return $meses[$mes] ?? $mes;
}

// Función para calcular comisión del 30% sobre precio sin IVA
function calcularComision($monto_sin_iva)
{
    return $monto_sin_iva * 0.30;
}

// Obtener comisiones por distribuidor y empresa
$comisiones = [];
$totales_generales = [
    'total_ventas_sin_iva' => 0,
    'total_ventas_con_iva' => 0,
    'total_comision' => 0,
    'total_activaciones' => 0,
    'total_distribuidores' => 0,
    'total_empresas' => 0
];

// Array para almacenar totales por distribuidor
$totales_por_distribuidor = [];

// Verificar si el distribuidor ya tiene datos bancarios
$datos_bancarios_completos = false;
if ($es_distribuidor && $distribuidor_info) {
    $datos_bancarios_completos = !empty($distribuidor_info['banco']) && !empty($distribuidor_info['numero_cuenta']);
}

// Procesar el guardado de datos bancarios
if ($es_distribuidor && isset($_POST['guardar_banco'])) {
    $banco = trim($_POST['banco']);
    $numero_cuenta = trim($_POST['numero_cuenta']);
    $distribuidor_id = $_SESSION['distribuidor_id'];
    
    if (!empty($banco) && !empty($numero_cuenta)) {
        $conn_update = new mysqli($servername, $username, $password, $dbname);
        
        if (!$conn_update->connect_error) {
            $sql_update = "UPDATE distribuidores SET banco = ?, numero_cuenta = ? WHERE id = ?";
            $stmt = $conn_update->prepare($sql_update);
            $stmt->bind_param("ssi", $banco, $numero_cuenta, $distribuidor_id);
            
            if ($stmt->execute()) {
                $mensaje_exito = "Datos bancarios guardados correctamente";
                $distribuidor_info['banco'] = $banco;
                $distribuidor_info['numero_cuenta'] = $numero_cuenta;
                $datos_bancarios_completos = true;
            } else {
                $mensaje_error = "Error al guardar los datos bancarios";
            }
            $stmt->close();
            $conn_update->close();
        }
    } else {
        $mensaje_error = "Todos los campos son obligatorios";
    }
}

try {
    $sql = "SELECT 
            d.id as distribuidor_id,
            d.nombre_distribuidor,
            d.numero_control,
            e.id as empresa_id,
            e.nombre_empresa,
            e.plan,
            e.estado_verificacion,
            COUNT(DISTINCT ap.id) as total_planes,
            COUNT(DISTINCT at.id) as total_timbres,
            COUNT(DISTINCT asu.id) as total_sucursales,
            COALESCE(SUM(ap.precio_sin_iva), 0) as total_sin_iva_planes,
            COALESCE(SUM(ap.precio_con_iva), 0) as total_con_iva_planes,
            COALESCE(SUM(at.precio_sin_iva), 0) as total_sin_iva_timbres,
            COALESCE(SUM(at.precio_con_iva), 0) as total_con_iva_timbres,
            COALESCE(SUM(asu.precio_sin_iva), 0) as total_sin_iva_sucursales,
            COALESCE(SUM(asu.precio_con_iva), 0) as total_con_iva_sucursales,
            MAX(ap.fecha_activacion) as ultima_activacion_plan,
            MAX(at.fecha_activacion) as ultima_activacion_timbre,
            MAX(asu.fecha_activacion) as ultima_activacion_sucursal
            FROM distribuidores d
            LEFT JOIN empresas e ON e.no_distribuidor = d.numero_control
            LEFT JOIN activaciones_plan ap ON e.id = ap.empresa_id";

    if (!empty($filtro_mes) && !empty($filtro_anio)) {
        $sql .= " AND MONTH(ap.fecha_activacion) = '$filtro_mes' AND YEAR(ap.fecha_activacion) = '$filtro_anio'";
    }

    $sql .= " LEFT JOIN activaciones_timbres at ON e.id = at.empresa_id";

    if (!empty($filtro_mes) && !empty($filtro_anio)) {
        $sql .= " AND MONTH(at.fecha_activacion) = '$filtro_mes' AND YEAR(at.fecha_activacion) = '$filtro_anio'";
    }

    $sql .= " LEFT JOIN activaciones_sucursales asu ON e.id = asu.empresa_id";

    if (!empty($filtro_mes) && !empty($filtro_anio)) {
        $sql .= " AND MONTH(asu.fecha_activacion) = '$filtro_mes' AND YEAR(asu.fecha_activacion) = '$filtro_anio'";
    }

    $sql .= " WHERE 1=1";

    if ($es_distribuidor && $distribuidor_info) {
        $sql .= " AND d.numero_control = '{$distribuidor_info['numero_control']}'";
    }

    if (!$es_distribuidor && $filtro_distribuidor != 'todos') {
        $sql .= " AND d.id = '$filtro_distribuidor'";
    }

    if ($filtro_plan != 'todos') {
        $sql .= " AND e.plan = '$filtro_plan'";
    }

    $sql .= " GROUP BY d.id, d.nombre_distribuidor, d.numero_control, e.id, e.nombre_empresa, e.plan, e.estado_verificacion
              ORDER BY d.nombre_distribuidor, e.nombre_empresa";

    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if ($row['empresa_id']) {
                $total_activaciones = intval($row['total_planes']) + intval($row['total_timbres']) + intval($row['total_sucursales']);
                $total_sin_iva = floatval($row['total_sin_iva_planes']) +
                    floatval($row['total_sin_iva_timbres']) +
                    floatval($row['total_sin_iva_sucursales']);

                $total_con_iva = floatval($row['total_con_iva_planes']) +
                    floatval($row['total_con_iva_timbres']) +
                    floatval($row['total_con_iva_sucursales']);

                $comision = calcularComision($total_sin_iva);

                $ultima_activacion = max(
                    $row['ultima_activacion_plan'] ?? '0000-00-00',
                    $row['ultima_activacion_timbre'] ?? '0000-00-00',
                    $row['ultima_activacion_sucursal'] ?? '0000-00-00'
                );

                if ($total_activaciones > 0) {
                    $comisiones[] = [
                        'distribuidor_id' => $row['distribuidor_id'],
                        'nombre_distribuidor' => $row['nombre_distribuidor'],
                        'numero_control' => $row['numero_control'],
                        'empresa_id' => $row['empresa_id'],
                        'nombre_empresa' => $row['nombre_empresa'],
                        'plan' => $row['plan'],
                        'estado_verificacion' => $row['estado_verificacion'],
                        'total_activaciones' => $total_activaciones,
                        'total_planes' => intval($row['total_planes']),
                        'total_timbres' => intval($row['total_timbres']),
                        'total_sucursales' => intval($row['total_sucursales']),
                        'total_sin_iva' => $total_sin_iva,
                        'total_con_iva' => $total_con_iva,
                        'comision' => $comision,
                        'ultima_activacion' => $ultima_activacion
                    ];

                    $dist_key = $row['distribuidor_id'];
                    if (!isset($totales_por_distribuidor[$dist_key])) {
                        $totales_por_distribuidor[$dist_key] = [
                            'distribuidor_id' => $row['distribuidor_id'],
                            'nombre_distribuidor' => $row['nombre_distribuidor'],
                            'numero_control' => $row['numero_control'],
                            'total_empresas' => 0,
                            'total_ventas_sin_iva' => 0,
                            'total_ventas_con_iva' => 0,
                            'total_comision' => 0,
                            'total_activaciones' => 0
                        ];
                    }

                    $totales_por_distribuidor[$dist_key]['total_empresas']++;
                    $totales_por_distribuidor[$dist_key]['total_ventas_sin_iva'] += $total_sin_iva;
                    $totales_por_distribuidor[$dist_key]['total_ventas_con_iva'] += $total_con_iva;
                    $totales_por_distribuidor[$dist_key]['total_comision'] += $comision;
                    $totales_por_distribuidor[$dist_key]['total_activaciones'] += $total_activaciones;

                    $totales_generales['total_ventas_sin_iva'] += $total_sin_iva;
                    $totales_generales['total_ventas_con_iva'] += $total_con_iva;
                    $totales_generales['total_comision'] += $comision;
                    $totales_generales['total_activaciones'] += $total_activaciones;
                    $totales_generales['total_empresas']++;
                }
            }
        }

        $totales_generales['total_distribuidores'] = count($totales_por_distribuidor);
    }
} catch (Exception $e) {
    $error = "Error al cargar las comisiones: " . $e->getMessage();
}

$conn->close();

function getPlanNombre($plan)
{
    $planes = [
        'prueba' => 'Prueba',
        'basico' => 'Básico',
        'starter' => 'Starter',
        'emprendedor' => 'Emprendedor',
        'premium' => 'Premium'
    ];
    return $planes[$plan] ?? ucfirst($plan);
}

function claseEstado($estado)
{
    switch ($estado) {
        case 'pendiente':
            return 'warning';
        case 'en_revision':
            return 'info';
        case 'aprobado':
            return 'success';
        case 'rechazado':
            return 'danger';
        default:
            return 'secondary';
    }
}

function formatearMoneda($monto)
{
    return '$' . number_format(floatval($monto), 2, '.', ',') . ' MXN';
}

function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00' || $fecha == '0000-00-00') {
        return 'No registrada';
    }
    return date('d/m/Y', strtotime($fecha));
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Comisiones por Plan - Libertyfin</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #27ae60;
            --primary-dark: #219a52;
            --secondary-color: #2ecc71;
            --accent-color: #3498db;
            --dark-bg: #1a2634;
            --gray-bg: #f8fafc;
            --card-shadow: 0 10px 40px rgba(0,0,0,0.08);
            --card-shadow-hover: 0 20px 50px rgba(0,0,0,0.12);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* Navbar mejorado - MISMO QUE PANEL-DISTRIBUIDOR */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 4px 20px rgba(39,174,96,0.2);
            padding: 0.8rem 1rem;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: -0.3px;
        }

        .img-logo-navbar {
            height: 36px;
            width: auto;
            filter: brightness(0) invert(1);
        }

        /* Sidebar moderno */
        .sidebar {
            background: linear-gradient(180deg, #1e2a36 0%, #1a2530 100%);
            color: white;
            min-height: calc(100vh - 70px);
            transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            will-change: transform;
            box-shadow: 2px 0 20px rgba(0,0,0,0.1);
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 4px 12px;
            border-radius: 12px;
            transition: var(--transition-smooth);
            font-weight: 500;
            position: relative;
        }

        .sidebar .nav-link:hover {
            background: rgba(46, 204, 113, 0.15);
            color: white;
            transform: translateX(5px);
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(39,174,96,0.3);
        }

        .sidebar .nav-link i {
            width: 24px;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        /* Tarjetas modernas */
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        /* Tarjetas de estadísticas */
        .stat-card {
            border-radius: 20px;
            cursor: pointer;
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .stat-card:hover {
            transform: translateY(-8px);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.2;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .metric-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
        }

        /* Filtros */
        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .btn-filter {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 30px;
            transition: var(--transition-smooth);
            font-weight: 500;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
            color: white;
        }

        .btn-reset {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 30px;
            transition: var(--transition-smooth);
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            color: white;
        }

        /* Badge para planes */
        .badge-plan {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-plan.prueba {
            background: #6c757d;
            color: white;
        }

        .badge-plan.basico {
            background: #17a2b8;
            color: white;
        }

        .badge-plan.starter {
            background: #ffc107;
            color: #212529;
        }

        .badge-plan.emprendedor {
            background: #fd7e14;
            color: white;
        }

        .badge-plan.premium {
            background: #28a745;
            color: white;
        }

        .badge-estado {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Tabla moderna */
        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: linear-gradient(135deg, #f8f9fa, #f1f3f5);
            border-bottom: 2px solid #e9ecef;
            font-weight: 700;
            color: #2c3e50;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 1rem;
        }

        .table tbody tr {
            transition: var(--transition-smooth);
        }

        .table tbody tr:hover {
            background: linear-gradient(90deg, #f8f9fa, #ffffff);
            transform: scale(1.01);
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }

        .comision-positive {
            color: #28a745;
            font-weight: 700;
        }

        /* Tarjetas para móvil */
        .comisiones-cards-movil {
            display: none;
        }

        .comision-card-movil {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: var(--transition-smooth);
            border: 1px solid #e9ecef;
            position: relative;
            overflow: hidden;
        }

        .comision-card-movil::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .comision-card-movil:hover {
            transform: translateX(5px);
            box-shadow: var(--card-shadow);
        }

        @media (max-width: 767.98px) {
            .table-responsive-desktop {
                display: none;
            }
            .comisiones-cards-movil {
                display: block;
            }
        }

        /* Botón púrpura */
        .btn-purple {
            background: linear-gradient(135deg, #9b59b6, #8e44ad) !important;
            color: white !important;
            border: none !important;
            padding: 0.6rem 1.2rem !important;
            border-radius: 30px !important;
            font-weight: 500 !important;
            transition: var(--transition-smooth) !important;
        }

        .btn-purple:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 5px 15px rgba(155, 89, 182, 0.3) !important;
        }

        .btn-purple-outline {
            background: transparent !important;
            color: #9b59b6 !important;
            border: 2px solid #9b59b6 !important;
            padding: 0.5rem 1rem !important;
            border-radius: 30px !important;
            font-weight: 500 !important;
            transition: var(--transition-smooth) !important;
        }

        .btn-purple-outline:hover {
            background: #9b59b6 !important;
            color: white !important;
        }

        .bank-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            border-left: 3px solid #9b59b6;
        }

        .progress-custom {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            overflow: hidden;
        }

        .progress-custom-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 3px;
            transition: width 0.5s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card, .filter-section {
            animation: fadeInUp 0.5s ease-out forwards;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }

        /* Botón hamburguesa */
        .sidebar-toggle {
            display: none;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem 0.8rem;
            margin-right: 1rem;
            border-radius: 12px;
            transition: var(--transition-smooth);
        }

        .sidebar-toggle:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Responsive - MISMO QUE PANEL-DISTRIBUIDOR */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 70px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 70px);
                z-index: 1050;
                overflow-y: auto;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }

            .metric-value {
                font-size: 1.5rem;
            }
            
            .col-md-3 {
                margin-bottom: 15px;
            }
        }

        /* Formularios */
        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(39, 174, 96, 0.25);
        }

        .form-select.rounded-pill,
        .form-control.rounded-pill {
            border-radius: 30px !important;
        }
    </style>
</head>

<body>
    <!-- Navbar - MISMA ESTRUCTURA QUE PANEL-DISTRIBUIDOR -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="<?php echo $es_distribuidor ? 'panel-distribuidor.php' : 'dashboard.php'; ?>">
                <img src="../images/LibertyfinBlanco.webp" alt="LibertyFin" class="me-2 img-logo-navbar">
                <span><?php echo $es_distribuidor ? 'Panel Distribuidor' : 'Panel Admin'; ?></span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-2"></i>
                        <span>
                            <?php
                            if ($es_distribuidor && $distribuidor_info) {
                                echo htmlspecialchars($distribuidor_info['nombre_distribuidor']);
                            } else {
                                echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin');
                            }
                            ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <?php if ($es_distribuidor): ?>
                            <li><a class="dropdown-item py-2" href="perfil-distribuidor.php"><i class="fas fa-user me-2"></i>Mi Perfil</a></li>
                        <?php else: ?>
                            <li><a class="dropdown-item py-2" href="perfil.php"><i class="fas fa-user me-2"></i>Mi Perfil</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="<?php echo $es_distribuidor ? 'cerrar-sesion.php' : 'logout.php'; ?>">
                                <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
                            </a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-4">
                    <ul class="nav flex-column">
                        <?php if ($es_distribuidor): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="panel-distribuidor.php">
                                    <i class="fas fa-tachometer-alt"></i>
                                    Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="perfil-distribuidor.php">
                                    <i class="fas fa-user-cog"></i>
                                    Perfil
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="mis-empresas.php">
                                    <i class="fas fa-building"></i>
                                    Empresas
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="nueva-empresa.php">
                                    <i class="fas fa-plus-circle"></i>
                                    Registrar Empresa
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link active" href="comisiones.php">
                                    <i class="fas fa-chart-line"></i>
                                    Comisiones
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="nav-link" href="dashboard.php">
                                    <i class="fas fa-tachometer-alt"></i>
                                    Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="empresas.php">
                                    <i class="fas fa-building"></i>
                                    Empresas
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link active" href="comisiones.php">
                                    <i class="fas fa-chart-line"></i>
                                    Comisiones
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="distribuidores.php">
                                    <i class="fas fa-users"></i>
                                    Distribuidores
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Encabezado -->
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h3 mb-1 fw-bold">
                            <i class="fas fa-chart-line me-2" style="color: var(--primary-color);"></i>
                            <?php echo $es_distribuidor ? 'Mis Comisiones' : 'Comisiones por Plan'; ?>
                        </h2>
                        <p class="text-muted mb-0">
                            <small>
                                <?php
                                if ($es_distribuidor) {
                                    echo "Comisiones generadas por tus empresas registradas (30% sobre precio sin IVA)";
                                } else {
                                    echo "Reporte de comisiones de distribuidores por plan (30% sobre precio sin IVA)";
                                }
                                ?>
                            </small>
                        </p>
                    </div>
                    <?php if (!empty($comisiones)): ?>
                        <button class="btn btn-filter" onclick="exportarExcel()">
                            <i class="fas fa-file-excel me-2"></i>Exportar
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Filtros -->
                <div class="filter-section">
                    <form method="GET" id="filterForm" class="row g-3">
                        <?php if (!$es_distribuidor): ?>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-user-tie me-1"></i>Distribuidor
                                </label>
                                <select class="form-select rounded-pill" name="distribuidor">
                                    <option value="todos">Todos los distribuidores</option>
                                    <?php foreach ($distribuidores as $dist): ?>
                                        <option value="<?php echo $dist['id']; ?>" <?php echo $filtro_distribuidor == $dist['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dist['nombre_distribuidor']); ?> (<?php echo $dist['numero_control']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">
                                <i class="fas fa-calendar-alt me-1"></i>Mes
                            </label>
                            <select class="form-select rounded-pill" name="mes">
                                <option value="01" <?php echo $filtro_mes == '01' ? 'selected' : ''; ?>>Enero</option>
                                <option value="02" <?php echo $filtro_mes == '02' ? 'selected' : ''; ?>>Febrero</option>
                                <option value="03" <?php echo $filtro_mes == '03' ? 'selected' : ''; ?>>Marzo</option>
                                <option value="04" <?php echo $filtro_mes == '04' ? 'selected' : ''; ?>>Abril</option>
                                <option value="05" <?php echo $filtro_mes == '05' ? 'selected' : ''; ?>>Mayo</option>
                                <option value="06" <?php echo $filtro_mes == '06' ? 'selected' : ''; ?>>Junio</option>
                                <option value="07" <?php echo $filtro_mes == '07' ? 'selected' : ''; ?>>Julio</option>
                                <option value="08" <?php echo $filtro_mes == '08' ? 'selected' : ''; ?>>Agosto</option>
                                <option value="09" <?php echo $filtro_mes == '09' ? 'selected' : ''; ?>>Septiembre</option>
                                <option value="10" <?php echo $filtro_mes == '10' ? 'selected' : ''; ?>>Octubre</option>
                                <option value="11" <?php echo $filtro_mes == '11' ? 'selected' : ''; ?>>Noviembre</option>
                                <option value="12" <?php echo $filtro_mes == '12' ? 'selected' : ''; ?>>Diciembre</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">
                                <i class="fas fa-calendar-alt me-1"></i>Año
                            </label>
                            <select class="form-select rounded-pill" name="anio">
                                <?php
                                $anio_actual = date('Y');
                                for ($i = $anio_actual; $i >= $anio_actual - 2; $i--):
                                ?>
                                    <option value="<?php echo $i; ?>" <?php echo $filtro_anio == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">
                                <i class="fas fa-tag me-1"></i>Plan
                            </label>
                            <select class="form-select rounded-pill" name="plan">
                                <option value="todos">Todos los planes</option>
                                <option value="basico" <?php echo $filtro_plan == 'basico' ? 'selected' : ''; ?>>Básico</option>
                                <option value="starter" <?php echo $filtro_plan == 'starter' ? 'selected' : ''; ?>>Starter</option>
                                <option value="emprendedor" <?php echo $filtro_plan == 'emprendedor' ? 'selected' : ''; ?>>Emprendedor</option>
                                <option value="premium" <?php echo $filtro_plan == 'premium' ? 'selected' : ''; ?>>Premium</option>
                                <option value="prueba" <?php echo $filtro_plan == 'prueba' ? 'selected' : ''; ?>>Prueba</option>
                            </select>
                        </div>

                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-filter w-100">
                                <i class="fas fa-search me-2"></i>Aplicar Filtros
                            </button>
                            <a href="comisiones.php<?php echo $es_distribuidor ? '' : '?distribuidor=todos'; ?>" class="btn btn-reset w-100">
                                <i class="fas fa-undo me-2"></i>Limpiar
                            </a>
                        </div>
                    </form>
                </div>

                <?php if (!empty($comisiones)): ?>
                    <!-- Tarjetas de resumen general -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="card stat-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label mb-2">Ventas sin IVA</div>
                                            <div class="metric-value"><?php echo formatearMoneda($totales_generales['total_ventas_sin_iva']); ?></div>
                                            <small class="text-muted">Base para comisión del 30%</small>
                                        </div>
                                        <div class="bg-info bg-opacity-10 rounded-3 p-3">
                                            <i class="fas fa-file-invoice text-info fa-2x"></i>
                                        </div>
                                    </div>
                                    <div class="progress-custom mt-3">
                                        <div class="progress-custom-bar" style="width: 100%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="card stat-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label mb-2">Ventas con IVA</div>
                                            <div class="metric-value"><?php echo formatearMoneda($totales_generales['total_ventas_con_iva']); ?></div>
                                            <small class="text-muted">Total facturado con IVA</small>
                                        </div>
                                        <div class="bg-secondary bg-opacity-10 rounded-3 p-3">
                                            <i class="fas fa-file-invoice-dollar text-secondary fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="card stat-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label mb-2">Comisión Total (30%)</div>
                                            <div class="metric-value text-success"><?php echo formatearMoneda($totales_generales['total_comision']); ?></div>
                                            <small class="text-muted">A pagar a distribuidores</small>
                                        </div>
                                        <div class="bg-success bg-opacity-10 rounded-3 p-3">
                                            <i class="fas fa-percentage text-success fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($es_distribuidor && $totales_generales['total_comision'] > 0): ?>
                            <div class="col-md-3">
                                <div class="card stat-card h-100" style="border-left-color: #9b59b6;">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="metric-label mb-2">Llamada Cobrar</div>
                                                <?php if ($datos_bancarios_completos): ?>
                                                    <div class="bank-info mb-2">
                                                        <small class="d-block"><i class="fas fa-university"></i> <?php echo htmlspecialchars($distribuidor_info['banco']); ?></small>
                                                        <small><i class="fas fa-credit-card"></i> <?php echo htmlspecialchars($distribuidor_info['numero_cuenta']); ?></small>
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <button type="button" class="btn btn-purple-outline btn-sm" onclick="abrirModalBanco(true)">
                                                            <i class="fas fa-edit me-1"></i>Editar
                                                        </button>
                                                        <button type="button" class="btn btn-purple btn-sm" onclick="solicitarPago()">
                                                            <i class="fas fa-whatsapp me-1"></i>Solicitar
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-purple" onclick="abrirModalBanco(false)">
                                                        <i class="fas fa-whatsapp me-2"></i>Llamada Cobrar
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            <div class="bg-purple bg-opacity-10 rounded-3 p-3">
                                                <i class="fas fa-hand-holding-usd" style="color: #9b59b6; font-size: 2rem;"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="col-md-3">
                                <div class="card stat-card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="metric-label mb-2">Estadísticas</div>
                                                <div class="metric-value"><?php echo $totales_generales['total_empresas']; ?> empresas</div>
                                                <small class="text-muted">
                                                    <?php echo $totales_generales['total_distribuidores']; ?> distribuidores •
                                                    <?php echo $totales_generales['total_activaciones']; ?> activaciones
                                                </small>
                                            </div>
                                            <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                                                <i class="fas fa-chart-bar text-warning fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tabla de comisiones por distribuidor (solo para admin) -->
                    <?php if (!$es_distribuidor && !empty($totales_por_distribuidor)): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-users me-2 text-success"></i>
                                    Resumen por Distribuidor
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="tablaDistribuidores">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Distribuidor</th>
                                                <th>N° Control</th>
                                                <th>Empresas</th>
                                                <th>Activaciones</th>
                                                <th class="text-end">Ventas sin IVA</th>
                                                <th class="text-end">Ventas con IVA</th>
                                                <th class="text-end">Comisión (30%)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($totales_por_distribuidor as $dist): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($dist['nombre_distribuidor']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?php echo $dist['numero_control']; ?></span>
                                                    </td>
                                                    <td class="text-center"><?php echo $dist['total_empresas']; ?></td>
                                                    <td class="text-center"><?php echo $dist['total_activaciones']; ?></td>
                                                    <td class="text-end"><?php echo formatearMoneda($dist['total_ventas_sin_iva']); ?></td>
                                                    <td class="text-end"><?php echo formatearMoneda($dist['total_ventas_con_iva']); ?></td>
                                                    <td class="text-end">
                                                        <span class="comision-positive">
                                                            <?php echo formatearMoneda($dist['total_comision']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="4" class="text-end">Totales:</th>
                                                <th class="text-end"><?php echo formatearMoneda($totales_generales['total_ventas_sin_iva']); ?></th>
                                                <th class="text-end"><?php echo formatearMoneda($totales_generales['total_ventas_con_iva']); ?></th>
                                                <th class="text-end text-success"><?php echo formatearMoneda($totales_generales['total_comision']); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tabla detallada de comisiones por empresa -->
                    <div class="card">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <div class="d-flex align-items-center justify-content-between">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-building me-2 text-success"></i>
                                    Detalle de Comisiones por Empresa
                                </h5>
                                <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo count($comisiones); ?> registros</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <!-- Versión Desktop - Tabla -->
                            <div class="table-responsive-desktop">
                                <table class="table table-hover mb-0" id="tablaComisiones">
                                    <thead class="table-light">
                                        <tr>
                                            <?php if (!$es_distribuidor): ?>
                                                <th>Distribuidor</th>
                                            <?php endif; ?>
                                            <th>Empresa</th>
                                            <th>Plan</th>
                                            <th>Estado</th>
                                            <th class="text-center">Activaciones</th>
                                            <th class="text-end">Ventas sin IVA</th>
                                            <th class="text-end">Ventas con IVA</th>
                                            <th class="text-end">Comisión (30%)</th>
                                            <th>Última Activación</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($comisiones as $com): ?>
                                            <tr>
                                                <?php if (!$es_distribuidor): ?>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($com['nombre_distribuidor']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo $com['numero_control']; ?></small>
                                                    </td>
                                                <?php endif; ?>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($com['nombre_empresa']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge-plan <?php echo $com['plan']; ?>">
                                                        <?php echo getPlanNombre($com['plan']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge-estado bg-<?php echo claseEstado($com['estado_verificacion']); ?> bg-opacity-10 text-<?php echo claseEstado($com['estado_verificacion']); ?>">
                                                        <?php echo ucfirst($com['estado_verificacion']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo $com['total_activaciones']; ?>
                                                    <?php
                                                    $detalle = [];
                                                    if ($com['total_planes'] > 0) $detalle[] = "P:{$com['total_planes']}";
                                                    if ($com['total_timbres'] > 0) $detalle[] = "T:{$com['total_timbres']}";
                                                    if ($com['total_sucursales'] > 0) $detalle[] = "S:{$com['total_sucursales']}";
                                                    if (!empty($detalle)):
                                                    ?>
                                                        <br><small class="text-muted"><?php echo implode(' ', $detalle); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end"><?php echo formatearMoneda($com['total_sin_iva']); ?></td>
                                                <td class="text-end"><?php echo formatearMoneda($com['total_con_iva']); ?></td>
                                                <td class="text-end">
                                                    <span class="comision-positive">
                                                        <?php echo formatearMoneda($com['comision']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatearFecha($com['ultima_activacion']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <?php if (!$es_distribuidor): ?>
                                                <th colspan="2" class="text-end">Totales:</th>
                                            <?php else: ?>
                                                <th colspan="1" class="text-end">Totales:</th>
                                            <?php endif; ?>
                                            <th></th>
                                            <th></th>
                                            <th class="text-center"><?php echo $totales_generales['total_activaciones']; ?></th>
                                            <th class="text-end"><?php echo formatearMoneda($totales_generales['total_ventas_sin_iva']); ?></th>
                                            <th class="text-end"><?php echo formatearMoneda($totales_generales['total_ventas_con_iva']); ?></th>
                                            <th class="text-end text-success"><?php echo formatearMoneda($totales_generales['total_comision']); ?></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Versión Móvil - Tarjetas -->
                            <div class="comisiones-cards-movil p-3">
                                <?php foreach ($comisiones as $com): ?>
                                    <div class="comision-card-movil">
                                        <?php if (!$es_distribuidor): ?>
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($com['nombre_distribuidor']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo $com['numero_control']; ?></small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($com['nombre_empresa']); ?></div>
                                                <span class="badge-plan <?php echo $com['plan']; ?> mt-1">
                                                    <?php echo getPlanNombre($com['plan']); ?>
                                                </span>
                                            </div>
                                            <div class="text-end">
                                                <div class="comision-positive fw-bold"><?php echo formatearMoneda($com['comision']); ?></div>
                                                <small class="text-muted">Comisión</small>
                                            </div>
                                        </div>
                                        
                                        <div class="row g-2 mt-2">
                                            <div class="col-6">
                                                <div class="bg-light rounded-3 p-2 text-center">
                                                    <small class="text-muted d-block">Activaciones</small>
                                                    <strong><?php echo $com['total_activaciones']; ?></strong>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="bg-light rounded-3 p-2 text-center">
                                                    <small class="text-muted d-block">Ventas sin IVA</small>
                                                    <strong><?php echo formatearMoneda($com['total_sin_iva']); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-2 pt-2 border-top">
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Última activación: <?php echo formatearFecha($com['ultima_activacion']); ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Mensaje cuando no hay datos -->
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <div class="bg-light rounded-circle p-4 d-inline-block mb-4">
                                <i class="fas fa-chart-line fa-3x text-muted"></i>
                            </div>
                            <h5 class="text-muted mb-3">No hay comisiones para mostrar</h5>
                            <p class="text-muted mb-0">
                                No se encontraron activaciones en el período seleccionado.
                            </p>
                            <?php if ($filtro_distribuidor != 'todos' || $filtro_plan != 'todos'): ?>
                                <p class="text-muted mt-3">
                                    <small>Intentá cambiar los filtros para ver más resultados</small>
                                </p>
                                <a href="comisiones.php<?php echo $es_distribuidor ? '' : '?distribuidor=todos'; ?>" class="btn btn-filter mt-3">
                                    <i class="fas fa-undo me-2"></i>Limpiar Filtros
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal para ingresar/editar datos bancarios -->
    <?php if ($es_distribuidor): ?>
    <div class="modal fade" id="modalBanco" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; border: none;">
                    <h5 class="modal-title">
                        <i class="fas fa-whatsapp me-2"></i>
                        <span id="modalTitle">Llamada Cobrar - Datos Bancarios</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formBanco" onsubmit="return validarFormularioBanco()">
                    <div class="modal-body p-4">
                        <?php if (isset($mensaje_error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo $mensaje_error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($mensaje_exito)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo $mensaje_exito; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mb-4">
                            <div class="bg-light rounded-circle p-3 d-inline-block mb-3">
                                <i class="fas fa-hand-holding-usd fa-3x" style="color: #9b59b6;"></i>
                            </div>
                            <h6 class="mt-2 fw-bold">Comisión disponible: <?php echo formatearMoneda($totales_generales['total_comision']); ?></h6>
                            <p class="text-muted small" id="modalDescription">
                                Ingresa tus datos bancarios para procesar el pago
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="banco" class="form-label fw-bold">
                                <i class="fas fa-university me-1" style="color: #9b59b6;"></i>
                                Banco
                            </label>
                            <input type="text" class="form-control rounded-pill" id="banco" name="banco" 
                                   placeholder="Ej. BBVA, Banamex, Santander" 
                                   value="<?php echo htmlspecialchars($distribuidor_info['banco'] ?? ''); ?>"
                                   required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="numero_cuenta" class="form-label fw-bold">
                                <i class="fas fa-credit-card me-1" style="color: #9b59b6;"></i>
                                Número de Cuenta / CLABE
                            </label>
                            <input type="text" class="form-control rounded-pill" id="numero_cuenta" name="numero_cuenta" 
                                   placeholder="Ej. 123456789012345678" 
                                   value="<?php echo htmlspecialchars($distribuidor_info['numero_cuenta'] ?? ''); ?>"
                                   required maxlength="18" minlength="10"
                                   onkeypress="return soloNumeros(event)">
                            <small class="text-muted">Solo números, mínimo 10 dígitos</small>
                        </div>
                        
                        <div class="alert alert-info rounded-3" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            <small>Tu información bancaria será utilizada únicamente para realizar los pagos de tus comisiones.</small>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pb-4 px-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancelar
                        </button>
                        <button type="submit" name="guardar_banco" class="btn btn-purple rounded-pill px-4">
                            <i class="fas fa-save me-1"></i><span id="btnGuardarTexto">Guardar Datos</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            <?php if (!empty($comisiones)): ?>
                $('#tablaComisiones').DataTable({
                    responsive: true,
                    language: {
                        "decimal": "",
                        "emptyTable": "No hay datos disponibles",
                        "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
                        "infoEmpty": "Mostrando 0 a 0 de 0 registros",
                        "infoFiltered": "(filtrado de _MAX_ registros totales)",
                        "infoPostFix": "",
                        "thousands": ",",
                        "lengthMenu": "Mostrar _MENU_ registros",
                        "loadingRecords": "Cargando...",
                        "processing": "Procesando...",
                        "search": "Buscar:",
                        "zeroRecords": "No se encontraron registros",
                        "paginate": {
                            "first": "Primero",
                            "last": "Último",
                            "next": "Siguiente",
                            "previous": "Anterior"
                        }
                    },
                    order: [
                        [<?php echo $es_distribuidor ? 5 : 6; ?>, 'desc']
                    ],
                    pageLength: 25,
                    lengthMenu: [
                        [10, 25, 50, -1],
                        [10, 25, 50, "Todos"]
                    ]
                });

                <?php if (!$es_distribuidor && !empty($totales_por_distribuidor)): ?>
                    $('#tablaDistribuidores').DataTable({
                        responsive: true,
                        language: {
                            "decimal": "",
                            "emptyTable": "No hay datos disponibles",
                            "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
                            "infoEmpty": "Mostrando 0 a 0 de 0 registros",
                            "infoFiltered": "(filtrado de _MAX_ registros totales)",
                            "infoPostFix": "",
                            "thousands": ",",
                            "lengthMenu": "Mostrar _MENU_ registros",
                            "loadingRecords": "Cargando...",
                            "processing": "Procesando...",
                            "search": "Buscar:",
                            "zeroRecords": "No se encontraron registros",
                            "paginate": {
                                "first": "Primero",
                                "last": "Último",
                                "next": "Siguiente",
                                "previous": "Anterior"
                            }
                        },
                        order: [
                            [6, 'desc']
                        ],
                        pageLength: 10
                    });
                <?php endif; ?>
            <?php endif; ?>
        });

        function exportarExcel() {
            const table = document.getElementById('tablaComisiones');
            if (!table) {
                Swal.fire({
                    title: 'Error',
                    text: 'No hay datos para exportar',
                    icon: 'error',
                    confirmButtonColor: '#27ae60'
                });
                return;
            }

            let csv = [];
            let rows = table.querySelectorAll('tr');

            for (let i = 0; i < rows.length; i++) {
                let row = [];
                let cols = rows[i].querySelectorAll('td, th');

                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }

                csv.push(row.join(','));
            }

            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);

            link.setAttribute('href', url);
            link.setAttribute('download', 'comisiones_' + new Date().toISOString().slice(0, 10) + '.csv');
            link.style.visibility = 'hidden';

            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            Swal.fire({
                title: 'Exportado',
                text: 'El archivo se ha descargado correctamente',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
        }

        function abrirModalBanco(esEdicion = false) {
            var modal = new bootstrap.Modal(document.getElementById('modalBanco'));
            
            if (esEdicion) {
                document.getElementById('modalTitle').textContent = 'Editar Datos Bancarios';
                document.getElementById('modalDescription').textContent = 'Actualiza tus datos bancarios para recibir tus pagos';
                document.getElementById('btnGuardarTexto').textContent = 'Actualizar Datos';
            } else {
                document.getElementById('modalTitle').textContent = 'Llamada Cobrar - Datos Bancarios';
                document.getElementById('modalDescription').textContent = 'Ingresa tus datos bancarios para procesar el pago';
                document.getElementById('btnGuardarTexto').textContent = 'Guardar Datos';
            }
            
            modal.show();
        }

        function solicitarPago() {
            Swal.fire({
                title: '¿Solicitar pago?',
                text: 'Se generará un mensaje para solicitar el cobro de tu comisión',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#9b59b6',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, solicitar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Solicitud enviada',
                        text: 'Tu solicitud de pago ha sido enviada',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });
        }

        function soloNumeros(e) {
            var charCode = (e.which) ? e.which : e.keyCode;
            if (charCode > 31 && (charCode < 48 || charCode > 57)) {
                return false;
            }
            return true;
        }

        function validarFormularioBanco() {
            var banco = document.getElementById('banco').value.trim();
            var numero_cuenta = document.getElementById('numero_cuenta').value.trim();
            
            if (banco === '') {
                Swal.fire({
                    title: 'Error',
                    text: 'Por favor ingresa el nombre del banco',
                    icon: 'error',
                    confirmButtonColor: '#9b59b6'
                });
                return false;
            }
            
            if (numero_cuenta === '') {
                Swal.fire({
                    title: 'Error',
                    text: 'Por favor ingresa el número de cuenta',
                    icon: 'error',
                    confirmButtonColor: '#9b59b6'
                });
                return false;
            } else if (numero_cuenta.length < 10 || numero_cuenta.length > 18) {
                Swal.fire({
                    title: 'Error',
                    text: 'El número de cuenta debe tener entre 10 y 18 dígitos',
                    icon: 'error',
                    confirmButtonColor: '#9b59b6'
                });
                return false;
            }
            
            return true;
        }

        <?php if (isset($mensaje_exito)): ?>
            Swal.fire({
                title: '¡Éxito!',
                text: '<?php echo $mensaje_exito; ?>',
                icon: 'success',
                timer: 3000,
                showConfirmButton: false,
                timerProgressBar: true
            });
        <?php endif; ?>

        <?php if (isset($mensaje_error) && !isset($mensaje_exito)): ?>
            Swal.fire({
                title: 'Error',
                text: '<?php echo $mensaje_error; ?>',
                icon: 'error',
                confirmButtonColor: '#9b59b6'
            });
        <?php endif; ?>

        // Sidebar functionality
        (function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (!sidebar || !sidebarToggle || !sidebarBackdrop) return;

            function openSidebar() {
                sidebar.classList.add('show');
                sidebarBackdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            function closeSidebar() {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                document.body.style.overflow = '';
            }

            function toggleSidebar() {
                if (sidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }

            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarBackdrop.addEventListener('click', closeSidebar);

            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth < 768) {
                        closeSidebar();
                    }
                });
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebar();
                }
            });
        })();
    </script>
</body>

</html>