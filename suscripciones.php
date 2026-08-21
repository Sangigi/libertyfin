<?php
// suscripciones.php 
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

// ============================================================
// VARIABLES PARA EL LOGO
// ============================================================
$logo_empresa = null;
$logo_src_base64 = null;
$color_primario = '#27ae60';
$color_secundario = '#2ecc71';
$nombre_empresa = $_SESSION['empresa_nombre'] ?? 'Mi Empresa';

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
            'moneda' => 'MXN',
            'color_primario' => '#27ae60',
            'color_secundario' => '#2ecc71'
        ];
        // Recargar la configuración
        $result_config = $conn->query($sql_config);
        $config = $result_config->fetch(PDO::FETCH_ASSOC);
    }

    // ============================================================
    // CARGAR LOGO DESDE sistema_config
    // ============================================================
    $nombre_empresa = $config['nombre_empresa'] ?? $_SESSION['empresa_nombre'] ?? 'Mi Empresa';
    $color_primario = $config['color_primario'] ?? '#27ae60';
    $color_secundario = $config['color_secundario'] ?? '#2ecc71';

    if (!empty($config['logo'])) {
        $empresa_logo = $config['logo'];
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

        // Si encontramos el logo, convertirlo a base64
        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;

            // Obtener la extensión del archivo
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));

            // Verificar que sea una imagen válida
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                // Leer el archivo y convertirlo a base64
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
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

// Verificar si la empresa ya tiene una tarjeta domiciliada
$conn_main = getDBConnection();
$tiene_domiciliacion = false;
$tarjeta_mask = '';
$tarjeta_exp_month = '';
$tarjeta_exp_year = '';
$domiciliacion_id = 0;

if ($conn_main) {
    $stmt_dom = $conn_main->prepare("SELECT id, cc_mask, cc_expmonth, cc_expyear FROM domiciliacion_tokens WHERE empresa_id = ?");
    $stmt_dom->execute([$_SESSION['empresa_id']]);
    $dom_data = $stmt_dom->fetch(PDO::FETCH_ASSOC);
    if ($dom_data && !empty($dom_data['cc_mask'])) {
        $tiene_domiciliacion = true;
        $domiciliacion_id = $dom_data['id'];
        $tarjeta_mask = $dom_data['cc_mask'];
        $tarjeta_exp_month = str_pad($dom_data['cc_expmonth'], 2, '0', STR_PAD_LEFT);
        $tarjeta_exp_year = $dom_data['cc_expyear'];
        // Convertir año de 2 dígitos a 4 si es necesario
        if (strlen($tarjeta_exp_year) == 2) {
            $tarjeta_exp_year = '20' . $tarjeta_exp_year;
        }
    }
    $conn_main = null;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <title>Planes - <?php echo htmlspecialchars($nombre_empresa); ?></title>
    
    <!-- Color de marca por empresa (sobrescribe el tema CRM) -->
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }
        
        /* Sobrescribir margin-left del main para esta página */
        main {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 1.5rem 1.75rem !important;
        }
        
        /* Ajustar el contenedor de planes */
        .plans-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1rem;
            width: 100%;
        }
    </style>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Tema CRM (estilos centralizados) -->
    <link rel="stylesheet" href="/css/crm-theme.css">
    <!-- Estilos específicos de suscripciones (solo lo que no cubre el tema CRM) -->
    <link rel="stylesheet" href="css/suscripciones.css">
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <!-- Mostrar logo en base64 -->
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($nombre_empresa); ?>"
                        class="me-2"
                        style="height: 32px; width: auto; border-radius: 8px; object-fit: contain;">
                    <span>
                        <?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <!-- Mostrar logo por ruta de archivo (fallback) -->
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($nombre_empresa); ?>"
                        class="me-2"
                        style="height: 32px; width: auto; border-radius: 8px; object-fit: contain;"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span>
                        <?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php else: ?>
                    <!-- Mostrar icono por defecto -->
                    <i class="fas fa-cash-register me-2" style="font-size: 1.2rem;"></i>
                    <span>
                        <?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($nombre_empresa); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li>
                             <li><a class="dropdown-item" href="Suscripciones">
                                    <i class="fas fa-crown me-2"></i>Suscripciones
                                </a></li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-modal">
            <div class="spinner"></div>
            <h5 id="loadingTitle">Procesando pago</h5>
            <p id="loadingMessage">Por favor espera un momento...</p>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <main>
        <!-- SECCIÓN DE PLANES -->
        <div class="plans-section">
            <div class="plans-inner">

                <h2 class="mb-2 text-center" style="color: var(--lf-ink); font-weight: 700;">
                    <i class="fas fa-rocket me-2" style="color: var(--primary-color);"></i>
                    Elige tu plan
                </h2>
                <p style="text-align: center; color: var(--lf-muted); margin-bottom: 0; font-size: 15px;">
                    Sin permanencia. Cancela cuando quieras.
                </p>

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

                    <!-- EMPRESARIAL -->
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

                </div>

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

                    <div class="summary-row ahorro" id="summaryAhorroRow" style="display: none;">
                        <span class="label"><i class="fas fa-gift me-2" style="color: var(--lf-warning);"></i>Ahorro</span>
                        <span class="value" id="summaryAhorro">-20%</span>
                    </div>

                    <div class="summary-row total">
                        <span class="label"><i class="fas fa-dollar-sign me-2" style="color: var(--primary-color);"></i>Total a pagar</span>
                        <span class="value" id="summaryTotal">$<?php echo number_format($plan_data['precio_mensual'], 2); ?> MXN</span>
                    </div>

                    <div class="summary-actions">
                        <button class="btn btn-pay" onclick="generarPago()">
                            <i class="fas fa-credit-card"></i> Pagar con tarjeta
                        </button>

                        <?php if ($tiene_domiciliacion && $empresa_plan !== 'prueba'): ?>
                        <button class="btn btn-pay-domiciliado" id="btnPagoDomiciliado" onclick="pagarConDomiciliacion()">
                            <i class="fas fa-sync-alt"></i> Pagar con domiciliación
                            <span class="badge bg-light text-dark ms-1" style="font-size: 9px; padding: 2px 8px;">
                                <i class="fas fa-check-circle text-success"></i> Activado
                            </span>
                        </button>
                        <?php endif; ?>

                        <button class="btn btn-cancel" onclick="cancelarSeleccion()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>

                    <?php if ($tiene_domiciliacion && $empresa_plan !== 'prueba'): ?>
                    <div class="mt-3 p-2" style="background: var(--lf-primary-050); border-radius: var(--lf-r); border-left: 4px solid #667eea;">
                        <small style="color: var(--lf-ink-2);">
                            <i class="fas fa-info-circle me-1"></i>
                            Tu pago se procesará automáticamente con la tarjeta domiciliada 
                            <strong><?php echo htmlspecialchars($tarjeta_mask); ?></strong>.
                        </small>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- SECCIÓN DE DOMICILIACIÓN DE TARJETA -->
                <div class="domiciliacion-section mt-5" id="domiciliacionSection" style="display: <?php echo ($empresa_plan !== 'prueba') ? 'block' : 'none'; ?>;">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h5 class="mb-0" style="font-weight: 700;">
                                <i class="fas fa-credit-card me-2"></i> 
                                Domiciliación de Pago
                                <span class="badge bg-light text-dark ms-2" style="font-size: 11px;">Pagos automáticos</span>
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            
                            <?php if ($tiene_domiciliacion): ?>
                                <div class="alert alert-success">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                                        <div>
                                            <i class="fas fa-check-circle me-2" style="color: var(--lf-success);"></i>
                                            <strong>Tarjeta domiciliada correctamente</strong>
                                            <div class="mt-1">
                                                <span class="badge bg-light text-dark me-2">
                                                    <i class="fas fa-credit-card me-1"></i>
                                                    <?php echo htmlspecialchars($tarjeta_mask); ?>
                                                </span>
                                                <span class="badge bg-light text-dark">
                                                    <i class="far fa-calendar-alt me-1"></i>
                                                    Expira: <?php echo $tarjeta_exp_month . '/' . $tarjeta_exp_year; ?>
                                                </span>
                                                <span class="badge" style="background: #667eea; color: white;">
                                                    <i class="fas fa-check-circle me-1"></i> Activa
                                                </span>
                                            </div>
                                        </div>
                                        <div class="mt-2 mt-sm-0">
                                            <button class="btn btn-danger btn-sm" onclick="cancelarDomiciliacion()">
                                                <i class="fas fa-trash-alt me-1"></i> Cancelar domiciliación
                                            </button>
                                            <button class="btn btn-outline-success btn-sm ms-2" onclick="cargarActualizarTarjeta()">
                                                <i class="fas fa-sync me-1"></i> Actualizar tarjeta
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-muted small mb-0">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Tu suscripción se renovará automáticamente cada mes. Puedes cancelar en cualquier momento.
                                </p>
                            <?php else: ?>
                                <!-- Formulario para domiciliar tarjeta -->
                                <div class="row">
                                    <div class="col-md-8">
                                        <form id="formDomiciliacion" onsubmit="return procesarDomiciliacion(event)">
                                            <div class="row g-3">
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold" style="font-size: 14px;">
                                                        <i class="fas fa-credit-card me-1" style="color: var(--primary-color);"></i>
                                                        Número de tarjeta
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">
                                                            <i class="fas fa-credit-card"></i>
                                                        </span>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               id="cardNumber" 
                                                               placeholder="1234 5678 9012 3456"
                                                               maxlength="19"
                                                               oninput="formatearNumeroTarjeta(this)"
                                                               required>
                                                    </div>
                                                    <small class="text-muted" style="font-size: 11px;">
                                                        <i class="fas fa-lock me-1"></i> Datos encriptados y seguros
                                                    </small>
                                                </div>
                                                
                                                <div class="col-md-4">
                                                    <label class="form-label fw-semibold" style="font-size: 14px;">Mes</label>
                                                    <select class="form-select" id="expMonth" required>
                                                        <option value="">MM</option>
                                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>">
                                                                <?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                                
                                                <div class="col-md-4">
                                                    <label class="form-label fw-semibold" style="font-size: 14px;">Año</label>
                                                    <select class="form-select" id="expYear" required>
                                                        <option value="">AA</option>
                                                        <?php for ($y = date('Y'); $y <= date('Y') + 10; $y++): ?>
                                                            <option value="<?php echo substr($y, -2); ?>">
                                                                <?php echo $y; ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                                
                                                <div class="col-md-4">
                                                    <label class="form-label fw-semibold" style="font-size: 14px;">
                                                        CVV
                                                        <span class="text-muted" style="font-weight: normal; font-size: 11px;">
                                                            <i class="fas fa-question-circle" data-bs-toggle="tooltip" title="Código de 3 dígitos en el reverso de tu tarjeta"></i>
                                                        </span>
                                                    </label>
                                                    <input type="password" 
                                                           class="form-control" 
                                                           id="cvv" 
                                                           placeholder="123"
                                                           maxlength="4"
                                                           required>
                                                </div>
                                                
                                                <div class="col-12">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="aceptoTerminos" required>
                                                        <label class="form-check-label" for="aceptoTerminos" style="font-size: 13px;">
                                                            Acepto los <a href="#" style="color: var(--primary-color);" data-bs-toggle="modal" data-bs-target="#terminosModal">términos y condiciones</a> del pago domiciliado
                                                        </label>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary" style="border-radius: 50px; padding: 10px 30px; font-weight: 600; width: 100%;" id="btnDomiciliar">
                                                        <i class="fas fa-check-circle me-2"></i>
                                                        Domiciliar tarjeta
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <div class="col-md-4 mt-3 mt-md-0">
                                        <div class="p-3" style="background: var(--lf-surface-2); border-radius: var(--lf-r); height: 100%;">
                                            <div class="text-center mb-2">
                                                <i class="fas fa-shield-alt" style="font-size: 2rem; color: var(--primary-color);"></i>
                                            </div>
                                            <h6 class="text-center fw-bold" style="font-size: 13px;">Pagos seguros</h6>
                                            <ul class="list-unstyled" style="font-size: 12px; color: var(--lf-muted);">
                                                <li><i class="fas fa-check-circle text-success me-1"></i> Datos encriptados</li>
                                                <li><i class="fas fa-check-circle text-success me-1"></i> Sin comisiones extra</li>
                                                <li><i class="fas fa-check-circle text-success me-1"></i> Renovación automática</li>
                                                <li><i class="fas fa-check-circle text-success me-1"></i> Cancelación fácil</li>
                                            </ul>
                                            <div class="text-center mt-2">
                                                <i class="fab fa-cc-visa me-1" style="color: #1a1f71;"></i>
                                                <i class="fab fa-cc-mastercard me-1" style="color: #eb001b;"></i>
                                                <i class="fab fa-cc-amex" style="color: #006fcf;"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- SECCIÓN DE FORMAS DE PAGO -->
        <section class="payment-section">
            <div class="payment-inner">
                <div class="text-center mb-4 reveal">
                    <span class="s-eyebrow">
                        <i class="fas fa-credit-card me-1"></i> Métodos de Pago
                    </span>
                    <h2>
                        Formas de <span style="color: var(--primary-color);">pago</span>
                    </h2>
                    <p>Elige la opción que mejor se adapte a tu negocio</p>
                </div>

                <div class="accordion" id="paymentAccordion">
                    <!-- Tarjeta de Crédito / Débito -->
                    <div class="accordion-item reveal">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCard" aria-expanded="true" aria-controls="collapseCard">
                                <i class="fas fa-credit-card me-3" style="color: var(--primary-color); font-size: 1.2rem;"></i>
                                Tarjeta
                                <span class="badge ms-2" style="background: var(--primary-color); color: var(--lf-on-brand);">Recomendado</span>
                            </button>
                        </h2>
                        <div id="collapseCard" class="accordion-collapse collapse show" data-bs-parent="#paymentAccordion">
                            <div class="accordion-body">
                                <div class="row align-items-center">
                                    <div class="col-md-7">
                                        <p style="color: var(--lf-ink-2); font-size: 14px; line-height: 1.8; margin-bottom: 16px;">
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
                                        <button class="btn btn-primary btn-sm" style="border-radius: 50px; padding: 8px 24px; font-weight: 600;" onclick="generarPago()">
                                            <i class="fas fa-credit-card me-1"></i> Pagar ahora
                                        </button>
                                        <p style="font-size: 11px; color: var(--lf-muted-2); margin-top: 8px;">
                                            <i class="fas fa-shield-alt me-1"></i> 100% seguro
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transferencia SPEI -->
                    <div class="accordion-item reveal reveal-d1">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSPEI" aria-expanded="false" aria-controls="collapseSPEI">
                                <i class="fas fa-university me-3" style="color: var(--primary-color); font-size: 1.2rem;"></i>
                                Transferencia SPEI
                                <span class="badge ms-2" style="background: #17a2b8; color: white;">Sin comisiones</span>
                            </button>
                        </h2>
                        <div id="collapseSPEI" class="accordion-collapse collapse" data-bs-parent="#paymentAccordion">
                            <div class="accordion-body">
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card shadow-sm">
                                            <div class="card-body p-4">
                                                <h5 class="card-title mb-3" style="color: var(--lf-ink); font-weight: 700;">
                                                    <i class="fas fa-building me-2" style="color: var(--primary-color);"></i>
                                                    Datos Bancarios
                                                </h5>
                                                
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <div class="p-3" style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1">
                                                                <i class="fas fa-university me-1"></i> Banco
                                                            </small>
                                                            <strong style="font-size: 1.1rem;">BANAMEX</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="p-3" style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1">
                                                                <i class="fas fa-user me-1"></i> Beneficiario
                                                            </small>
                                                            <strong style="font-size: 1.1rem;">OPERACIONES Y MULTISERVICIOS IDEAS SA DE CV</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="p-3" style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
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
                                                    <div class="col-md-6">
                                                        <div class="p-3" style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1">
                                                                <i class="fas fa-credit-card me-1"></i> Número de Tarjeta
                                                            </small>
                                                            <strong style="font-size: 1.1rem; letter-spacing: 1px;">5290 9303 0104 4786</strong>
                                                        </div>
                                                    </div>
                                                </div>

                                                <hr class="my-3" style="border-color: var(--lf-border);">
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
                                                                <strong>www.grupoideasmx.com</strong>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="alert alert-success mt-3 mb-0">
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

                    <!-- Domiciliación -->
                    <?php if ($tiene_domiciliacion): ?>
                    <div class="accordion-item reveal reveal-d2">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDomiciliacion" aria-expanded="false" aria-controls="collapseDomiciliacion">
                                <i class="fas fa-sync-alt me-3" style="color: #667eea; font-size: 1.2rem;"></i>
                                Pago Domiciliado
                                <span class="badge ms-2" style="background: #667eea; color: white;">Automático</span>
                            </button>
                        </h2>
                        <div id="collapseDomiciliacion" class="accordion-collapse collapse" data-bs-parent="#paymentAccordion">
                            <div class="accordion-body">
                                <div class="row align-items-center">
                                    <div class="col-md-7">
                                        <p style="color: var(--lf-ink-2); font-size: 14px; line-height: 1.8; margin-bottom: 16px;">
                                            <i class="fas fa-check-circle me-2" style="color: #667eea;"></i>
                                            Tu pago se realizará automáticamente con la tarjeta domiciliada.
                                        </p>
                                        <ul class="payment-features">
                                            <li><i class="fas fa-check-circle" style="color: #667eea;"></i> Sin necesidad de ingresar datos cada vez</li>
                                            <li><i class="fas fa-check-circle" style="color: #667eea;"></i> Renovación automática mensual</li>
                                            <li><i class="fas fa-check-circle" style="color: #667eea;"></i> Cancelación en cualquier momento</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-5 text-center">
                                        <div class="mb-3">
                                            <span class="badge" style="background: #667eea; color: white; padding: 8px 16px; font-size: 14px; border-radius: 50px;">
                                                <i class="fas fa-credit-card me-1"></i>
                                                <?php echo htmlspecialchars($tarjeta_mask); ?>
                                            </span>
                                        </div>
                                        <button class="btn btn-primary btn-sm" style="border-radius: 50px; padding: 10px 30px; background: #667eea; border-color: #667eea; font-weight: 600;" onclick="pagarConDomiciliacion()">
                                            <i class="fas fa-sync-alt me-1"></i> Pagar con domiciliación
                                        </button>
                                        <p style="font-size: 11px; color: var(--lf-muted-2); margin-top: 8px;">
                                            <i class="fas fa-clock me-1"></i> Proceso inmediato
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <!-- Modal de términos y condiciones -->
    <div class="modal fade" id="terminosModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-contract me-2" style="color: var(--primary-color);"></i>
                        Términos de Domiciliación
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="font-size: 14px; color: var(--lf-ink-2); line-height: 1.8;">
                    <p><strong>Al domiciliar tu tarjeta, aceptas:</strong></p>
                    <ul>
                        <li>El cargo automático mensual por el monto correspondiente a tu plan.</li>
                        <li>Recibir notificaciones antes de cada cargo.</li>
                        <li>Puedes cancelar la domiciliación en cualquier momento.</li>
                        <li>Tus datos están protegidos bajo estándares de seguridad PCI-DSS.</li>
                    </ul>
                    <p class="mt-3 text-muted" style="font-size: 13px;">
                        <i class="fas fa-lock me-1"></i>
                        No almacenamos el número completo de tu tarjeta, solo un token seguro.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px;">Cerrar</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" style="border-radius: 50px;">
                        <i class="fas fa-check me-1"></i> Acepto
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- JavaScript personalizado -->
    <script src="js/suscripciones.js"></script>
    
    <script>
        // Inicializar datos para JS (variables PHP a JS)
        const phpData = {
            empresaId: '<?php echo $_SESSION['empresa_id']; ?>',
            empresaPlan: '<?php echo $empresa_plan; ?>',
            tieneDomiciliacion: <?php echo $tiene_domiciliacion ? 'true' : 'false'; ?>,
            tarjetaMask: '<?php echo $tarjeta_mask; ?>',
            planSeleccionado: '<?php echo $plan_seleccionado; ?>'
        };
        
        // Pasar datos a la función de inicialización
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof inicializarSuscripciones === 'function') {
                inicializarSuscripciones(phpData);
            }
        });
    </script>
</body>

</html>