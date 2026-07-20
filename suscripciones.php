<?php
session_start();

// Verificar si el usuario está logueado y es admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

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

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

$mensaje = '';
$tipo_mensaje = '';

// Conectar a la base de datos de la empresa
try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Verificar y actualizar la estructura de la tabla sistema_config
    $sql_check_columns = "SHOW COLUMNS FROM sistema_config";
    $result_columns = $conn->query($sql_check_columns);
    $existing_columns = [];
    while ($row = $result_columns->fetch(PDO::FETCH_ASSOC)) {
        $existing_columns[] = $row['Field'];
    }

    // Obtener configuración actual
    $sql_config = "SELECT * FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $config = $result_config->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        // Insertar configuración por defecto si no existe
        $sql_insert = "INSERT INTO sistema_config (nombre_empresa) VALUES ('Mi Empresa')";
        $conn->exec($sql_insert);
        $config = [
            'nombre_empresa' => 'Mi Empresa',
            'rfc' => '',
            'telefono' => '',
            'email' => '',
            'direccion' => '',
            'logo' => '',
            'iva' => '16.00',
            'moneda' => 'MXN'
        ];
        // Recargar la configuración
        $result_config = $conn->query($sql_config);
        $config = $result_config->fetch(PDO::FETCH_ASSOC);
    }

    // Función segura para obtener valores de configuración
    function getConfigValue($config, $key, $default = '')
    {
        return isset($config[$key]) ? $config[$key] : $default;
    }

    // Procesar actualización de configuración general
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_config'])) {
        $nombre_empresa = $_POST['nombre_empresa'];
        $rfc = $_POST['rfc'];
        $telefono = $_POST['telefono'];
        $email = $_POST['email'];
        $direccion = $_POST['direccion'];
        $iva = floatval($_POST['iva']);
        $moneda = $_POST['moneda'];

        $sql_update = "UPDATE sistema_config SET 
                      nombre_empresa = ?,
                      rfc = ?,
                      telefono = ?,
                      email = ?,
                      direccion = ?,
                      iva = ?,
                      moneda = ?";

        $stmt = $conn->prepare($sql_update);
        $stmt->execute([$nombre_empresa, $rfc, $telefono, $email, $direccion, $iva, $moneda]);

        if ($stmt->rowCount() >= 0) {
            $mensaje = "Configuración actualizada correctamente";
            $tipo_mensaje = "success";
            // Actualizar variable de configuración
            $config['nombre_empresa'] = $nombre_empresa;
            $config['rfc'] = $rfc;
            $config['telefono'] = $telefono;
            $config['email'] = $email;
            $config['direccion'] = $direccion;
            $config['iva'] = $iva;
            $config['moneda'] = $moneda;
        } else {
            $mensaje = "Error al actualizar la configuración";
            $tipo_mensaje = "danger";
        }
        $stmt = null;
    }

    // Obtener estadísticas del sistema
    $sql_stats = "
        SELECT 
            (SELECT COUNT(*) FROM productos WHERE activo = 1) as total_productos,
            (SELECT COUNT(*) FROM clientes WHERE activo = 1) as total_clientes,
            (SELECT COUNT(*) FROM usuarios WHERE activo = 1) as total_usuarios,
            (SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()) as ventas_hoy,
            (SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo) as productos_bajo_stock,
            (SELECT COUNT(*) FROM sucursales WHERE activo = 1) as total_sucursales
    ";
    $result_stats = $conn->query($sql_stats);
    $estadisticas = $result_stats->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Definir los planes para el resumen
$planes = [
    'basico' => [
        'nombre' => 'Básico',
        'precio_mensual' => 299,
        'precio_anual' => 239,
        'usuarios' => 1,
        'cajas' => 1,
        'productos' => 100
    ],
    'profesional' => [
        'nombre' => 'Profesional',
        'precio_mensual' => 599,
        'precio_anual' => 479,
        'usuarios' => 4,
        'cajas' => 2,
        'productos' => 500
    ],
    'empresarial' => [
        'nombre' => 'Empresarial',
        'precio_mensual' => 999,
        'precio_anual' => 799,
        'usuarios' => 6,
        'cajas' => 3,
        'productos' => 500,
        'sucursales' => 1
    ],
    'plus' => [
        'nombre' => 'Empresarial Plus',
        'precio_mensual' => 1499,
        'precio_anual' => 1199,
        'usuarios' => 10,
        'cajas' => 10,
        'productos' => 'Ilimitados',
        'sucursales' => 3,
        'timbres' => 500
    ]
];

// Plan seleccionado (por defecto empresarial)
$plan_seleccionado = isset($_GET['plan']) ? $_GET['plan'] : 'empresarial';
$plan_data = isset($planes[$plan_seleccionado]) ? $planes[$plan_seleccionado] : $planes['empresarial'];

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planes - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo getConfigValue($config, 'color_primario', '#27ae60'); ?>;
            --secondary-color: <?php echo getConfigValue($config, 'color_secundario', '#2ecc71'); ?>;
            --bg: #f8f9fa;
            --border: #e9ecef;
            --border2: #dee2e6;
            --ink: #0f172a;
            --ink2: #334155;
            --ink3: #64748b;
            --ink4: #94a3b8;
            --green: #27ae60;
            --green-d: #1e8449;
            --green-mid: #d5f5e3;
            --green-light: #eafaf1;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        /* ============================================================
           PLANES SECTION
           ============================================================ */
        .plans-section {
            padding: 2rem 0;
            background: #f8f9fa;
            min-height: calc(100vh - 76px);
        }

        .plans-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1rem;
            width: 100%;
        }

        /* Pricing Toggle */
        .pricing-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: center;
            margin: 24px 0 32px;
            font-size: 14px;
            font-weight: 600;
            color: var(--ink3);
        }

        .tog-track {
            width: 48px;
            height: 26px;
            border-radius: 100px;
            background: var(--primary-color);
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .tog-track:hover {
            opacity: 0.8;
        }

        .tog-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            position: absolute;
            top: 3px;
            left: 3px;
            transition: transform 0.3s ease;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }

        .tog-track.annual .tog-thumb {
            transform: translateX(22px);
        }

        .save-badge {
            background: var(--green-mid);
            color: var(--green-d);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            align-items: stretch;
        }

        /* Cada tarjeta de plan */
        .plan {
            background: #fff;
            border-radius: 16px;
            padding: 1.8rem 1.2rem;
            box-shadow: 0 6px 20px rgba(0,0,0,0.05);
            transition: 0.3s ease;
            display: flex;
            flex-direction: column;
            text-align: center;
            border: 1px solid #e9ecef;
            height: 100%;
            cursor: pointer;
            position: relative;
        }
        .plan:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.10);
        }

        .plan.selected {
            border: 3px solid var(--primary-color);
            background: #f0fdf4;
            box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
        }

        .plan.selected .plan-name {
            color: var(--primary-color);
        }

        .plan-check {
            display: none;
            position: absolute;
            top: -12px;
            right: -12px;
            background: var(--primary-color);
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
        }

        .plan.selected .plan-check {
            display: flex;
        }

        /* Plan destacado */
        .plan.popular {
            border: 2px solid var(--primary-color);
            position: relative;
        }

        .plan.selected.popular {
            border: 3px solid var(--primary-color);
        }

        .popular-badge {
            background: var(--primary-color);
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.2rem 1rem;
            border-radius: 30px;
            display: inline-block;
            margin-top: -2.4rem;
            margin-bottom: 0.6rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .plan-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        .plan-price {
            font-size: 2.2rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        .plan-price sup {
            font-size: 1rem;
            font-weight: 600;
            top: -0.6rem;
            margin-right: 2px;
        }
        .plan-period {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.8rem;
        }

        .plan-lis {
            list-style: none;
            padding: 0;
            margin: 0 0 1.2rem 0;
            text-align: left;
            flex: 1;
        }
        .plan-section-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #94a3b8;
            margin-top: 0.6rem;
            margin-bottom: 0.2rem;
            border-bottom: 1px dashed #e2e8f0;
            padding-bottom: 0.2rem;
        }
        .plan-section-label:first-of-type {
            margin-top: 0;
        }
        .plan-li {
            font-size: 0.85rem;
            color: #334155;
            padding: 0.2rem 0 0.2rem 1.2rem;
            position: relative;
        }
        .plan-li::before {
            content: "✓";
            color: var(--primary-color);
            font-weight: 700;
            position: absolute;
            left: 0;
        }

        /* ============================================================
           BOTONES DE SELECCIÓN MEJORADOS
           ============================================================ */
        .plan-select-btn {
            width: 100%;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }

        .plan-select-btn-outline {
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .plan-select-btn-outline:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        .plan-select-btn-filled {
            background: var(--primary-color);
            color: white;
            border: 2px solid var(--primary-color);
            box-shadow: 0 3px 12px rgba(39, 174, 96, 0.25);
        }

        .plan-select-btn-filled:hover {
            background: var(--green-d);
            border-color: var(--green-d);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
        }

        .plan-select-btn-filled i {
            font-size: 0.9rem;
        }

        .plan-select-btn-outline i {
            font-size: 0.9rem;
        }

        .plan.selected .plan-select-btn-outline {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .plan.selected .plan-select-btn-filled {
            background: var(--green-d);
            border-color: var(--green-d);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4);
        }

        /* ============================================================
           RESUMEN DEL PEDIDO
           ============================================================ */
        .order-summary {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
            margin-top: 32px;
            display: none;
        }

        .order-summary.visible {
            display: block;
            animation: slideDown 0.4s ease forwards;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .order-summary .summary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f0f0;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .order-summary .summary-header h4 {
            margin: 0;
            font-weight: 700;
            color: #0f172a;
        }

        .order-summary .summary-header .badge-status {
            background: var(--green-mid);
            color: var(--green-d);
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row .label {
            color: #64748b;
        }

        .summary-row .value {
            font-weight: 600;
            color: #0f172a;
        }

        .summary-row.total {
            padding-top: 16px;
            margin-top: 8px;
            border-top: 2px solid #e9ecef;
            font-size: 18px;
        }

        .summary-row.total .label {
            font-weight: 700;
            color: #0f172a;
        }

        .summary-row.total .value {
            font-size: 20px;
            color: var(--primary-color);
        }

        .summary-row.ahorro {
            border-bottom: none;
            padding-bottom: 4px;
        }

        .summary-row.ahorro .value {
            color: #f59e0b;
        }

        .summary-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .summary-actions .btn {
            border-radius: 50px;
            padding: 10px 28px;
            font-weight: 600;
            flex: 1;
            min-width: 140px;
        }

        .btn-pay {
            background: var(--primary-color);
            color: white;
            border: none;
            transition: all 0.3s;
        }

        .btn-pay:hover {
            background: var(--green-d);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        .btn-pay i {
            margin-right: 8px;
        }

        .btn-cancel {
            background: transparent;
            color: #64748b;
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }

        .btn-cancel:hover {
            border-color: #dc3545;
            color: #dc3545;
        }

        /* Efecto de revelado */
        .reveal {
            opacity: 0;
            transform: translateY(20px);
            animation: fadeUp 0.7s ease forwards;
        }
        .reveal-d1 { animation-delay: 0.1s; }
        .reveal-d2 { animation-delay: 0.2s; }
        .reveal-d3 { animation-delay: 0.3s; }
        .reveal-d4 { animation-delay: 0.4s; }

        @keyframes fadeUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ============================================================
           PAYMENT SECTION (Acordeón)
           ============================================================ */
        .payment-section {
            padding: 64px 24px;
            background: #f8f9fa;
        }

        .payment-inner {
            max-width: 960px;
            margin: 0 auto;
        }

        .accordion-item {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .accordion-item .accordion-button {
            padding: 20px 24px;
            background: white;
            font-weight: 600;
            font-size: 16px;
            box-shadow: none;
        }

        .accordion-item .accordion-button:not(.collapsed) {
            background: white;
            color: var(--primary-color);
            box-shadow: none;
        }

        .accordion-item .accordion-button:focus {
            box-shadow: none;
            border-color: transparent;
        }

        .accordion-item .accordion-body {
            padding: 24px;
            background: #fafafa;
            border-top: 1px solid #e9ecef;
        }

        .accordion-item .accordion-button .badge {
            font-size: 10px;
            font-weight: 600;
        }

        .payment-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .payment-features li {
            padding: 6px 0;
            font-size: 14px;
            color: #334155;
        }

        .payment-features li i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        .payment-icons {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .payment-icons i {
            font-size: 3rem;
        }

        .bank-tags {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .bank-tags span {
            background: #e9ecef;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
        }

        .payment-note {
            margin-top: 24px;
            padding: 20px 24px;
            background: white;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }

        .payment-note .note-icon {
            color: var(--primary-color);
            font-size: 1.2rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Modal de carga */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-modal {
            background: white;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            max-width: 400px;
            width: 90%;
        }

        .loading-modal .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-modal h5 {
            color: #0f172a;
            margin-bottom: 8px;
        }

        .loading-modal p {
            color: #64748b;
            font-size: 14px;
            margin: 0;
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 767.98px) {
            .plans-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
                margin: 0 auto;
            }
            .plans-section {
                min-height: auto;
                padding: 2rem 0;
            }
            .pricing-toggle {
                flex-wrap: wrap;
            }
            .payment-section {
                padding: 40px 16px;
            }
            .payment-icons i {
                font-size: 2.5rem;
            }
            .accordion-item .accordion-button {
                font-size: 14px;
                padding: 16px;
            }
            .accordion-item .accordion-body {
                padding: 16px;
            }
            .order-summary {
                padding: 16px;
            }
            .summary-actions .btn {
                min-width: 100%;
            }
            .summary-row {
                font-size: 13px;
            }
            .order-summary .summary-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
        }

        @media (min-width: 768px) and (max-width: 991.98px) {
            .plans-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <?php if (!empty($config['logo']) && file_exists($config['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($config['logo']); ?>"
                         alt="Logo"
                         style="height: 40px; max-width: 150px; object-fit: contain; margin-right: 10px;">
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
            </a>

            <div class="d-flex align-items-center">
                <!-- Botón Regresar a Inicio -->
                <a href="Inicio" class="btn btn-light btn-sm me-3" style="border-radius: 20px; padding: 5px 15px;">
                    <i class="fas fa-arrow-left me-1"></i> Inicio
                </a>

                <!-- Menú de usuario -->
                <div class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text">
                                    <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                                </span></li>
                            <li><span class="dropdown-item-text">
                                    <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                                </span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="Suscripciones">
                                    <i class="fas fa-crown me-2"></i>Suscripciones
                                </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
                                </a></li>
                        </ul>
                    </li>
                </div>
            </div>
        </div>
    </nav>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-modal">
            <div class="spinner"></div>
            <h5>Generando link de pago</h5>
            <p>Por favor espera un momento...</p>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <main>
        <!-- SECCIÓN DE PLANES -->
        <div class="plans-section">
            <div class="plans-inner">

                <!-- Título de la sección -->
                <h2 class="mb-2 text-center" style="color: #0f172a; font-weight: 700;">
                    <i class="fas fa-rocket me-2" style="color: var(--primary-color);"></i>
                    Elige tu plan
                </h2>
                <p style="text-align: center; color: #64748b; margin-bottom: 0; font-size: 15px;">
                    Sin permanencia. Cancela cuando quieras.
                </p>

                <!-- Toggle Mensual / Anual -->
                <div class="pricing-toggle">
                    <span style="font-weight: 600;">Mensual</span>
                    <div class="tog-track" onclick="togglePricing()" id="togTrack">
                        <div class="tog-thumb"></div>
                    </div>
                    <span style="font-weight: 600;">Anual <span class="save-badge">–20%</span></span>
                </div>

                <div class="plans-grid">

                    <!-- BÁSICO -->
                    <div class="plan reveal <?php echo $plan_seleccionado == 'basico' ? 'selected' : ''; ?>" data-plan="basico" onclick="selectPlan('basico')">
                        <div class="plan-check"><i class="fas fa-check"></i></div>
                        <div class="plan-name">Básico</div>
                        <div class="plan-price"><sup>$</sup><span class="pv" data-m="299" data-a="239">299</span></div>
                        <div class="plan-period">MXN/mes · 1 usuario</div>
                        <ul class="plan-lis">
                            <div class="plan-section-label">Punto de Venta</div>
                            <li class="plan-li">1 caja registradora</li>
                            <li class="plan-li">100 productos</li>
                            <li class="plan-li">Pago en efectivo</li>
                        </ul>
                        <button class="plan-select-btn plan-select-btn-outline" onclick="event.stopPropagation(); selectPlan('basico')">
                            <i class="fas fa-arrow-right"></i> Seleccionar
                        </button>
                    </div>

                    <!-- PROFESIONAL -->
                    <div class="plan reveal reveal-d1 <?php echo $plan_seleccionado == 'profesional' ? 'selected' : ''; ?>" data-plan="profesional" onclick="selectPlan('profesional')">
                        <div class="plan-check"><i class="fas fa-check"></i></div>
                        <div class="plan-name">Profesional</div>
                        <div class="plan-price"><sup>$</sup><span class="pv" data-m="599" data-a="479">599</span></div>
                        <div class="plan-period">MXN/mes · 4 usuarios</div>
                        <ul class="plan-lis">
                            <div class="plan-section-label">Punto de Venta</div>
                            <li class="plan-li">2 cajas registradoras</li>
                            <li class="plan-li">500 productos</li>
                            <li class="plan-li">Pago en efectivo</li>
                        </ul>
                        <button class="plan-select-btn plan-select-btn-outline" onclick="event.stopPropagation(); selectPlan('profesional')">
                            <i class="fas fa-arrow-right"></i> Seleccionar
                        </button>
                    </div>

                    <!-- EMPRESARIAL (destacado) -->
                    <div class="plan popular reveal reveal-d2 <?php echo $plan_seleccionado == 'empresarial' ? 'selected' : ''; ?>" data-plan="empresarial" onclick="selectPlan('empresarial')">
                        <div class="plan-check"><i class="fas fa-check"></i></div>
                        <div class="popular-badge">⚡ Más popular</div>
                        <div class="plan-name">Empresarial</div>
                        <div class="plan-price"><sup>$</sup><span class="pv" data-m="999" data-a="799">999</span></div>
                        <div class="plan-period">MXN/mes · 6 usuarios</div>
                        <ul class="plan-lis">
                            <div class="plan-section-label">Punto de Venta</div>
                            <li class="plan-li">3 cajas registradoras</li>
                            <li class="plan-li">1 sucursal</li>
                            <li class="plan-li">500 productos</li>
                            <div class="plan-section-label">Pagos</div>
                            <li class="plan-li">Pasarela de pago</li>
                            <li class="plan-li">SPEI / PayPal</li>
                        </ul>
                        <button class="plan-select-btn plan-select-btn-filled" onclick="event.stopPropagation(); selectPlan('empresarial')">
                            <i class="fas fa-star"></i> Seleccionar
                        </button>
                    </div>

                    <!-- EMPRESARIAL PLUS -->
                    <div class="plan reveal reveal-d3 <?php echo $plan_seleccionado == 'plus' ? 'selected' : ''; ?>" data-plan="plus" onclick="selectPlan('plus')">
                        <div class="plan-check"><i class="fas fa-check"></i></div>
                        <div class="plan-name">Empresarial Plus</div>
                        <div class="plan-price" style="font-size:32px"><sup style="font-size:16px">$</sup><span class="pv" data-m="1499" data-a="1199">1,499</span></div>
                        <div class="plan-period">MXN/mes · 10 usuarios</div>
                        <ul class="plan-lis">
                            <div class="plan-section-label">Punto de Venta</div>
                            <li class="plan-li">10 cajas registradoras</li>
                            <li class="plan-li">3 sucursales</li>
                            <li class="plan-li">Productos ilimitados</li>
                            <div class="plan-section-label">Pagos</div>
                            <li class="plan-li">Pasarela de pago</li>
                            <li class="plan-li">SPEI / PayPal</li>
                            <li class="plan-li">Tarjeta de crédito</li>
                            <div class="plan-section-label">Facturación</div>
                            <li class="plan-li">500 CFDI / Timbres</li>
                        </ul>
                        <button class="plan-select-btn plan-select-btn-outline" onclick="event.stopPropagation(); selectPlan('plus')">
                            <i class="fas fa-arrow-right"></i> Seleccionar
                        </button>
                    </div>

                </div><!-- /plans-grid -->

                <!-- RESUMEN DEL PEDIDO -->
                <div class="order-summary visible" id="orderSummary">
                    <div class="summary-header">
                        <h4><i class="fas fa-shopping-cart me-2" style="color: var(--primary-color);"></i>Resumen del pedido</h4>
                        <span class="badge-status"><i class="fas fa-check-circle me-1"></i>Plan seleccionado</span>
                    </div>

                    <div class="summary-row">
                        <span class="label"><i class="fas fa-tag me-2" style="color: var(--primary-color);"></i>Plan</span>
                        <span class="value" id="summaryPlan"><?php echo $plan_data['nombre']; ?></span>
                    </div>

                    <div class="summary-row">
                        <span class="label"><i class="fas fa-users me-2" style="color: var(--primary-color);"></i>Usuarios</span>
                        <span class="value" id="summaryUsuarios"><?php echo $plan_data['usuarios']; ?></span>
                    </div>

                    <div class="summary-row">
                        <span class="label"><i class="fas fa-cash-register me-2" style="color: var(--primary-color);"></i>Cajas registradoras</span>
                        <span class="value" id="summaryCajas"><?php echo $plan_data['cajas']; ?></span>
                    </div>

                    <div class="summary-row">
                        <span class="label"><i class="fas fa-boxes me-2" style="color: var(--primary-color);"></i>Productos</span>
                        <span class="value" id="summaryProductos"><?php echo $plan_data['productos']; ?></span>
                    </div>

                    <?php if (isset($plan_data['sucursales'])): ?>
                    <div class="summary-row">
                        <span class="label"><i class="fas fa-store me-2" style="color: var(--primary-color);"></i>Sucursales</span>
                        <span class="value" id="summarySucursales"><?php echo $plan_data['sucursales']; ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($plan_data['timbres'])): ?>
                    <div class="summary-row">
                        <span class="label"><i class="fas fa-file-invoice me-2" style="color: var(--primary-color);"></i>CFDI / Timbres</span>
                        <span class="value" id="summaryTimbres"><?php echo $plan_data['timbres']; ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="summary-row" style="border-bottom: none; padding-bottom: 4px;">
                        <span class="label"><i class="fas fa-calendar me-2" style="color: var(--primary-color);"></i>Periodo</span>
                        <span class="value" id="summaryPeriodo">Mensual</span>
                    </div>

                    <!-- Fila de ahorro (se muestra solo en anual) -->
                    <div class="summary-row ahorro" id="summaryAhorroRow" style="display: none;">
                        <span class="label"><i class="fas fa-gift me-2" style="color: #f59e0b;"></i>Ahorro</span>
                        <span class="value" id="summaryAhorro">-20%</span>
                    </div>

                    <div class="summary-row total">
                        <span class="label"><i class="fas fa-dollar-sign me-2" style="color: var(--primary-color);"></i>Total a pagar</span>
                        <span class="value" id="summaryTotal">$<?php echo number_format($plan_data['precio_mensual'], 2); ?> MXN</span>
                    </div>

                    <div class="summary-actions">
                        <button class="btn btn-pay" onclick="generarPago()">
                            <i class="fas fa-credit-card"></i> Proceder al pago
                        </button>
                        <button class="btn btn-cancel" onclick="cancelarSeleccion()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </div>

            </div><!-- /plans-inner -->
        </div><!-- /plans-section -->

        <!-- SECCIÓN DE FORMAS DE PAGO (Accordion) -->
        <section class="payment-section">
            <div class="payment-inner">
                <div class="text-center mb-4 reveal">
                    <span class="s-eyebrow" style="display: inline-block; background: #d5f5e3; color: #1e8449; padding: 4px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                        <i class="fas fa-credit-card me-1"></i> Métodos de Pago
                    </span>
                    <h2 style="font-family: 'Segoe UI', sans-serif; font-size: clamp(28px, 4vw, 40px); font-weight: 900; color: #0f172a; margin-bottom: 8px;">
                        Formas de <span style="color: var(--primary-color);">pago</span>
                    </h2>
                    <p style="color: #64748b; font-size: 15px;">Elige la opción que mejor se adapte a tu negocio</p>
                </div>

                <div class="accordion" id="paymentAccordion">
                    <!-- Tarjeta de Crédito / Débito -->
                    <div class="accordion-item reveal">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCard" aria-expanded="true" aria-controls="collapseCard">
                                <i class="fas fa-credit-card me-3" style="color: var(--primary-color); font-size: 1.2rem;"></i>
                                Tarjeta
                                <span class="badge ms-2" style="background: var(--primary-color);">Recomendado</span>
                            </button>
                        </h2>
                        <div id="collapseCard" class="accordion-collapse collapse show" data-bs-parent="#paymentAccordion">
                            <div class="accordion-body">
                                <div class="row align-items-center">
                                    <div class="col-md-7">
                                        <p style="color: #334155; font-size: 14px; line-height: 1.8; margin-bottom: 16px;">
                                            <i class="fas fa-lock me-2" style="color: var(--primary-color);"></i>
                                            Pago seguro con tarjeta de crédito o débito. Aceptamos todas las tarjetas principales.
                                        </p>
                                        <ul class="payment-features">
                                            <li><i class="fas fa-check-circle"></i> Transacciones encriptadas SSL</li>
                                            <li><i class="fas fa-check-circle"></i> Aprobación en segundos</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-5 text-center">
                                        <div class="payment-icons">
                                            <i class="fab fa-cc-visa" style="color: #1a1f71;"></i>
                                            <i class="fab fa-cc-mastercard" style="color: #eb001b;"></i>
                                            <i class="fab fa-cc-amex" style="color: #006fcf;"></i>
                                        </div>
                                        <button class="btn btn-primary btn-sm" style="border-radius: 50px; padding: 8px 24px; background: var(--primary-color); border-color: var(--primary-color); font-weight: 600;" onclick="generarPago()">
                                            <i class="fas fa-credit-card me-1"></i> Pagar ahora
                                        </button>
                                        <p style="font-size: 11px; color: #94a3b8; margin-top: 8px;">
                                            <i class="fas fa-shield-alt me-1"></i> 100% seguro
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transferencia SPEI -->
                    <!-- Transferencia SPEI -->
<div class="accordion-item reveal reveal-d1">
    <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSPEI" aria-expanded="false" aria-controls="collapseSPEI">
            <i class="fas fa-university me-3" style="color: var(--primary-color); font-size: 1.2rem;"></i>
            Transferencia SPEI
            <span class="badge ms-2" style="background: #17a2b8;">Sin comisiones</span>
        </button>
    </h2>
    <div id="collapseSPEI" class="accordion-collapse collapse" data-bs-parent="#paymentAccordion">
        <div class="accordion-body">
            <div class="row">
                <div class="col-12">
                    <div class="card shadow-sm" style="border-radius: 12px; border: 1px solid #e9ecef;">
                        <div class="card-body p-4">
                            <h5 class="card-title mb-3" style="color: #0f172a; font-weight: 700;">
                                <i class="fas fa-building me-2" style="color: var(--primary-color);"></i>
                                Datos Bancarios
                            </h5>
                            
                            <div class="row g-3">
                                <!-- Banco -->
                                <div class="col-md-6">
                                    <div class="p-3" style="background: #f8f9fa; border-radius: 8px;">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-university me-1"></i> Banco
                                        </small>
                                        <strong style="font-size: 1.1rem;">BANAMEX</strong>
                                    </div>
                                </div>
                                
                                <!-- Beneficiario -->
                                <div class="col-md-6">
                                    <div class="p-3" style="background: #f8f9fa; border-radius: 8px;">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-user me-1"></i> Beneficiario
                                        </small>
                                        <strong style="font-size: 1.1rem;">OPERACIONES Y MULTISERVICIOS IDEAS SA DE CV</strong>
                                    </div>
                                </div>
                                
                                <!-- CLABE Interbancaria -->
                                <div class="col-md-6">
                                    <div class="p-3" style="background: #f8f9fa; border-radius: 8px;">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-hashtag me-1"></i> CLABE Interbancaria
                                        </small>
                                        <div class="d-flex align-items-center">
                                            <strong style="font-size: 1.1rem; letter-spacing: 1px;">002180702323009399</strong>
                                            <button class="btn btn-sm btn-outline-primary ms-2" onclick="copiarCLABE('002180702323009399')" style="border-radius: 20px;">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Número de Tarjeta -->
                                <div class="col-md-6">
                                    <div class="p-3" style="background: #f8f9fa; border-radius: 8px;">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-credit-card me-1"></i> Número de Tarjeta
                                        </small>
                                        <strong style="font-size: 1.1rem; letter-spacing: 1px;">5290 9303 0104 4786</strong>
                                    </div>
                                </div>
                            </div>

                            <!-- Información de Contacto -->
                            <hr class="my-3">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-phone me-2" style="color: var(--primary-color);"></i>
                                        <div>
                                            <small class="text-muted d-block">Teléfonos</small>
                                            <strong>55 4123 2305</strong> / <strong>55 4124 7213</strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fab fa-whatsapp me-2" style="color: #25D366;"></i>
                                        <div>
                                            <small class="text-muted d-block">WhatsApp</small>
                                            <strong>55 5925 7893</strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-globe me-2" style="color: var(--primary-color);"></i>
                                        <div>
                                            <small class="text-muted d-block">Sitio Web</small>
                                            <strong>WWW.GRUPOIDEAS.COM.MX</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Mensaje de ayuda -->
                            <div class="alert alert-success mt-3 mb-0" style="border-radius: 8px; background: #d5f5e3; border-color: #a9dfbf; color: #1e8449;">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Sin comisiones</strong> · Transferencia reflejada en 24-48 horas hábiles.
                                Envía tu comprobante a <strong>ventas@grupoideas.com.mx</strong> o por WhatsApp.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
                </div>
            </div>
        </section>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Datos de los planes
        const planesData = {
            basico: {
                nombre: 'Básico',
                precio_mensual: 299,
                precio_anual: 239,
                usuarios: 1,
                cajas: 1,
                productos: 100
            },
            profesional: {
                nombre: 'Profesional',
                precio_mensual: 599,
                precio_anual: 479,
                usuarios: 4,
                cajas: 2,
                productos: 500
            },
            empresarial: {
                nombre: 'Empresarial',
                precio_mensual: 999,
                precio_anual: 799,
                usuarios: 6,
                cajas: 3,
                productos: 500,
                sucursales: 1
            },
            plus: {
                nombre: 'Empresarial Plus',
                precio_mensual: 1499,
                precio_anual: 1199,
                usuarios: 10,
                cajas: 10,
                productos: 'Ilimitados',
                sucursales: 3,
                timbres: 500
            }
        };

        let planActual = '<?php echo $plan_seleccionado; ?>';
        let isAnnual = false;

        // Función para seleccionar un plan
        function selectPlan(planId) {
            planActual = planId;
            const plan = planesData[planId];
            
            // Actualizar clases seleccionadas
            document.querySelectorAll('.plan').forEach(el => {
                el.classList.remove('selected');
            });
            document.querySelector(`.plan[data-plan="${planId}"]`).classList.add('selected');
            
            // Actualizar resumen
            updateSummary(plan);
            
            // Mostrar resumen
            document.getElementById('orderSummary').classList.add('visible');
            
            // Scroll suave al resumen en móviles
            if (window.innerWidth < 768) {
                document.getElementById('orderSummary').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Función para actualizar el resumen
        function updateSummary(plan) {
            const isAnnual = document.getElementById('togTrack').classList.contains('annual');
            // Si es anual, mostramos el precio total del año (precio_anual * 12)
            // Si es mensual, mostramos el precio mensual
            const precio = isAnnual ? plan.precio_anual * 12 : plan.precio_mensual;
            const periodo = isAnnual ? 'Anual' : 'Mensual';
            
            document.getElementById('summaryPlan').textContent = plan.nombre;
            document.getElementById('summaryUsuarios').textContent = plan.usuarios;
            document.getElementById('summaryCajas').textContent = plan.cajas;
            document.getElementById('summaryProductos').textContent = plan.productos;
            document.getElementById('summaryPeriodo').textContent = periodo;
            document.getElementById('summaryTotal').textContent = `$${precio.toLocaleString()} MXN`;
            
            // Mostrar/ocultar fila de ahorro
            const ahorroRow = document.getElementById('summaryAhorroRow');
            if (ahorroRow) {
                if (isAnnual) {
                    ahorroRow.style.display = 'flex';
                    const ahorro = ((plan.precio_mensual - plan.precio_anual) / plan.precio_mensual * 100).toFixed(0);
                    const ahorroMonto = (plan.precio_mensual - plan.precio_anual) * 12;
                    document.getElementById('summaryAhorro').textContent = `-${ahorro}% ($${ahorroMonto.toLocaleString()} MXN/año)`;
                } else {
                    ahorroRow.style.display = 'none';
                }
            }
            
            // Mostrar/ocultar campos opcionales
            const sucursalesRow = document.getElementById('summarySucursales')?.parentElement;
            const timbresRow = document.getElementById('summaryTimbres')?.parentElement;
            
            if (sucursalesRow) {
                if (plan.sucursales) {
                    sucursalesRow.style.display = 'flex';
                    document.getElementById('summarySucursales').textContent = plan.sucursales;
                } else {
                    sucursalesRow.style.display = 'none';
                }
            }
            
            if (timbresRow) {
                if (plan.timbres) {
                    timbresRow.style.display = 'flex';
                    document.getElementById('summaryTimbres').textContent = plan.timbres;
                } else {
                    timbresRow.style.display = 'none';
                }
            }
        }

        // Función para copiar CLABE al portapapeles
function copiarCLABE(clabe) {
    navigator.clipboard.writeText(clabe).then(function() {
        // Mostrar feedback visual
        const btn = document.querySelector('.btn-outline-primary');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = originalText;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-primary');
        }, 2000);
    }).catch(function(err) {
        // Fallback para navegadores antiguos
        const textArea = document.createElement('textarea');
        textArea.value = clabe;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('CLABE copiada al portapapeles');
    });
}

        // Función para cancelar selección
        function cancelarSeleccion() {
            document.querySelectorAll('.plan').forEach(el => {
                el.classList.remove('selected');
            });
            document.getElementById('orderSummary').classList.remove('visible');
        }

        // Toggle de precios mensuales/anuales
        function togglePricing() {
            const track = document.getElementById('togTrack');
            track.classList.toggle('annual');
            isAnnual = track.classList.contains('annual');
            
            // Actualizar precios en las tarjetas
            document.querySelectorAll('.plan').forEach(el => {
                const planId = el.dataset.plan;
                const plan = planesData[planId];
                if (plan) {
                    const priceEl = el.querySelector('.pv');
                    // Mostrar el precio mensual o anual en las tarjetas
                    const precio = isAnnual ? plan.precio_anual : plan.precio_mensual;
                    priceEl.textContent = precio.toLocaleString();
                }
            });
            
            // Actualizar resumen si hay un plan seleccionado
            if (planActual && planesData[planActual]) {
                updateSummary(planesData[planActual]);
            }
        }

        // También permitir cambiar haciendo clic en el texto
        document.querySelector('.pricing-toggle')?.addEventListener('click', function(e) {
            if (e.target.closest('.tog-track')) return;
            togglePricing();
        });

        // Función para generar el link de pago
        async function generarPago() {
            try {
                // Mostrar overlay de carga
                document.getElementById('loadingOverlay').classList.add('active');
                
                // Obtener el total del resumen
                const totalTexto = document.getElementById('summaryTotal').textContent;
                // Extraer el número del texto "$X.XX MXN"
                const totalMatch = totalTexto.match(/\$([\d,]+\.?\d*)/);
                let monto = totalMatch ? parseFloat(totalMatch[1].replace(/,/g, '')) : 0;
                
                // Obtener el plan seleccionado
                const planSeleccionado = document.querySelector('.plan.selected');
                const nombrePlan = planSeleccionado ? planSeleccionado.querySelector('.plan-name').textContent : 'Plan Empresarial';
                
                // Verificar si es anual
                const esAnual = document.getElementById('togTrack').classList.contains('annual');
                
                // Construir la descripción
                const descripcion = `Suscripción ${nombrePlan} - ${esAnual ? 'Anual' : 'Mensual'}`;
                
                // Deshabilitar botones
                document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => {
                    btn.disabled = true;
                });
                
                // Llamar al endpoint para generar el link de pago
                const response = await fetch('Service/GenerarLigaPagoSuscripcion.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        monto: monto,
                        descripcion: descripcion
                    })
                });
                
                const data = await response.json();
                
                // Ocultar overlay de carga
                document.getElementById('loadingOverlay').classList.remove('active');
                
                // Habilitar botones
                document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => {
                    btn.disabled = false;
                });
                
                if (data.success && data.url) {
                    // Redirigir a la URL de pago
                    window.location.href = data.url;
                } else {
                    // Mostrar error
                    alert('Error al generar el link de pago: ' + (data.error || 'Error desconocido'));
                    console.error('Error:', data);
                }
                
            } catch (error) {
                // Ocultar overlay de carga
                document.getElementById('loadingOverlay').classList.remove('active');
                
                console.error('Error en generarPago:', error);
                alert('Error al procesar el pago. Por favor, intenta de nuevo.');
                
                // Habilitar botones
                document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => {
                    btn.disabled = false;
                });
            }
        }

        // Inicializar resumen con el plan seleccionado por defecto
        document.addEventListener('DOMContentLoaded', function() {
            if (planActual && planesData[planActual]) {
                updateSummary(planesData[planActual]);
                document.getElementById('orderSummary').classList.add('visible');
            }
        });
    </script>
</body>

</html>