<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: Login");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/includes/mi_cuenta_pago.php';

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
$datos_pago = [];
$documentos_comercio = [];
if ($conn) {
    asegurar_tablas_pago($conn);

    $sql = "SELECT * FROM sistema_config LIMIT 1";
    $res = $conn->query($sql);
    $config = $res->fetch(PDO::FETCH_ASSOC) ?: [];

    $res = $conn->query("SELECT * FROM datos_pago_comercio LIMIT 1");
    $datos_pago = $res->fetch(PDO::FETCH_ASSOC) ?: [];

    $res = $conn->query("SELECT * FROM documentos_comercio");
    foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $documentos_comercio[$doc['tipo']] = $doc;
    }

    $conn = null;
}
$nombre_empresa = $config['nombre_empresa'] ?? $_SESSION['empresa_nombre'] ?? 'Mi Empresa';
$color_primario = $config['color_primario'] ?? '#27ae60';
$color_secundario = $config['color_secundario'] ?? '#2ecc71';

$documentacion_estado = $config['documentacion_estado'] ?? 'sin_enviar';
$estado_doc_labels = [
    'sin_enviar'  => ['texto' => 'Sin documentos', 'clase' => 'secondary'],
    'en_revision' => ['texto' => 'En revisión',    'clase' => 'warning'],
    'aprobada'    => ['texto' => 'Documentación aprobada', 'clase' => 'success'],
    'rechazada'   => ['texto' => 'Documentación rechazada', 'clase' => 'danger'],
];
$estado_doc_info = $estado_doc_labels[$documentacion_estado] ?? $estado_doc_labels['sin_enviar'];

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
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
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

            <a href="planes.php" class="btn btn-primary">
                <i class="fas fa-arrow-up-right-dots me-2"></i>Cambiar / renovar plan
            </a>

            <hr class="my-4">

            <!-- ====================== DATOS FISCALES ====================== -->
            <div class="card mb-4" id="card-datos-fiscales">
                <div class="card-body">
                    <h4 class="mb-1"><i class="fas fa-file-invoice me-2 text-primary"></i>Datos fiscales</h4>
                    <p class="text-muted mb-3">Necesarios para poder facturar los cobros de tu negocio.</p>

                    <div id="msg-fiscal" class="alert d-none" role="alert"></div>

                    <form id="form-datos-fiscales" class="row g-3" style="max-width: 720px;">
                        <div class="col-md-4">
                            <label class="form-label">Tipo de persona</label>
                            <select class="form-select" name="tipo_persona" id="tipo_persona">
                                <option value="" <?php echo empty($config['tipo_persona']) ? 'selected' : ''; ?>>Sin definir</option>
                                <option value="fisica" <?php echo (($config['tipo_persona'] ?? '') === 'fisica') ? 'selected' : ''; ?>>Persona física</option>
                                <option value="moral" <?php echo (($config['tipo_persona'] ?? '') === 'moral') ? 'selected' : ''; ?>>Persona moral</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">RFC</label>
                            <input type="text" class="form-control" name="rfc" maxlength="13"
                                   value="<?php echo htmlspecialchars($config['rfc'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Código postal fiscal</label>
                            <input type="text" class="form-control" name="cp_fiscal" maxlength="5" inputmode="numeric"
                                   value="<?php echo htmlspecialchars($config['cp_fiscal'] ?? ''); ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Razón social</label>
                            <input type="text" class="form-control" name="razon_social" placeholder="Nombre legal completo"
                                   value="<?php echo htmlspecialchars($config['razon_social'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Régimen fiscal (clave SAT)</label>
                            <input type="text" class="form-control" name="regimen_fiscal" maxlength="10" placeholder="601, 612, 626…"
                                   value="<?php echo htmlspecialchars($config['regimen_fiscal'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary">
                                <span class="btn-text"><i class="fas fa-floppy-disk me-2"></i>Guardar datos fiscales</span>
                                <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2"></span>Guardando…</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ============== DATOS PARA PROCESAR PAGOS REALES ============== -->
            <div class="card mb-4" id="card-datos-pago">
                <div class="card-body">
                    <h4 class="mb-1"><i class="fas fa-credit-card me-2 text-primary"></i>Datos para procesar pagos reales</h4>
                    <p class="text-muted mb-3">
                        Formulario de alta de comercio para poder cobrar de verdad a tus clientes con tarjeta o SPEI.
                    </p>

                    <div id="msg-pago" class="alert d-none" role="alert"></div>

                    <form id="form-datos-pago">
                        <input type="hidden" name="tipo_persona_actual" id="tipo_persona_actual"
                               value="<?php echo htmlspecialchars($config['tipo_persona'] ?? ''); ?>">

                        <div class="mb-2 fw-bold text-uppercase text-muted" style="font-size: .75rem; letter-spacing: .05em;">Datos generales del titular</div>
                        <div class="row g-3 mb-4">
                            <?php
                            $campos_titular = [
                                ['titular_nombre', 'Nombre del titular (como aparece en el estado de cuenta)', 6],
                                ['nombre_comercio', 'Nombre de sucursal (nombre del comercio)', 6],
                                ['titular_correo', 'Correo', 4, 'email'],
                                ['giro', 'Actividad o giro', 4],
                                ['telefono_celular', 'Teléfono celular', 4, 'tel'],
                                ['telefono_oficina', 'Teléfono oficina', 4, 'tel'],
                                ['calle_numero', 'Calle y número exterior', 6],
                                ['numero_interior', 'Número interior', 3],
                                ['colonia', 'Colonia', 3],
                                ['delegacion_municipio', 'Delegación o municipio', 4],
                                ['ciudad', 'Ciudad', 4],
                                ['estado_direccion', 'Estado', 4],
                                ['pais', 'País', 4],
                                ['nombre_vendedor', 'Nombre vendedor', 4],
                            ];
                            foreach ($campos_titular as $c) {
                                $key = $c[0]; $label = $c[1]; $cols = $c[2]; $tipo = $c[3] ?? 'text';
                                $val = htmlspecialchars($datos_pago[$key] ?? ($key === 'pais' ? 'México' : ''));
                                echo "<div class=\"col-md-$cols\"><label class=\"form-label\">$label</label>"
                                   . "<input type=\"$tipo\" class=\"form-control\" name=\"$key\" value=\"$val\"></div>";
                            }
                            ?>
                        </div>

                        <div class="mb-2 fw-bold text-uppercase text-muted" style="font-size: .75rem; letter-spacing: .05em;">Datos del representante legal</div>
                        <div class="row g-3 mb-4">
                            <?php
                            $campos_rep = [
                                ['rep_legal_nombre', 'Nombre completo', 6],
                                ['rep_legal_escritura', 'Número y fecha de escritura', 6],
                                ['rep_legal_notaria_numero', 'Notaría número', 3],
                                ['rep_legal_notario_nombre', 'Nombre del notario', 5],
                                ['rep_legal_ciudad', 'Ciudad', 4],
                            ];
                            foreach ($campos_rep as $c) {
                                $key = $c[0]; $label = $c[1]; $cols = $c[2];
                                $val = htmlspecialchars($datos_pago[$key] ?? '');
                                echo "<div class=\"col-md-$cols\"><label class=\"form-label\">$label</label>"
                                   . "<input type=\"text\" class=\"form-control\" name=\"$key\" value=\"$val\"></div>";
                            }
                            ?>
                        </div>

                        <div class="mb-4" id="bloque-persona-moral"
                             style="<?php echo (($config['tipo_persona'] ?? '') === 'moral') ? '' : 'display:none;'; ?>">
                            <div class="mb-2 fw-bold text-uppercase text-muted" style="font-size: .75rem; letter-spacing: .05em;">
                                Datos de la empresa <span class="fw-normal normal-case text-muted">(solo persona moral)</span>
                            </div>
                            <div class="row g-3">
                                <?php
                                $campos_empresa = [
                                    ['empresa_escritura', 'Número de escritura y fecha', 6],
                                    ['empresa_folio_rpc', 'Folio del registro público del comercio', 6],
                                    ['empresa_ciudad', 'Ciudad', 4],
                                    ['empresa_notario_nombre', 'Nombre del notario', 5],
                                    ['empresa_notaria_numero', 'Notaría número', 3],
                                ];
                                foreach ($campos_empresa as $c) {
                                    $key = $c[0]; $label = $c[1]; $cols = $c[2];
                                    $val = htmlspecialchars($datos_pago[$key] ?? '');
                                    echo "<div class=\"col-md-$cols\"><label class=\"form-label\">$label</label>"
                                       . "<input type=\"text\" class=\"form-control\" name=\"$key\" value=\"$val\"></div>";
                                }
                                ?>
                            </div>
                        </div>

                        <div class="mb-2 fw-bold text-uppercase text-muted" style="font-size: .75rem; letter-spacing: .05em;">Identificación del titular o representante legal</div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <label class="form-label">Tipo de identificación</label>
                                <input type="text" class="form-control" name="id_tipo" placeholder="INE, pasaporte…"
                                       value="<?php echo htmlspecialchars($datos_pago['id_tipo'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Número</label>
                                <input type="text" class="form-control" name="id_numero"
                                       value="<?php echo htmlspecialchars($datos_pago['id_numero'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fecha expedición</label>
                                <input type="date" class="form-control" name="id_fecha_expedicion"
                                       value="<?php echo htmlspecialchars($datos_pago['id_fecha_expedicion'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Vigencia</label>
                                <input type="date" class="form-control" name="id_vigencia"
                                       value="<?php echo htmlspecialchars($datos_pago['id_vigencia'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="mb-2 fw-bold text-uppercase text-muted" style="font-size: .75rem; letter-spacing: .05em;">Datos bancarios</div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Banco</label>
                                <input type="text" class="form-control" name="banco"
                                       value="<?php echo htmlspecialchars($datos_pago['banco'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Plaza</label>
                                <input type="text" class="form-control" name="plaza"
                                       value="<?php echo htmlspecialchars($datos_pago['plaza'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sucursal</label>
                                <input type="text" class="form-control" name="sucursal_bancaria"
                                       value="<?php echo htmlspecialchars($datos_pago['sucursal_bancaria'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cuenta cheques</label>
                                <input type="text" class="form-control" name="cuenta_cheques"
                                       value="<?php echo htmlspecialchars($datos_pago['cuenta_cheques'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cuenta CLABE</label>
                                <input type="text" class="form-control" name="cuenta_clabe" maxlength="18" inputmode="numeric"
                                       value="<?php echo htmlspecialchars($datos_pago['cuenta_clabe'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="p-3 bg-light rounded mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="acepta_clausulado" name="acepta_clausulado"
                                       <?php echo !empty($datos_pago['clausulado_aceptado_en']) ? 'checked disabled' : ''; ?>>
                                <label class="form-check-label" for="acepta_clausulado" style="font-size: .9rem;">
                                    Acepto que he leído y estoy de acuerdo con el
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#modalClausulado">clausulado del contrato de procesamiento de transacciones</a>.
                                    (Indispensable aceptarlo para enviar esta información)
                                </label>
                            </div>
                            <?php if (!empty($datos_pago['clausulado_aceptado_en'])): ?>
                                <div class="text-success mt-1" style="font-size: .8rem;">
                                    <i class="fas fa-circle-check me-1"></i>Aceptado el
                                    <?php echo htmlspecialchars(date('d/m/Y', strtotime($datos_pago['clausulado_aceptado_en']))); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-outline-primary">
                            <span class="btn-text"><i class="fas fa-floppy-disk me-2"></i>Guardar datos de alta de comercio</span>
                            <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2"></span>Guardando…</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Modal: clausulado -->
            <div class="modal fade" id="modalClausulado" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Clausulado del contrato</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" style="font-size: .9rem;">
                            Pendiente: aquí va el texto del clausulado del contrato de procesamiento de
                            transacciones (o la liga oficial a su aviso legal) que te proporcione tu
                            proveedor de pagos.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====================== DOCUMENTOS ====================== -->
            <div class="card mb-4" id="card-documentos">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <h4 class="mb-1"><i class="fas fa-folder-open me-2 text-primary"></i>Documentos</h4>
                        <span class="badge bg-<?php echo $estado_doc_info['clase']; ?>" id="badge-estado-documentacion">
                            <?php echo htmlspecialchars($estado_doc_info['texto']); ?>
                        </span>
                    </div>
                    <p class="text-muted mb-3">JPG, PNG o PDF, peso máximo 10MB. Un administrador de LibertyFin los revisa en 24-72 horas.</p>

                    <div id="msg-docs" class="alert d-none" role="alert"></div>

                    <div class="d-flex flex-column gap-2">
                        <?php foreach (MCP_TIPOS_DOCUMENTO as $tipo => $label):
                            $doc = $documentos_comercio[$tipo] ?? null;
                            $estado_badge = match($doc['estado'] ?? null) {
                                'aprobado' => ['success', 'Aprobado'],
                                'rechazado' => ['danger', 'Rechazado'],
                                'pendiente' => ['warning', 'En revisión'],
                                default => null,
                            };
                        ?>
                        <div class="d-flex align-items-center justify-content-between gap-2 p-2 border rounded doc-row" data-tipo="<?php echo $tipo; ?>">
                            <div>
                                <div style="font-weight: 600; font-size: .9rem;"><?php echo htmlspecialchars($label); ?></div>
                                <div class="doc-estado mt-1">
                                    <?php if ($estado_badge): ?>
                                        <span class="badge bg-<?php echo $estado_badge[0]; ?>"><?php echo $estado_badge[1]; ?></span>
                                        <?php if (($doc['estado'] ?? '') === 'rechazado' && !empty($doc['motivo_rechazo'])): ?>
                                            <span class="text-danger ms-2" style="font-size: .8rem;"><?php echo htmlspecialchars($doc['motivo_rechazo']); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($doc): ?>
                                    <a href="descargar_documento_empresa.php?tipo=<?php echo urlencode($tipo); ?>"
                                       class="btn btn-sm btn-outline-secondary" target="_blank">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                <?php endif; ?>
                                <label class="btn btn-sm btn-secondary mb-0 btn-subir-doc">
                                    <span class="btn-text"><?php echo $doc ? 'Volver a subir' : 'Subir'; ?></span>
                                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                    <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="d-none input-subir-doc">
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
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

        // ====================== Mi cuenta: datos fiscales / pago / documentos ======================
        (function () {
            function mostrarMensaje(el, ok, texto) {
                el.textContent = texto;
                el.classList.remove('d-none', 'alert-success', 'alert-danger');
                el.classList.add(ok ? 'alert-success' : 'alert-danger');
            }

            function setCargando(boton, cargando) {
                boton.disabled = cargando;
                boton.querySelector('.btn-text')?.classList.toggle('d-none', cargando);
                boton.querySelector('.btn-loading')?.classList.toggle('d-none', !cargando);
            }

            // ---- Datos fiscales ----
            const formFiscal = document.getElementById('form-datos-fiscales');
            const msgFiscal = document.getElementById('msg-fiscal');
            const tipoPersonaSelect = document.getElementById('tipo_persona');
            const tipoPersonaActualInput = document.getElementById('tipo_persona_actual');
            const bloquePersonaMoral = document.getElementById('bloque-persona-moral');

            tipoPersonaSelect?.addEventListener('change', () => {
                bloquePersonaMoral.style.display = tipoPersonaSelect.value === 'moral' ? '' : 'none';
            });

            formFiscal?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const boton = formFiscal.querySelector('button[type="submit"]');
                setCargando(boton, true);
                msgFiscal.classList.add('d-none');
                try {
                    const datos = Object.fromEntries(new FormData(formFiscal).entries());
                    const r = await fetch('guardar_datos_fiscales_empresa.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(datos)
                    });
                    const res = await r.json();
                    if (!res.success) throw new Error(res.message || 'No se pudo guardar');
                    mostrarMensaje(msgFiscal, true, 'Datos fiscales guardados.');
                    if (tipoPersonaActualInput) tipoPersonaActualInput.value = datos.tipo_persona || '';
                } catch (err) {
                    mostrarMensaje(msgFiscal, false, err.message);
                } finally {
                    setCargando(boton, false);
                }
            });

            // ---- Datos de pago (alta de comercio) ----
            const formPago = document.getElementById('form-datos-pago');
            const msgPago = document.getElementById('msg-pago');
            const aceptaClausulado = document.getElementById('acepta_clausulado');

            formPago?.addEventListener('submit', async (e) => {
                e.preventDefault();

                if (!aceptaClausulado.checked && !aceptaClausulado.disabled) {
                    mostrarMensaje(msgPago, false, 'Debes aceptar el clausulado del contrato de procesamiento de transacciones para enviar esta información.');
                    return;
                }

                const boton = formPago.querySelector('button[type="submit"]');
                setCargando(boton, true);
                msgPago.classList.add('d-none');
                try {
                    const datos = Object.fromEntries(new FormData(formPago).entries());
                    datos.acepta_clausulado = aceptaClausulado.checked || aceptaClausulado.disabled ? '1' : '0';
                    datos.tipo_persona = tipoPersonaActualInput ? tipoPersonaActualInput.value : '';

                    const r = await fetch('guardar_datos_pago_empresa.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(datos)
                    });
                    const res = await r.json();
                    if (!res.success) throw new Error(res.message || 'No se pudo guardar');
                    mostrarMensaje(msgPago, true, 'Datos de alta de comercio guardados.');
                    aceptaClausulado.checked = true;
                    aceptaClausulado.disabled = true;
                } catch (err) {
                    mostrarMensaje(msgPago, false, err.message);
                } finally {
                    setCargando(boton, false);
                }
            });

            // ---- Documentos ----
            const msgDocs = document.getElementById('msg-docs');
            const badgeEstadoDoc = document.getElementById('badge-estado-documentacion');
            const badgeClases = { pendiente: 'warning', aprobado: 'success', rechazado: 'danger' };
            const badgeTextos = { pendiente: 'En revisión', aprobado: 'Aprobado', rechazado: 'Rechazado' };

            document.querySelectorAll('.input-subir-doc').forEach((input) => {
                input.addEventListener('change', async () => {
                    const archivo = input.files && input.files[0];
                    input.value = '';
                    if (!archivo) return;

                    const fila = input.closest('.doc-row');
                    const tipo = fila.dataset.tipo;
                    const boton = input.closest('.btn-subir-doc');
                    setCargando(boton, true);
                    msgDocs.classList.add('d-none');

                    try {
                        const fd = new FormData();
                        fd.append('tipo', tipo);
                        fd.append('archivo', archivo);
                        const r = await fetch('subir_documento_empresa.php', { method: 'POST', body: fd });
                        const res = await r.json();
                        if (!res.success) throw new Error(res.message || 'No se pudo subir el documento');

                        const estadoDiv = fila.querySelector('.doc-estado');
                        estadoDiv.innerHTML = '<span class="badge bg-' + badgeClases[res.estado] + '">' + badgeTextos[res.estado] + '</span>';
                        boton.querySelector('.btn-text').textContent = 'Volver a subir';

                        if (badgeEstadoDoc) {
                            badgeEstadoDoc.className = 'badge bg-warning';
                            badgeEstadoDoc.textContent = 'En revisión';
                        }
                    } catch (err) {
                        mostrarMensaje(msgDocs, false, err.message);
                    } finally {
                        setCargando(boton, false);
                    }
                });
            });
        })();
    </script>
</body>
</html>