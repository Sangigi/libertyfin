<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: Login");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// ===== Definición de planes =====
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
    'profesional' => [
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
    'empresarial' => [
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
            'Pagos' => ['Pasarela de pago', 'SPEI / PayPal']
        ]
    ],
    'plus' => [
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
            'Pagos' => ['Pasarela de pago', 'SPEI / PayPal', 'Tarjeta de crédito'],
            'Facturación' => ['500 CFDI / Timbres']
        ]
    ]
];

// Alias: valores del enum `plan` en la BD -> claves de $planes
$plan_alias = [
    'prueba'      => null,
    'basico'      => 'basico',
    'starter'     => 'basico',
    'emprendedor' => 'profesional',
    'premium'     => 'empresarial',
    'profesional' => 'profesional',
    'empresarial' => 'empresarial',
    'plus'        => 'plus',
];

// Obtener datos de la empresa
$conn_main = getDBConnection();
$empresa_plan = "prueba";
$empresa_fecha_vencimiento = null;
$empresa_activo = 1;

if ($conn_main) {
    $stmt = $conn_main->prepare("SELECT plan, fecha_vencimiento, activo FROM empresas WHERE id = ?");
    $stmt->execute([$_SESSION['empresa_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $empresa_plan              = $row['plan'] ?? 'prueba';
        $empresa_fecha_vencimiento = $row['fecha_vencimiento'] ?? null;
        $empresa_activo            = (int)($row['activo'] ?? 1);
    }
    $stmt = null;
    $conn_main = null;
}
$_SESSION['empresa_plan'] = $empresa_plan;

// Obtener info del plan usando el alias
$plan_key  = $plan_alias[$empresa_plan] ?? null;
$plan_info = null;

if ($empresa_plan === 'prueba') {
    $plan_info = [
        'nombre'    => 'Prueba',
        'usuarios'  => 1,
        'cajas'     => 1,
        'productos' => 50,
        'etiquetas' => ['Prueba' => ['Plan de prueba', 'Acceso temporal']],
        'badge'     => '',
        'popular'   => false,
    ];
} elseif ($plan_key && isset($planes[$plan_key])) {
    $plan_info = $planes[$plan_key];
} else {
    $plan_info = [
        'nombre'    => ucfirst($empresa_plan),
        'usuarios'  => 1,
        'cajas'     => 1,
        'productos' => '—',
        'etiquetas' => [],
        'badge'     => '',
        'popular'   => false,
    ];
}

// Cálculo de días restantes
$dias_restantes = null;
$vencido = false;
$por_vencer = false;
$fecha_venc_fmt = 'Sin fecha definida';

if (!empty($empresa_fecha_vencimiento)) {
    try {
        $hoy = new DateTime('today');
        $fv  = new DateTime($empresa_fecha_vencimiento);
        $dias_restantes = (int)$hoy->diff($fv)->format('%r%a');
        $vencido = $dias_restantes < 0;
        $por_vencer = (!$vencido && $dias_restantes <= 7);
        $fecha_venc_fmt = $fv->format('d/m/Y');
    } catch (Exception $e) {
        $fecha_venc_fmt = htmlspecialchars($empresa_fecha_vencimiento);
    }
}

$plan_badge_color = match($empresa_plan) {
    'premium', 'plus', 'empresarial' => 'primary',
    'emprendedor', 'profesional'     => 'success',
    'starter'                        => 'info',
    'basico'                         => 'warning',
    'prueba'                         => 'secondary',
    default                          => 'secondary',
};

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <title>Mi cuenta - <?php echo htmlspecialchars($nombre_empresa); ?></title>
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }
        main { margin-left: 0 !important; width: 100% !important; padding: 1.5rem 1.75rem !important; }
        .plans-inner { max-width: 1280px; margin: 0 auto; padding: 0 1rem; width: 100%; }

        .plan-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .plan-features .grupo-titulo {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--lf-muted, #6b7d76);
            margin: 0.75rem 0 0.35rem;
        }
        .plan-features li.item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.25rem 0;
            font-size: 0.9rem;
            color: var(--lf-ink-2, #384843);
        }
        .plan-features li.item i {
            color: var(--primary-color);
            margin-top: 0.2rem;
            font-size: 0.8rem;
        }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/crm-theme.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">   
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>" alt="<?php echo htmlspecialchars($nombre_empresa); ?>" class="me-2" style="height:32px; width:auto; border-radius:8px; object-fit:contain;">
                    <span><?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php echo match($empresa_plan) { 'premium'=>'primary', 'emprendedor'=>'success', 'basico'=>'warning', 'prueba'=>'info', default=>'secondary' }; ?> ms-2" style="font-size:0.5rem;"><?php echo ucfirst($empresa_plan); ?></span>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2" style="font-size:1.2rem;"></i>
                    <span><?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php echo match($empresa_plan) { 'premium'=>'primary', 'emprendedor'=>'success', 'basico'=>'warning', 'prueba'=>'info', default=>'secondary' }; ?> ms-2" style="font-size:0.5rem;"><?php echo ucfirst($empresa_plan); ?></span>
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
                        <li><a class="dropdown-item" href="cerrar_sesion"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <main>
        <div class="plans-inner">
            <h1 class="page-title mb-4"><i class="fas fa-id-card me-2"></i>Mi cuenta</h1>

            <!-- Plan actual -->
            <div class="mb-4">
                <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
                    <h4 class="mb-0">Plan actual:</h4>
                    <span class="badge bg-<?php echo $plan_badge_color; ?> text-uppercase fs-6">
                        <?php echo htmlspecialchars($plan_info['nombre']); ?>
                    </span>
                    <?php if (!empty($plan_info['badge'])): ?>
                        <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($plan_info['badge']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-4 mb-3">
                    <div>
                        <div class="metric-label">Usuarios</div>
                        <div class="fs-4 fw-bold"><?php echo (int)$plan_info['usuarios']; ?></div>
                    </div>
                    <div>
                        <div class="metric-label">Cajas</div>
                        <div class="fs-4 fw-bold"><?php echo (int)$plan_info['cajas']; ?></div>
                    </div>
                    <div>
                        <div class="metric-label">Productos</div>
                        <div class="fs-4 fw-bold">
                            <?php echo is_numeric($plan_info['productos']) ? (int)$plan_info['productos'] : htmlspecialchars($plan_info['productos']); ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($plan_info['etiquetas'])): ?>
                    <div class="plan-features">
                        <?php foreach ($plan_info['etiquetas'] as $grupo => $items): ?>
                            <div class="grupo-titulo"><?php echo htmlspecialchars($grupo); ?></div>
                            <?php foreach ($items as $item): ?>
                                <li class="item">
                                    <i class="fas fa-check"></i>
                                    <span><?php echo htmlspecialchars($item); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <hr>

            <!-- Vencimiento -->
            <div class="mb-4">
                <h4 class="mb-2"><i class="fas fa-calendar-check me-2 text-primary"></i>Vencimiento</h4>
                <p class="mb-2 fs-5">
                    <strong><?php echo htmlspecialchars($fecha_venc_fmt); ?></strong>
                </p>

                <?php if ($dias_restantes !== null): ?>
                    <?php if ($vencido): ?>
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-triangle-exclamation me-2"></i>
                            Tu plan venció hace <strong><?php echo abs($dias_restantes); ?> día(s)</strong>.
                        </div>
                    <?php elseif ($por_vencer): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-clock me-2"></i>
                            Tu plan vence en <strong><?php echo $dias_restantes; ?> día(s)</strong>.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success mb-0">
                            <i class="fas fa-circle-check me-2"></i>
                            Quedan <strong><?php echo $dias_restantes; ?> día(s)</strong> de servicio.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Aún no hay fecha de vencimiento registrada.
                    </div>
                <?php endif; ?>
            </div>

            <a href="planes" class="btn btn-primary">
                <i class="fas fa-arrow-up-right-dots me-2"></i>Cambiar / renovar plan
            </a>
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