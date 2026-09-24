<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

$plan_key = isset($_GET['plan']) ? $_GET['plan'] : 'empresarial';
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'mensual';

$planes = [
    'basico' => [
        'nombre' => 'Básico',
        'precio_mensual' => 299,
        'precio_anual' => 239,
        'usuarios' => 1,
        'cajas' => 1,
        'productos' => 100
    ],
    'starter' => [
        'nombre' => 'Profesional',
        'precio_mensual' => 599,
        'precio_anual' => 479,
        'usuarios' => 4,
        'cajas' => 2,
        'productos' => 500
    ],
    'emprendedor' => [
        'nombre' => 'Empresarial',
        'precio_mensual' => 999,
        'precio_anual' => 799,
        'usuarios' => 6,
        'cajas' => 3,
        'productos' => 500,
        'sucursales' => 1
    ],
    'premium' => [
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

if (!isset($planes[$plan_key]))
    $plan_key = 'empresarial';
$plan_data = $planes[$plan_key];
$is_annual = ($periodo === 'anual');
$precio = $is_annual ? $plan_data['precio_anual'] * 12 : $plan_data['precio_mensual'];

// Arreglo de regímenes fiscales del SAT
$regimenes_fiscales = [
    '601' => 'General de Ley Personas Morales',
    '603' => 'Personas Morales con Fines no Lucrativos',
    '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
    '606' => 'Arrendamiento',
    '607' => 'Régimen de Enajenación o Adquisición de Bienes',
    '608' => 'Demás ingresos',
    '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
    '611' => 'Ingresos por Dividendos (socios y accionistas)',
    '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
    '614' => 'Ingresos por intereses',
    '615' => 'Régimen de los ingresos por obtención de premios',
    '616' => 'Sin obligaciones fiscales',
    '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
    '621' => 'Incorporación Fiscal',
    '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
    '623' => 'Opcional para Grupos de Sociedades',
    '624' => 'Coordinados',
    '625' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
    '626' => 'Régimen Simplificado de Confianza'
];

// Arreglo de usos de CFDI (catálogo del SAT)
$usos_cfdi = [
    'G01' => 'Adquisición de mercancías',
    'G02' => 'Devoluciones, descuentos o bonificaciones',
    'G03' => 'Gastos en general',
    'I01' => 'Construcciones',
    'I02' => 'Mobiliario y equipo de oficina por inversiones',
    'I03' => 'Equipo de transporte',
    'I04' => 'Equipo de cómputo y accesorios',
    'I05' => 'Dados, troqueles, moldes, matrices y herramental',
    'I06' => 'Comunicaciones telefónicas',
    'I07' => 'Comunicaciones satelitales',
    'I08' => 'Otra maquinaria y equipo',
    'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
    'D02' => 'Gastos médicos por incapacidad o discapacidad',
    'D03' => 'Gastos funerales',
    'D04' => 'Donativos',
    'D05' => 'Intereses reales efectivamente pagados por créditos hipotecarios',
    'D06' => 'Aportaciones voluntarias al SAR',
    'D07' => 'Primas por seguros de gastos médicos',
    'D08' => 'Gastos de transportación escolar obligatoria',
    'D09' => 'Depósitos en cuentas para el ahorro',
    'D10' => 'Pagos por servicios educativos',
    'P01' => 'Por definir'
];

// Datos fiscales guardados en "Mi cuenta" (sistema_config / datos_pago_comercio)
// de la propia empresa: se usan como valor por defecto para no tener que
// volver a capturarlos cada vez que se paga la suscripción. Si el usuario ya
// los había llenado en este checkout durante la sesión actual, esos tienen
// prioridad (por si los está corrigiendo solo para esta compra).
$fiscal_empresa = [];
$pago_empresa = [];
if (!empty($_SESSION['empresa_db'])) {
    try {
        $conn_fiscal = getEmpresaDBConnection($_SESSION['empresa_db']);
        if ($conn_fiscal) {
            $res = $conn_fiscal->query("SELECT * FROM sistema_config LIMIT 1");
            $fiscal_empresa = $res->fetch(PDO::FETCH_ASSOC) ?: [];

            // datos_pago_comercio (dirección) puede no existir todavía si el
            // admin nunca ha entrado a "Mi cuenta" -> se ignora en silencio.
            try {
                $res = $conn_fiscal->query("SELECT * FROM datos_pago_comercio LIMIT 1");
                $pago_empresa = $res->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                $pago_empresa = [];
            }
            $conn_fiscal = null;
        }
    } catch (Exception $e) {
        error_log('checkout.php: no se pudieron cargar datos fiscales de la empresa: ' . $e->getMessage());
    }
}

// Recuperar datos fiscales: primero lo ya capturado en esta sesión (si el
// usuario los está editando ahora mismo), si no lo que tenga guardado en
// "Mi cuenta", y si tampoco hay, vacío.
$facturar_default = $_SESSION['facturar_default'] ?? 'no';
$rfc_default = $_SESSION['rfc_default'] ?? ($fiscal_empresa['rfc'] ?? '');
$razon_social_default = $_SESSION['razon_social_default'] ?? ($fiscal_empresa['razon_social'] ?? '');
$regimen_default = $_SESSION['regimen_default'] ?? ($fiscal_empresa['regimen_fiscal'] ?? '');
$cp_default = $_SESSION['cp_default'] ?? ($fiscal_empresa['cp_fiscal'] ?? '');
$estado_default = $_SESSION['estado_default'] ?? ($pago_empresa['estado_direccion'] ?? '');
$ciudad_default = $_SESSION['ciudad_default'] ?? ($pago_empresa['ciudad'] ?? '');
$uso_cfdi_default = $_SESSION['uso_cfdi_default'] ?? '';

$conn_main = getDBConnection();
$empresa_plan = "prueba";
$cargo_automatico = false;
$tiene_domiciliacion = false;
$tarjeta_mask = '';
$tarjeta_exp_month = '';
$tarjeta_exp_year = '';
if ($conn_main) {
    $stmt = $conn_main->prepare("SELECT plan, cargo_automatico FROM empresas WHERE id = ?");
    $stmt->execute([$_SESSION['empresa_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $empresa_plan = $row['plan'];
        $cargo_automatico = (bool) $row['cargo_automatico'];
    }
    $stmt = null;
    $stmt = $conn_main->prepare("SELECT id, cc_mask, cc_expmonth, cc_expyear FROM domiciliacion_tokens WHERE empresa_id = ?");
    $stmt->execute([$_SESSION['empresa_id']]);
    $dom = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dom && !empty($dom['cc_mask'])) {
        $tiene_domiciliacion = true;
        $tarjeta_mask = $dom['cc_mask'];
        $tarjeta_exp_month = str_pad($dom['cc_expmonth'], 2, '0', STR_PAD_LEFT);
        $tarjeta_exp_year = $dom['cc_expyear'];
        if (strlen($tarjeta_exp_year) == 2)
            $tarjeta_exp_year = '20' . $tarjeta_exp_year;
    }
    $conn_main = null;
}
$_SESSION['empresa_plan'] = $empresa_plan;

$conn = getEmpresaDBConnection($_SESSION['empresa_db']);
$config = [];
if ($conn) {
    $sql = "SELECT * FROM sistema_config LIMIT 1";
    $res = $conn->query($sql);
    $config = $res->fetch(PDO::FETCH_ASSOC);
    $conn = null;
}
$nombre_empresa = $config['nombre_empresa'] ?? $_SESSION['empresa_nombre'] ?? 'Mi Empresa';
$color_primario = $config['color_primario'] ?? '#27ae60';
$color_secundario = $config['color_secundario'] ?? '#2ecc71';

$logo_src_base64 = null;
if (!empty($config['logo'])) {
    $rutas = [
        $config['logo'],
        '../' . $config['logo'],
        '../../' . $config['logo'],
        'admin/' . $config['logo'],
        '../admin/' . $config['logo'],
        'logos/' . $config['logo'],
        'img/' . $config['logo'],
        'images/' . $config['logo'],
        'assets/' . $config['logo'],
        'uploads/' . $config['logo'],
        '../logos/' . $config['logo'],
        '../img/' . $config['logo'],
        '../images/' . $config['logo'],
        '../assets/' . $config['logo'],
        '../uploads/' . $config['logo']
    ];
    $logo_path = '';
    foreach ($rutas as $ruta) {
        if (file_exists($ruta) && is_file($ruta)) {
            $logo_path = $ruta;
            break;
        }
    }
    if ($logo_path) {
        $ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
        $validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        if (in_array($ext, $validas)) {
            $logo_data = base64_encode(file_get_contents($logo_path));
            $logo_src_base64 = 'data:image/' . $ext . ';base64,' . $logo_data;
        }
    }
}

// ✅ Badge que soporta claves nuevas Y antiguas
$plan_badge_class = match ($empresa_plan) {
    'plus', 'premium' => 'primary',
    'empresarial', 'emprendedor' => 'success',
    'profesional', 'stater' => 'info',
    'basico' => 'warning',
    'prueba' => 'info',
    default => 'secondary'
};
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <title>Checkout - <?php echo htmlspecialchars($nombre_empresa); ?></title>
    <style>
        :root {
            --primary-color:
                <?php echo $color_primario; ?>
            ;
            --secondary-color:
                <?php echo $color_secundario; ?>
            ;
        }

        main {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 1.5rem 1.75rem !important;
        }

        .plans-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1rem;
            width: 100%;
        }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="/css/crm-theme.css">
    <link rel="stylesheet" href="css/planes.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>" alt="<?php echo htmlspecialchars($nombre_empresa); ?>"
                        class="me-2" style="height:32px; width:auto; border-radius:8px; object-fit:contain;">
                    <span><?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php echo $plan_badge_class; ?> ms-2"
                            style="font-size:0.5rem;"><?php echo ucfirst($empresa_plan); ?></span>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2" style="font-size:1.2rem;"></i>
                    <span><?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php echo $plan_badge_class; ?> ms-2"
                            style="font-size:0.5rem;"><?php echo ucfirst($empresa_plan); ?></span>
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
                        <li><span class="dropdown-item-text"><small>Empresa:
                                    <?php echo htmlspecialchars($nombre_empresa); ?></small></span></li>
                        <li><span class="dropdown-item-text"><small>Rol:
                                    <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small></span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="cuenta.php"><i class="fas fa-id-card me-2"></i>Mi Cuenta</a>
                        </li>
                        <li><a class="dropdown-item" href="planes.php"><i class="fas fa-rocket me-2"></i>Planes</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar
                                Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-modal">
            <div class="spinner"></div>
            <h5 id="loadingTitle">Procesando pago</h5>
            <p id="loadingMessage">Por favor espera un momento...</p>
        </div>
    </div>

    <main>
        <div class="plans-section">
            <div class="plans-inner">
                <h2 class="mb-2 text-center" style="color: var(--lf-ink); font-weight: 700;">
                    <i class="fas fa-shopping-cart me-2" style="color: var(--primary-color);"></i>
                    Resumen del pedido
                </h2>
                <p style="text-align: center; color: var(--lf-muted); margin-bottom: 0; font-size: 15px;">
                    Confirma los datos y elige tu método de pago.
                </p>

                <div class="pricing-toggle">
                    <span style="font-weight:600;">Mensual</span>
                    <div class="tog-track <?php echo $is_annual ? 'annual' : ''; ?>" onclick="togglePricing()"
                        id="togTrack">
                        <div class="tog-thumb"></div>
                    </div>
                    <span style="font-weight:600;">Anual <span class="save-badge">–20%</span></span>
                </div>

                <!-- CONTENEDOR PARA DOS COLUMNAS -->
                <div class="checkout-row">
                    <!-- Columna izquierda: Resumen del pedido -->
                    <div class="order-summary visible" id="orderSummary">
                        <div class="summary-header">
                            <h4><i class="fas fa-shopping-cart me-2" style="color: var(--primary-color);"></i>Resumen
                                del pedido</h4>
                            <span class="badge-status"><i class="fas fa-check-circle me-1"></i>Plan seleccionado</span>
                        </div>
                        <div class="summary-row">
                            <span class="label"><i class="fas fa-tag me-2"
                                    style="color: var(--primary-color);"></i>Plan</span>
                            <span class="value" id="summaryPlan"><?php echo $plan_data['nombre']; ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="label"><i class="fas fa-users me-2"
                                    style="color: var(--primary-color);"></i>Usuarios</span>
                            <span class="value" id="summaryUsuarios"><?php echo $plan_data['usuarios']; ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="label"><i class="fas fa-cash-register me-2"
                                    style="color: var(--primary-color);"></i>Cajas registradoras</span>
                            <span class="value" id="summaryCajas"><?php echo $plan_data['cajas']; ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="label"><i class="fas fa-boxes me-2"
                                    style="color: var(--primary-color);"></i>Productos</span>
                            <span class="value" id="summaryProductos"><?php echo $plan_data['productos']; ?></span>
                        </div>
                        <?php if (isset($plan_data['sucursales'])): ?>
                            <div class="summary-row">
                                <span class="label"><i class="fas fa-store me-2"
                                        style="color: var(--primary-color);"></i>Sucursales</span>
                                <span class="value" id="summarySucursales"><?php echo $plan_data['sucursales']; ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($plan_data['timbres'])): ?>
                            <div class="summary-row">
                                <span class="label"><i class="fas fa-file-invoice me-2"
                                        style="color: var(--primary-color);"></i>CFDI / Timbres</span>
                                <span class="value" id="summaryTimbres"><?php echo $plan_data['timbres']; ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="summary-row" style="border-bottom: none; padding-bottom: 4px;">
                            <span class="label"><i class="fas fa-calendar me-2"
                                    style="color: var(--primary-color);"></i>Periodo</span>
                            <span class="value"
                                id="summaryPeriodo"><?php echo $is_annual ? 'Anual' : 'Mensual'; ?></span>
                        </div>
                        <div class="summary-row ahorro" id="summaryAhorroRow"
                            style="display: <?php echo $is_annual ? 'flex' : 'none'; ?>;">
                            <span class="label"><i class="fas fa-gift me-2"
                                    style="color: var(--lf-warning);"></i>Ahorro</span>
                            <span class="value"
                                id="summaryAhorro">-<?php echo round((1 - $plan_data['precio_anual'] / $plan_data['precio_mensual']) * 100); ?>%</span>
                        </div>
                        <div class="summary-row total">
                            <span class="label"><i class="fas fa-dollar-sign me-2"
                                    style="color: var(--primary-color);"></i>Total a pagar</span>
                            <span class="value" id="summaryTotal">$<?php echo number_format($precio, 2); ?> MXN</span>
                        </div>

                        <div class="summary-actions">
                            <a href="planes.php" class="btn btn-cancel">
                                <i class="fas fa-arrow-left"></i> Cambiar plan
                            </a>
                        </div>
                    </div>

                    <!-- Columna derecha: Métodos de pago -->
                    <section class="payment-section">
                        <div class="payment-inner">
                            <div class="text-center mb-4 reveal">
                                <span class="s-eyebrow"><i class="fas fa-credit-card me-1"></i> Métodos de Pago</span>
                                <h2>1. Selecciona un <span style="color: var(--primary-color);">método de pago</span>
                                </h2>
                                <p class="text-muted">Elige la opción que prefieras para completar tu compra</p>
                            </div>

                            <!-- SECCIÓN DE FACTURACIÓN -->
                            <div class="billing-section mb-4 reveal">
                                <div class="billing-card">
                                    <div class="billing-header">
                                        <h5><i class="fas fa-file-invoice me-2"
                                                style="color: var(--primary-color);"></i>Datos de Facturación</h5>
                                    </div>
                                    <div class="billing-body">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">¿Requieres factura? *</label>
                                            <div class="d-flex gap-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="facturar"
                                                        id="facturar_si" value="si" <?php echo ($facturar_default === 'si') ? 'checked' : ''; ?>
                                                        onchange="toggleFacturacion()">
                                                    <label class="form-check-label" for="facturar_si">Sí, requiero
                                                        factura</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="facturar"
                                                        id="facturar_no" value="no" <?php echo ($facturar_default !== 'si') ? 'checked' : ''; ?> onchange="toggleFacturacion()">
                                                    <label class="form-check-label" for="facturar_no">No,
                                                        gracias</label>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Campos de facturación (ocultos por defecto si es "no") -->
                                        <div id="camposFacturacion"
                                            style="display: <?php echo ($facturar_default === 'si') ? 'block' : 'none'; ?>;">
                                            <hr class="my-3">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label for="razon_social" class="form-label">Nombre / Razón Social
                                                        *</label>
                                                    <input type="text" class="form-control" id="razon_social"
                                                        name="razon_social"
                                                        value="<?php echo htmlspecialchars($razon_social_default); ?>"
                                                        placeholder="Ej. Mi Empresa S.A. de C.V.">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="rfc" class="form-label">RFC *</label>
                                                    <input type="text" class="form-control" id="rfc" name="rfc"
                                                        value="<?php echo htmlspecialchars($rfc_default); ?>"
                                                        placeholder="Ej. GODE561231GR8">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="email_factura" class="form-label">Correo electrónico
                                                        *</label>
                                                    <input type="email" class="form-control" id="email_factura"
                                                        name="email_factura"
                                                        value="<?php echo htmlspecialchars($_SESSION['usuario_email'] ?? ''); ?>"
                                                        placeholder="correo@ejemplo.com">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="regimen_fiscal" class="form-label">Régimen fiscal
                                                        *</label>
                                                    <select class="form-select" id="regimen_fiscal"
                                                        name="regimen_fiscal">
                                                        <option value="">Seleccionar...</option>
                                                        <?php foreach ($regimenes_fiscales as $clave => $descripcion): ?>
                                                            <option value="<?php echo $clave; ?>" <?php echo ($regimen_default === $clave) ? 'selected' : ''; ?>>
                                                                <?php echo $clave . ' - ' . $descripcion; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="cp" class="form-label">Código postal *</label>
                                                    <input type="text" class="form-control" id="cp" name="cp"
                                                        value="<?php echo htmlspecialchars($cp_default); ?>"
                                                        placeholder="12345" maxlength="5">
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="estado" class="form-label">Estado</label>
                                                    <input type="text" class="form-control" id="estado" name="estado"
                                                        value="<?php echo htmlspecialchars($estado_default); ?>"
                                                        placeholder="Ej. Jalisco">
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="ciudad" class="form-label">Ciudad</label>
                                                    <input type="text" class="form-control" id="ciudad" name="ciudad"
                                                        value="<?php echo htmlspecialchars($ciudad_default); ?>"
                                                        placeholder="Ej. Guadalajara">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="metodo_pago" class="form-label">Método de pago *</label>
                                                    <select class="form-select" id="metodo_pago" name="metodo_pago">
                                                        <option value="PUE">PUE (Pago en una sola exhibición)</option>
                                                        <option value="PPD">PPD (Pago en parcialidades o diferido)
                                                        </option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="uso_cfdi" class="form-label">Uso de CFDI *</label>
                                                    <select class="form-select" id="uso_cfdi" name="uso_cfdi">
                                                        <option value="">Seleccionar...</option>
                                                        <?php foreach ($usos_cfdi as $clave => $descripcion): ?>
                                                            <option value="<?php echo $clave; ?>" <?php echo ($uso_cfdi_default === $clave) ? 'selected' : ''; ?>>
                                                                <?php echo $clave . ' - ' . $descripcion; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="payment-tabs-wrapper">
                                <!-- Pestañas horizontales -->
                                <ul class="nav nav-pills payment-tabs" id="paymentTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="tab-card" data-bs-toggle="pill"
                                            data-bs-target="#panel-card" type="button" role="tab"
                                            aria-controls="panel-card" aria-selected="true">
                                            <i class="fas fa-credit-card me-2"></i> Tarjeta
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="tab-spei" data-bs-toggle="pill"
                                            data-bs-target="#panel-spei" type="button" role="tab"
                                            aria-controls="panel-spei" aria-selected="false">
                                            <i class="fas fa-university me-2"></i> SPEI
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="tab-referencia" data-bs-toggle="tab"
                                            data-bs-target="#panel-referencia" type="button" role="tab"
                                            aria-controls="panel-referencia" aria-selected="false">
                                            <i class="fas fa-barcode"></i> Referencia en efectivo
                                        </button>
                                    </li>
                                </ul>

                                <!-- Paneles de contenido -->
                                <div class="tab-content payment-tab-content" id="paymentTabsContent">
                                    <!-- Panel: Tarjeta (con iframe) -->
                                    <div class="tab-pane fade show active" id="panel-card" role="tabpanel"
                                        aria-labelledby="tab-card">
                                        <div class="payment-panel-body">
                                            <!-- Vista informativa inicial -->
                                            <div id="cardInfoView">
                                                <div class="row align-items-center">
                                                    <div class="col-md-8">
                                                        <p
                                                            style="color: var(--lf-ink-2); font-size: 14px; line-height: 1.8; margin-bottom: 16px;">
                                                            <i class="fas fa-lock me-2"
                                                                style="color: var(--primary-color);"></i>
                                                            Pago seguro con tarjeta de crédito o débito. Aceptamos todas
                                                            las tarjetas principales.
                                                        </p>
                                                        <ul class="payment-features">
                                                            <li><i class="fas fa-check-circle"></i> Transacciones
                                                                encriptadas SSL</li>
                                                            <li><i class="fas fa-check-circle"></i> Aprobación en
                                                                segundos</li>
                                                        </ul>
                                                    </div>
                                                    <div class="col-md-4 text-center">
                                                        <div class="payment-icons">
                                                            <i class="fab fa-cc-visa" style="color: #1a1f71;"></i>
                                                            <i class="fab fa-cc-mastercard" style="color: #eb001b;"></i>
                                                        </div>
                                                        <button class="btn btn-primary btn-sm mt-2"
                                                            style="border-radius: 50px; padding: 8px 28px; font-weight: 600;"
                                                            onclick="generarPago()">
                                                            <i class="fas fa-credit-card me-1"></i> Pagar ahora
                                                        </button>
                                                        <p
                                                            style="font-size: 11px; color: var(--lf-muted-2); margin-top: 8px;">
                                                            <i class="fas fa-shield-alt me-1"></i> 100% seguro
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Vista de resultado (iframe con el pago) -->
                                            <div id="cardResultView" style="display: none;">
                                                <div class="text-center mb-3">
                                                    <i class="fas fa-credit-card"
                                                        style="font-size: 2rem; color: var(--primary-color);"></i>
                                                    <h5 style="font-weight: 700; color: var(--lf-ink);">Completa tu pago
                                                    </h5>
                                                    <p style="color: var(--lf-muted); font-size: 14px;">
                                                        Utiliza el siguiente formulario para completar tu transacción de
                                                        forma segura.
                                                    </p>
                                                </div>
                                                <div class="payment-iframe-wrapper">
                                                    <iframe id="paymentIframe" src=""
                                                        style="width: 100%; height: 600px; border: none; border-radius: var(--lf-r); background: white;"
                                                        allowpaymentrequest></iframe>
                                                </div>
                                                <div class="text-center mt-3">
                                                    <button class="btn btn-outline-secondary btn-sm"
                                                        onclick="abrirLinkPago()"
                                                        style="border-radius: 50px; padding: 6px 18px;">
                                                        <i class="fas fa-external-link-alt me-1"></i> Abrir en nueva
                                                        pestaña
                                                    </button>
                                                    <button class="btn btn-outline-secondary btn-sm"
                                                        onclick="copiarLinkPago()"
                                                        style="border-radius: 50px; padding: 6px 18px;">
                                                        <i class="fas fa-copy me-1"></i> Copiar enlace
                                                    </button>
                                                    <button class="btn btn-link btn-sm" onclick="volverDeResultado()"
                                                        style="color: var(--lf-muted);">
                                                        <i class="fas fa-arrow-left me-1"></i> Volver
                                                    </button>
                                                </div>
                                                <p class="text-center text-muted mt-2" style="font-size: 12px;">
                                                    <i class="fas fa-shield-alt me-1"></i> El enlace expira en 24 horas.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Panel: SPEI (con CLABE generada por generar_clabe.php) -->
                                    <div class="tab-pane fade" id="panel-spei" role="tabpanel"
                                        aria-labelledby="tab-spei">
                                        <div class="payment-panel-body">
                                            <!-- Vista inicial: botón para generar CLABE -->
                                            <div id="speiInfoView">
                                                <div class="row align-items-center">
                                                    <div class="col-md-8">
                                                        <p
                                                            style="color: var(--lf-ink-2); font-size: 14px; line-height: 1.8; margin-bottom: 16px;">
                                                            <i class="fas fa-university me-2"
                                                                style="color: var(--primary-color);"></i>
                                                            Paga mediante transferencia bancaria SPEI. Generaremos una
                                                            CLABE única para tu pago.
                                                        </p>
                                                        <ul class="payment-features">
                                                            <li><i class="fas fa-check-circle"></i> Sin comisiones</li>
                                                            <li><i class="fas fa-check-circle"></i> Transferencia
                                                                reflejada en 24-48 horas hábiles</li>
                                                            <li><i class="fas fa-check-circle"></i> CLABE válida por 24
                                                                horas</li>
                                                        </ul>
                                                    </div>
                                                    <div class="col-md-4 text-center">
                                                        <div class="payment-icons">
                                                            <i class="fas fa-university"
                                                                style="color: #002180; font-size: 3rem; opacity: 0.8;"></i>
                                                        </div>
                                                        <button class="btn btn-primary btn-sm mt-2"
                                                            style="border-radius: 50px; padding: 8px 28px; font-weight: 600;"
                                                            onclick="generarCLABE()">
                                                            <i class="fas fa-qrcode me-1"></i> Generar CLABE
                                                        </button>
                                                        <p
                                                            style="font-size: 11px; color: var(--lf-muted-2); margin-top: 8px;">
                                                            <i class="fas fa-shield-alt me-1"></i> Pago 100% seguro
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Vista de resultado: CLABE generada -->
                                            <div id="speiResultView" style="display: none;">
                                                <div class="text-center mb-4">
                                                    <i class="fas fa-check-circle"
                                                        style="font-size: 2.5rem; color: var(--primary-color);"></i>
                                                    <h5
                                                        style="font-weight: 700; color: var(--lf-ink); margin-top: 8px;">
                                                        CLABE generada exitosamente</h5>
                                                    <p style="color: var(--lf-muted); font-size: 14px;">
                                                        Realiza tu transferencia SPEI a la siguiente cuenta:
                                                    </p>
                                                </div>

                                                <div class="spei-clabe-box" id="speiClabeBox">
                                                    <div class="spei-clabe-label">
                                                        <i class="fas fa-hashtag me-1"></i> CLABE Interbancaria
                                                    </div>
                                                    <div class="spei-clabe-value" id="speiClabeValue">—</div>
                                                    <button class="btn btn-sm btn-outline-primary mt-2"
                                                        onclick="copiarCLABE('speiClabeValue')"
                                                        style="border-radius: 20px;">
                                                        <i class="fas fa-copy me-1"></i> Copiar CLABE
                                                    </button>
                                                </div>

                                                <div class="row g-3 mt-3">
                                                    <div class="col-md-6">
                                                        <div class="p-3"
                                                            style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1"><i
                                                                    class="fas fa-university me-1"></i> Banco</small>
                                                            <strong id="speiBanco">—</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="p-3"
                                                            style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1"><i
                                                                    class="fas fa-user me-1"></i> Beneficiario</small>
                                                            <strong id="speiBeneficiario">—</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="p-3"
                                                            style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1"><i
                                                                    class="fas fa-dollar-sign me-1"></i> Monto</small>
                                                            <strong id="speiMonto">—</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="p-3"
                                                            style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1"><i
                                                                    class="fas fa-barcode me-1"></i> Folio</small>
                                                            <strong id="speiFolio">—</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <div class="p-3"
                                                            style="background: var(--lf-surface-2); border-radius: var(--lf-r);">
                                                            <small class="text-muted d-block mb-1"><i
                                                                    class="fas fa-clock me-1"></i> Válida hasta</small>
                                                            <strong id="speiExpiracion">—</strong>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="alert alert-success mt-3 mb-0">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    <strong>Importante:</strong> Envía tu comprobante a
                                                    <strong>ventas@grupoideas.com.mx</strong> o por WhatsApp para
                                                    agilizar la validación.
                                                </div>

                                                <div class="text-center mt-3">
                                                    <button class="btn btn-outline-secondary btn-sm"
                                                        onclick="volverDeSPEI()"
                                                        style="border-radius: 50px; padding: 6px 18px;">
                                                        <i class="fas fa-arrow-left me-1"></i> Volver
                                                    </button>
                                                    <button class="btn btn-link btn-sm" onclick="generarCLABE()"
                                                        style="color: var(--lf-muted);">
                                                        <i class="fas fa-sync-alt me-1"></i> Generar nueva CLABE
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- =========================================================
     PANEL: Referencia en efectivo (OXXO / tiendas)
     ========================================================= -->
                                    <div class="tab-pane fade" id="panel-referencia" role="tabpanel"
                                        aria-labelledby="tab-referencia" tabindex="0">

                                        <!-- VISTA 1: Formulario -->
                                        <div id="refInfoView" class="payment-panel-body">
                                            <div class="text-center mb-3">
                                                <div class="payment-icons">
                                                    <i class="fas fa-store-alt" style="color:#e74c3c;"></i>
                                                    <i class="fas fa-barcode" style="color:#27ae60;"></i>
                                                    <i class="fas fa-file-invoice" style="color:#3498db;"></i>
                                                </div>
                                                <h5 class="fw-bold mb-1">Paga en efectivo en tiendas</h5>
                                                <p class="text-muted small mb-3">
                                                    Genera tu ficha y paga en OXXO, 7-Eleven, Farmacias, Walmart y más
                                                    de 10,000 puntos.
                                                </p>
                                            </div>

                                            <div class="alert alert-info d-flex align-items-start gap-2 mb-3"
                                                style="border-radius:var(--lf-r); font-size:13px;">
                                                <i class="fas fa-info-circle mt-1"></i>
                                                <div>
                                                    <strong>Instrucciones:</strong> al generar la referencia podrás
                                                    descargar
                                                    un PDF con el código de barras. Preséntalo en caja y realiza tu
                                                    pago.
                                                    La referencia vence en <strong>3</strong> días.
                                                </div>
                                            </div>

                                            <button type="button" class="btn btn-pay w-100 py-2"
                                                id="btnGenerarReferencia" onclick="generarReferenciaEfectivo()">
                                                <i class="fas fa-barcode me-2"></i> Generar referencia de pago
                                            </button>
                                        </div>

                                        <!-- VISTA 2: Resultado -->
                                        <div id="refResultView" class="payment-panel-body" style="display:none;">
                                            <div class="alert alert-success d-flex align-items-center gap-2 mb-3"
                                                style="border-radius:var(--lf-r);">
                                                <i class="fas fa-check-circle fa-lg"></i>
                                                <div>
                                                    <strong>¡Referencia generada con éxito!</strong><br>
                                                    <small>Preséntala en cualquier tienda afiliada para completar el
                                                        pago.</small>
                                                </div>
                                            </div>

                                            <div class="spei-clabe-box">
                                                <div class="spei-clabe-label">Referencia de pago</div>
                                                <div class="spei-clabe-value" id="refReferenceValue">—</div>
                                                <button class="btn btn-sm btn-outline-success mt-2"
                                                    onclick="copiarCLABE('refReferenceValue')">
                                                    <i class="fas fa-copy me-1"></i> Copiar referencia
                                                </button>
                                            </div>

                                            <div class="summary-row">
                                                <span class="label"><i class="fas fa-hashtag"></i> Folio</span>
                                                <span class="value" id="refFolio">—</span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label"><i class="fas fa-dollar-sign"></i> Monto</span>
                                                <span class="value" id="refMonto">—</span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label"><i class="fas fa-calendar-times"></i> Vence</span>
                                                <span class="value" id="refFechaExpiracion">—</span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label"><i class="fas fa-file-pdf"></i> Formato de
                                                    pago</span>
                                                <span class="value">
                                                    <a href="#" id="refPayformatLink" target="_blank"
                                                        class="btn btn-sm btn-success" style="border-radius:50px;">
                                                        <i class="fas fa-download me-1"></i> Descargar PDF
                                                    </a>
                                                </span>
                                            </div>

                                            <div class="text-center my-3" id="refBarcodeContainer"
                                                style="display:none;">
                                                <img id="refBarcodeImg" src="" alt="Código de barras" style="max-width:100%; height:auto; border:1px solid var(--lf-border);
                        border-radius:var(--lf-r); padding:8px; background:#fff;">
                                            </div>

                                            <div class="d-flex gap-2 mt-3">
                                                <button class="btn btn-outline-secondary flex-fill"
                                                    onclick="volverDeReferencia()" style="border-radius:50px;">
                                                    <i class="fas fa-arrow-left me-1"></i> Volver
                                                </button>
                                                <button class="btn btn-pay flex-fill"
                                                    onclick="window.open(document.getElementById('refPayformatLink').href,'_blank')">
                                                    <i class="fas fa-print me-1"></i> Imprimir ficha
                                                </button>
                                            </div>

                                            <div class="alert alert-warning mt-3 small mb-0"
                                                style="border-radius:var(--lf-r);">
                                                <i class="fas fa-clock me-1"></i>
                                                Estamos monitoreando el pago. Esta ventana se actualizará
                                                automáticamente
                                                cuando se acredite.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
                <!-- Fin checkout-row -->

            </div>
        </div>
    </main>

    <!-- Modal de términos -->
    <div class="modal fade" id="terminosModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-file-contract me-2"
                            style="color:var(--primary-color);"></i>Términos de Domiciliación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="font-size:14px; color:var(--lf-ink-2); line-height:1.8;">
                    <p><strong>Al domiciliar tu tarjeta, aceptas:</strong></p>
                    <ul>
                        <li>El cargo automático mensual por el monto correspondiente a tu plan.</li>
                        <li>Recibir notificaciones antes de cada cargo.</li>
                        <li>Puedes cancelar la domiciliación en cualquier momento.</li>
                        <li>Tus datos están protegidos bajo estándares de seguridad PCI-DSS.</li>
                    </ul>
                    <p class="mt-3 text-muted" style="font-size:13px;"><i class="fas fa-lock me-1"></i> No almacenamos
                        el número completo de tu tarjeta, solo un token seguro.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"
                        style="border-radius:50px;">Cerrar</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" style="border-radius:50px;"><i
                            class="fas fa-check me-1"></i> Acepto</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/planes.js"></script>
    <script>
        const phpData = {
            empresaId: '<?php echo $_SESSION['empresa_id']; ?>',
            empresaPlan: '<?php echo $empresa_plan; ?>',
            tieneDomiciliacion: <?php echo $tiene_domiciliacion ? 'true' : 'false'; ?>,
            tarjetaMask: '<?php echo $tarjeta_mask; ?>',
            planSeleccionado: '<?php echo $plan_key; ?>',
            periodo: '<?php echo $periodo; ?>',
            precio: <?php echo $precio; ?>,
            cargoAutomatico: <?php echo $cargo_automatico ? 'true' : 'false'; ?>,
            clienteEmail: '<?php echo htmlspecialchars($_SESSION['usuario_email'] ?? "cliente@libertyfin.com.mx"); ?>',
            clienteNombre: '<?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? "Cliente Libertyfin"); ?>'
        };

        // Guardar globalmente para que generarCLABE() los use
        window._clienteEmail = phpData.clienteEmail;
        window._clienteNombre = phpData.clienteNombre;

        document.addEventListener('DOMContentLoaded', function () {
            if (typeof inicializarCheckout === 'function') {
                inicializarCheckout(phpData);
            } else if (typeof inicializarSuscripciones === 'function') {
                inicializarSuscripciones(phpData);
            }
            const switchEl = document.getElementById('cargoAutomaticoSwitch');
            if (switchEl) {
                switchEl.addEventListener('change', toggleCargoAutomatico);
            }
            // Ejecutar al cargar la página por si acaso hay un valor preseleccionado
            if (typeof toggleFacturacion === 'function') {
                toggleFacturacion();
            }
        });
    </script>
</body>

</html>