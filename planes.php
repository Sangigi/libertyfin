<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: Login");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// Obtener datos de la empresa
$conn_main = getDBConnection();
$empresa_plan = "prueba";
if ($conn_main) {
    $stmt = $conn_main->prepare("SELECT plan FROM empresas WHERE id = ?");
    $stmt->execute([$_SESSION['empresa_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $empresa_plan = $row['plan'];
    $stmt = null;
    $conn_main = null;
}
$_SESSION['empresa_plan'] = $empresa_plan;

// Configuración de la empresa (logo, colores)
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
        $validas = ['jpg','jpeg','png','gif','webp','bmp'];
        if (in_array($ext, $validas)) {
            $logo_data = base64_encode(file_get_contents($logo_path));
            $logo_src_base64 = 'data:image/' . $ext . ';base64,' . $logo_data;
        }
    }
}

// ✅ CLAVES UNIFICADAS con checkout.php y planes.js
$planes = [
    'basico' => [
        'nombre' => 'Básico',
        'precio_mensual' => 299,
        'precio_anual' => 239,
        'usuarios' => 1,
        'cajas' => 1,
        'productos' => 100,
        'badge' => '',
        'popular' => false,
        'etiquetas' => ['Punto de Venta' => ['1 caja registradora', '100 productos', 'Pago en efectivo']]
    ],
    'starter' => [
        'nombre' => 'Profesional',
        'precio_mensual' => 599,
        'precio_anual' => 479,
        'usuarios' => 4,
        'cajas' => 2,
        'productos' => 500,
        'badge' => '',
        'popular' => false,
        'etiquetas' => ['Punto de Venta' => ['2 cajas registradoras', '500 productos', 'Pago en efectivo']]
    ],
    'emprendedor' => [
        'nombre' => 'Empresarial',
        'precio_mensual' => 999,
        'precio_anual' => 799,
        'usuarios' => 6,
        'cajas' => 3,
        'productos' => 500,
        'badge' => '⚡ Más popular',
        'popular' => true,
        'etiquetas' => [
            'Punto de Venta' => ['3 cajas registradoras', '1 sucursal', '500 productos'],
            'Pagos' => ['Pasarela de pago', 'SPEI']
        ]
    ],
    'premium' => [
        'nombre' => 'Empresarial Plus',
        'precio_mensual' => 1499,
        'precio_anual' => 1199,
        'usuarios' => 10,
        'cajas' => 10,
        'productos' => 'Ilimitados',
        'badge' => '',
        'popular' => false,
        'etiquetas' => [
            'Punto de Venta' => ['10 cajas registradoras', '3 sucursales', 'Productos ilimitados'],
            'Pagos' => ['Pasarela de pago', 'SPEI', 'Tarjeta de crédito'],
            'Facturación' => ['500 CFDI / Timbres']
        ]
    ]
];

// ✅ Badge que soporta claves nuevas Y antiguas (transición suave)
$plan_badge_class = match($empresa_plan) {
    'plus', 'premium'          => 'primary',
    'empresarial', 'emprendedor' => 'success',
    'profesional', 'stater'    => 'info',
    'basico'                   => 'warning',
    'prueba'                   => 'info',
    default                    => 'secondary'
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <title>Planes - <?php echo htmlspecialchars($nombre_empresa); ?></title>
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }
        main { margin-left: 0 !important; width: 100% !important; padding: 1.5rem 1.75rem !important; }
        .plans-inner { max-width: 1280px; margin: 0 auto; padding: 0 1rem; width: 100%; }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/crm-theme.css">
    <link rel="stylesheet" href="css/planes.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>" alt="<?php echo htmlspecialchars($nombre_empresa); ?>" class="me-2" style="height:32px; width:auto; border-radius:8px; object-fit:contain;">
                    <span><?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php echo $plan_badge_class; ?> ms-2" style="font-size:0.5rem;"><?php echo ucfirst($empresa_plan); ?></span>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2" style="font-size:1.2rem;"></i>
                    <span><?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php echo $plan_badge_class; ?> ms-2" style="font-size:0.5rem;"><?php echo ucfirst($empresa_plan); ?></span>
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
                        <li><span class="dropdown-item-text"><small>Empresa: <?php echo htmlspecialchars($nombre_empresa); ?></small></span></li>
                        <li><span class="dropdown-item-text"><small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="cuenta.php"><i class="fas fa-id-card me-2"></i>Mi Cuenta</a></li>
                        <li><a class="dropdown-item" href="planes.php"><i class="fas fa-rocket me-2"></i>Planes</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="cerrar_sesion"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <main>
        <div class="plans-section">
            <div class="plans-inner">
                <a href="cuenta.php" class="btn btn-outline-secondary btn-sm mb-3">
                    <i class="fas fa-arrow-left me-2"></i>Volver a Mi Cuenta
                </a>

                <h2 class="mb-2 text-center" style="color: var(--lf-ink); font-weight: 700;">
                    <i class="fas fa-rocket me-2" style="color: var(--primary-color);"></i>
                    Elige tu plan
                </h2>
                <p style="text-align: center; color: var(--lf-muted); margin-bottom: 0; font-size: 15px;">
                    Sin permanencia. Cancela cuando quieras.
                </p>

                <div class="pricing-toggle">
                    <span style="font-weight:600;">Mensual</span>
                    <div class="tog-track" id="togTrackIndex">
                        <div class="tog-thumb"></div>
                    </div>
                    <span style="font-weight:600;">Anual <span class="save-badge">–20%</span></span>
                </div>

                <div class="plans-grid">
                    <?php $i = 0; foreach ($planes as $key => $p): ?>
                    <div class="plan reveal <?php echo $i === 0 ? '' : 'reveal-d' . $i; ?><?php echo $p['popular'] ? ' popular' : ''; ?>" data-plan="<?php echo $key; ?>">
                        <?php if ($p['popular']): ?>
                            <div class="popular-badge"><?php echo $p['badge']; ?></div>
                        <?php endif; ?>
                        <div class="plan-name"><?php echo $p['nombre']; ?></div>
                        <div class="plan-price">
                            <sup>$</sup><span class="pv" data-m="<?php echo $p['precio_mensual']; ?>" data-a="<?php echo $p['precio_anual']; ?>"><?php echo $p['precio_mensual']; ?></span>
                        </div>
                        <div class="plan-period">MXN/mes · <?php echo $p['usuarios']; ?> usuario<?php echo $p['usuarios']>1?'s':''; ?></div>
                        <ul class="plan-lis">
                            <?php foreach ($p['etiquetas'] as $seccion => $items): ?>
                                <div class="plan-section-label"><?php echo $seccion; ?></div>
                                <?php foreach ($items as $item): ?>
                                    <li class="plan-li"><?php echo $item; ?></li>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </ul>
                        <a href="checkout?plan=<?php echo $key; ?>&periodo=mensual" class="plan-select-btn <?php echo $p['popular'] ? 'plan-select-btn-filled' : 'plan-select-btn-outline'; ?>">
                            <?php if ($p['popular']): ?>
                                <i class="fas fa-star"></i> Seleccionar
                            <?php else: ?>
                                <i class="fas fa-arrow-right"></i> Seleccionar
                            <?php endif; ?>
                        </a>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelector('.tog-track')?.addEventListener('click', function() {
            this.classList.toggle('annual');
            const isAnnual = this.classList.contains('annual');
            document.querySelectorAll('.plan').forEach(el => {
                const pv = el.querySelector('.pv');
                if (pv) {
                    const val = isAnnual ? pv.dataset.a : pv.dataset.m;
                    pv.textContent = parseInt(val).toLocaleString();
                }
            });
            document.querySelectorAll('.plan-select-btn').forEach(btn => {
                const href = btn.getAttribute('href');
                if (href) {
                    const newHref = href.replace(/periodo=[^&]*/, 'periodo=' + (isAnnual ? 'anual' : 'mensual'));
                    btn.setAttribute('href', newHref);
                }
            });
        });
    </script>
</body>
</html>