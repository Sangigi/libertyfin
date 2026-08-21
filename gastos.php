<?php
// gastos.php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

$mensaje = '';
$tipo_mensaje = '';

$registros_por_pagina = 15;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Categorías predefinidas para gastos manuales (además de "Costo de venta", que es automática)
$categorias_gasto = [
    'Renta', 'Nómina', 'Servicios (luz, agua, internet)', 'Mantenimiento',
    'Insumos', 'Transporte', 'Marketing', 'Impuestos', 'Otros'
];

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // === Obtener plan/colores/logo de la empresa (igual que en otras páginas) ===
    $conn_main = getDBConnection();
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
    $_SESSION['empresa_plan'] = $empresa_plan;

    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch(PDO::FETCH_ASSOC);

    $logo_empresa = null;
    $logo_src_base64 = null;
    if (!empty($empresa_info['logo'])) {
        $empresa_logo = $empresa_info['logo'];
        $logo_path = '';
        $rutas_posibles = [
            $empresa_logo, '../' . $empresa_logo, '../../' . $empresa_logo,
            'admin/' . $empresa_logo, '../admin/' . $empresa_logo,
            'logos/' . $empresa_logo, 'img/' . $empresa_logo, 'images/' . $empresa_logo,
            'assets/' . $empresa_logo, 'uploads/' . $empresa_logo,
            '../logos/' . $empresa_logo, '../img/' . $empresa_logo,
            '../images/' . $empresa_logo, '../assets/' . $empresa_logo, '../uploads/' . $empresa_logo
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

    $color_primario = $empresa_info['color_primario'] ?? '#27ae60';
    $color_secundario = $empresa_info['color_secundario'] ?? '#2ecc71';

    // Sucursales para filtro/formulario
    $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre";
    $result_sucursales = $conn->query($sql_sucursales);
    $sucursales = $result_sucursales->fetchAll(PDO::FETCH_ASSOC);

    // === Procesar acciones POST: guardar (nuevo/editar) y eliminar gasto manual ===
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_gasto') {
            $gasto_id = isset($_POST['gasto_id']) ? (int)$_POST['gasto_id'] : 0;
            $concepto = trim($_POST['concepto'] ?? '');
            $categoria = trim($_POST['categoria'] ?? '') ?: 'Otros';
            $monto = isset($_POST['monto']) ? (float)str_replace(',', '', $_POST['monto']) : 0;
            $fecha = trim($_POST['fecha'] ?? '') ?: date('Y-m-d H:i:s');
            $sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
            $metodo_pago = trim($_POST['metodo_pago'] ?? '') ?: null;
            $descripcion = trim($_POST['descripcion'] ?? '') ?: null;
            $proveedor = trim($_POST['proveedor'] ?? '') ?: null;
            $numero_referencia = trim($_POST['numero_referencia'] ?? '') ?: null;

            if ($concepto === '' || $monto <= 0) {
                $mensaje = 'El concepto y el monto (mayor a 0) son obligatorios.';
                $tipo_mensaje = 'danger';
            } else {
                if ($gasto_id > 0) {
                    // Editar: solo se permite editar gastos manuales
                    $sql = "UPDATE gastos SET concepto = ?, categoria = ?, monto = ?, fecha = ?, sucursal_id = ?, metodo_pago = ?, descripcion = ?, proveedor = ?, numero_referencia = ?
                            WHERE id = ? AND tipo = 'manual'";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$concepto, $categoria, $monto, $fecha, $sucursal_id, $metodo_pago, $descripcion, $proveedor, $numero_referencia, $gasto_id]);
                    $mensaje = 'Gasto actualizado correctamente.';
                } else {
                    $sql = "INSERT INTO gastos (concepto, categoria, monto, tipo, origen, usuario_id, sucursal_id, metodo_pago, descripcion, fecha, proveedor, numero_referencia)
                            VALUES (?, ?, ?, 'manual', 'manual', ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$concepto, $categoria, $monto, $_SESSION['usuario_id'], $sucursal_id, $metodo_pago, $descripcion, $fecha, $proveedor, $numero_referencia]);
                    $mensaje = 'Gasto registrado correctamente.';
                }
                $tipo_mensaje = 'success';
            }
        } elseif (isset($_POST['accion']) && $_POST['accion'] === 'eliminar_gasto') {
            $gasto_id = (int)($_POST['gasto_id'] ?? 0);
            if ($gasto_id > 0) {
                // Solo se permite eliminar gastos manuales; los automáticos se eliminan junto con la venta
                $stmt = $conn->prepare("DELETE FROM gastos WHERE id = ? AND tipo = 'manual'");
                $stmt->execute([$gasto_id]);
                $mensaje = 'Gasto eliminado correctamente.';
                $tipo_mensaje = 'success';
            }
        }

        // Redirigir para evitar reenvío del formulario, conservando filtros
        $query_redirect = $_GET;
        $_SESSION['gastos_mensaje'] = $mensaje;
        $_SESSION['gastos_tipo_mensaje'] = $tipo_mensaje;
        header("Location: gastos.php" . (!empty($query_redirect) ? '?' . http_build_query($query_redirect) : ''));
        exit();
    }

    if (isset($_SESSION['gastos_mensaje'])) {
        $mensaje = $_SESSION['gastos_mensaje'];
        $tipo_mensaje = $_SESSION['gastos_tipo_mensaje'];
        unset($_SESSION['gastos_mensaje'], $_SESSION['gastos_tipo_mensaje']);
    }

    // === Filtros ===
    $filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
    $filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';
    $filtro_categoria = $_GET['categoria'] ?? '';
    $filtro_tipo = $_GET['tipo'] ?? '';
    $filtro_sucursal = $_GET['sucursal'] ?? '';
    $filtro_orden = $_GET['orden'] ?? 'desc';
    if (!in_array($filtro_orden, ['asc', 'desc'])) {
        $filtro_orden = 'desc';
    }

    $where_conditions = [];
    $params = [];

    if (!empty($filtro_fecha_desde)) {
        $where_conditions[] = "DATE(g.fecha) >= ?";
        $params[] = $filtro_fecha_desde;
    }
    if (!empty($filtro_fecha_hasta)) {
        $where_conditions[] = "DATE(g.fecha) <= ?";
        $params[] = $filtro_fecha_hasta;
    }
    if (!empty($filtro_categoria)) {
        $where_conditions[] = "g.categoria = ?";
        $params[] = $filtro_categoria;
    }
    if (!empty($filtro_tipo)) {
        $where_conditions[] = "g.tipo = ?";
        $params[] = $filtro_tipo;
    }
    if (!empty($filtro_sucursal)) {
        $where_conditions[] = "g.sucursal_id = ?";
        $params[] = $filtro_sucursal;
    }

    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }

    // Total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM gastos g $where_clause";
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->execute($params);
    $total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
    $stmt_count = null;

    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    $sql_gastos = "
        SELECT g.*, s.nombre as sucursal_nombre, u.nombre as usuario_nombre, v.codigo_venta
        FROM gastos g
        LEFT JOIN sucursales s ON g.sucursal_id = s.id
        LEFT JOIN usuarios u ON g.usuario_id = u.id
        LEFT JOIN ventas v ON g.venta_id = v.id
        $where_clause
        ORDER BY g.fecha $filtro_orden, g.id $filtro_orden
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql_gastos);
    $all_params = $params;
    $all_params[] = $registros_por_pagina;
    $all_params[] = $offset;
    $stmt->execute($all_params);
    $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null;

    // Estadísticas del periodo filtrado (sin paginación)
    $sql_stats = "
        SELECT
            COUNT(*) as total_gastos,
            COALESCE(SUM(monto), 0) as monto_total,
            COALESCE(SUM(CASE WHEN tipo = 'automatico' THEN monto ELSE 0 END), 0) as monto_automatico,
            COALESCE(SUM(CASE WHEN tipo = 'manual' THEN monto ELSE 0 END), 0) as monto_manual
        FROM gastos g
        $where_clause
    ";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->execute($params);
    $stats_gastos = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    $stmt_stats = null;

    // Categorías realmente usadas (para el filtro, incluye "Costo de venta")
    $sql_categorias_usadas = "SELECT DISTINCT categoria FROM gastos ORDER BY categoria";
    $categorias_usadas = $conn->query($sql_categorias_usadas)->fetchAll(PDO::FETCH_COLUMN);

    $conn = null;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

function safe_html_gasto($value) {
    return htmlspecialchars($value ?? '');
}

$is_admin = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Gastos - <?php echo safe_html_gasto($_SESSION['empresa_nombre'] ?? ''); ?></title>
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/crm-theme.css">
</head>

<body>
    <!-- Navbar -->
<?php include 'includes/navbar.php'; ?>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar (exactamente igual a dashboard.php) -->
             <?php include 'includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <h2 class="mb-0 fs-4 fs-md-3">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        Gastos
                    </h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalGasto" onclick="prepararNuevoGasto()">
                        <i class="fas fa-plus me-1"></i>Nuevo Gasto
                    </button>
                </div>

                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                        <?php echo safe_html_gasto($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Tarjetas de estadísticas -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title text-muted mb-1 small">Total del periodo</h6>
                                        <h3 class="mb-0 text-primary">$<?php echo number_format($stats_gastos['monto_total'], 2); ?></h3>
                                    </div>
                                    <i class="fas fa-wallet fa-2x text-primary opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title text-muted mb-1 small">Automáticos</h6>
                                        <h3 class="mb-0 text-info">$<?php echo number_format($stats_gastos['monto_automatico'], 2); ?></h3>
                                    </div>
                                    <i class="fas fa-robot fa-2x text-info opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title text-muted mb-1 small">Manuales</h6>
                                        <h3 class="mb-0 text-success">$<?php echo number_format($stats_gastos['monto_manual'], 2); ?></h3>
                                    </div>
                                    <i class="fas fa-hand-holding-usd fa-2x text-success opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title text-muted mb-1 small">Registros</h6>
                                        <h3 class="mb-0 text-warning"><?php echo (int)$stats_gastos['total_gastos']; ?></h3>
                                    </div>
                                    <i class="fas fa-list fa-2x text-warning opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" id="filtrosForm">
                            <input type="hidden" name="pagina" value="1">
                            <div class="row g-3">
                                <div class="col-6 col-md-2">
                                    <label class="form-label small">Fecha Desde</label>
                                    <input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?php echo safe_html_gasto($filtro_fecha_desde); ?>">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small">Fecha Hasta</label>
                                    <input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?php echo safe_html_gasto($filtro_fecha_hasta); ?>">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small">Categoría</label>
                                    <select class="form-select form-select-sm" name="categoria">
                                        <option value="">Todas</option>
                                        <?php foreach ($categorias_usadas as $cat): ?>
                                            <option value="<?php echo safe_html_gasto($cat); ?>" <?php echo $filtro_categoria === $cat ? 'selected' : ''; ?>>
                                                <?php echo safe_html_gasto($cat); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small">Tipo</label>
                                    <select class="form-select form-select-sm" name="tipo">
                                        <option value="">Todos</option>
                                        <option value="manual" <?php echo $filtro_tipo === 'manual' ? 'selected' : ''; ?>>Manual</option>
                                        <option value="automatico" <?php echo $filtro_tipo === 'automatico' ? 'selected' : ''; ?>>Automático</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small">Sucursal</label>
                                    <select class="form-select form-select-sm" name="sucursal">
                                        <option value="">Todas</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>" <?php echo $filtro_sucursal == $sucursal['id'] ? 'selected' : ''; ?>>
                                                <?php echo safe_html_gasto($sucursal['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small">Orden</label>
                                    <select class="form-select form-select-sm" name="orden">
                                        <option value="desc" <?php echo $filtro_orden === 'desc' ? 'selected' : ''; ?>>Más reciente primero</option>
                                        <option value="asc" <?php echo $filtro_orden === 'asc' ? 'selected' : ''; ?>>Más antiguo primero</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                                    <a href="gastos.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla desktop -->
                <div class="card d-none d-md-block">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="gastosTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Concepto</th>
                                        <th>Proveedor</th>
                                        <th>Categoría</th>
                                        <th>Tipo</th>
                                        <th>Sucursal</th>
                                        <th>Monto</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($gastos)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="fas fa-receipt fa-3x mb-3 d-block"></i>
                                                No hay gastos registrados con estos filtros.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($gastos as $gasto): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y H:i', strtotime($gasto['fecha'])); ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo safe_html_gasto($gasto['concepto']); ?></div>
                                                    <?php if (!empty($gasto['codigo_venta'])): ?>
                                                        <small class="text-muted">Venta: <?php echo safe_html_gasto($gasto['codigo_venta']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($gasto['numero_referencia'])): ?>
                                                        <small class="text-muted d-block">Ref: <?php echo safe_html_gasto($gasto['numero_referencia']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo safe_html_gasto($gasto['proveedor'] ?? '-'); ?></td>
                                                <td><?php echo safe_html_gasto($gasto['categoria']); ?></td>
                                                <td>
                                                    <?php if ($gasto['tipo'] === 'automatico'): ?>
                                                        <span class="badge bg-info">Automático</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Manual</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo safe_html_gasto($gasto['sucursal_nombre'] ?? '-'); ?></td>
                                                <td class="fw-bold text-danger">-$<?php echo number_format($gasto['monto'], 2); ?></td>
                                                <td class="text-end">
                                                    <?php if ($gasto['tipo'] === 'manual'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick='prepararEditarGasto(<?php echo json_encode($gasto); ?>)'>
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmarEliminarGasto(<?php echo (int)$gasto['id']; ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted small" title="Los gastos automáticos se eliminan junto con su venta">
                                                            <i class="fas fa-lock"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Cards móvil -->
                <div class="d-md-none" id="gastosMobile">
                    <?php if (empty($gastos)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-receipt fa-3x mb-3 d-block"></i>
                            No hay gastos registrados con estos filtros.
                        </div>
                    <?php else: ?>
                        <?php foreach ($gastos as $gasto): ?>
                            <div class="card gasto-mobile-card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h6 class="fw-bold mb-0"><?php echo safe_html_gasto($gasto['concepto']); ?></h6>
                                        <?php if ($gasto['tipo'] === 'automatico'): ?>
                                            <span class="badge bg-info">Automático</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Manual</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted small mb-1"><?php echo date('d/m/Y H:i', strtotime($gasto['fecha'])); ?> · <?php echo safe_html_gasto($gasto['categoria']); ?></p>
                                    <?php if (!empty($gasto['proveedor'])): ?>
                                        <p class="text-muted small mb-1">Proveedor: <?php echo safe_html_gasto($gasto['proveedor']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($gasto['numero_referencia'])): ?>
                                        <p class="text-muted small mb-1">Ref: <?php echo safe_html_gasto($gasto['numero_referencia']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($gasto['codigo_venta'])): ?>
                                        <p class="text-muted small mb-1">Venta: <?php echo safe_html_gasto($gasto['codigo_venta']); ?></p>
                                    <?php endif; ?>
                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                        <span class="fw-bold text-danger">-$<?php echo number_format($gasto['monto'], 2); ?></span>
                                        <?php if ($gasto['tipo'] === 'manual'): ?>
                                            <div>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='prepararEditarGasto(<?php echo json_encode($gasto); ?>)'>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmarEliminarGasto(<?php echo (int)$gasto['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Paginación -->
                <?php if ($total_paginas > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center flex-wrap">
                            <?php if ($pagina_actual > 1): ?>
                                <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>"><i class="fas fa-chevron-left"></i></a></li>
                            <?php endif; ?>
                            <?php
                            $inicio = max(1, $pagina_actual - 2);
                            $fin = min($total_paginas, $pagina_actual + 2);
                            for ($i = $inicio; $i <= $fin; $i++): ?>
                                <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($pagina_actual < $total_paginas): ?>
                                <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>"><i class="fas fa-chevron-right"></i></a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal Nuevo/Editar Gasto -->
    <div class="modal fade" id="modalGasto" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="gastos.php<?php echo !empty($_GET) ? '?' . http_build_query($_GET) : ''; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalGastoTitulo">Nuevo Gasto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="guardar_gasto">
                        <input type="hidden" name="gasto_id" id="gasto_id" value="">
                        <div class="mb-3">
                            <label class="form-label">Concepto *</label>
                            <input type="text" class="form-control" name="concepto" id="gasto_concepto" required maxlength="255">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Categoría</label>
                                <input type="text" class="form-control" name="categoria" id="gasto_categoria" list="listaCategorias" placeholder="Ej. Renta">
                                <datalist id="listaCategorias">
                                    <?php foreach ($categorias_gasto as $cat): ?>
                                        <option value="<?php echo safe_html_gasto($cat); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Monto *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control" name="monto" id="gasto_monto" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Fecha</label>
                                <input type="datetime-local" class="form-control" name="fecha" id="gasto_fecha">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Sucursal</label>
                                <select class="form-select" name="sucursal_id" id="gasto_sucursal">
                                    <option value="">Sin especificar</option>
                                    <?php foreach ($sucursales as $sucursal): ?>
                                        <option value="<?php echo $sucursal['id']; ?>"><?php echo safe_html_gasto($sucursal['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Método de pago</label>
                            <select class="form-select" name="metodo_pago" id="gasto_metodo_pago">
                                <option value="">Sin especificar</option>
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Proveedor</label>
                                <input type="text" class="form-control" name="proveedor" id="gasto_proveedor" maxlength="150" placeholder="Ej. CFE, Telmex...">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Referencia / Folio</label>
                                <input type="text" class="form-control" name="numero_referencia" id="gasto_numero_referencia" maxlength="100" placeholder="Ej. Factura F-1234">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" name="descripcion" id="gasto_descripcion" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Formulario oculto para eliminar -->
    <form method="POST" action="gastos.php<?php echo !empty($_GET) ? '?' . http_build_query($_GET) : ''; ?>" id="formEliminarGasto" style="display:none;">
        <input type="hidden" name="accion" value="eliminar_gasto">
        <input type="hidden" name="gasto_id" id="eliminar_gasto_id" value="">
    </form>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // =============================================
            // FUNCIONALIDAD DE SIDEBAR (igual que dashboard.php)
            // =============================================
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function openSidebar() {
                if (sidebar && !sidebar.classList.contains('show')) {
                    sidebar.classList.add('show');
                    sidebarBackdrop.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeSidebar() {
                if (sidebar && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    sidebarBackdrop.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (sidebar.classList.contains('show')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                });
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebar);
            }

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebar();
                    }
                });
            });

            // Ajustar en redimensionamiento
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebar();
                }
            });

            // =============================================
            // SWIPE AUTOMÁTICO (igual que dashboard.php)
            // =============================================
            let touchStartX = 0;
            let touchStartY = 0;
            let touchEndX = 0;
            let touchEndY = 0;
            let isTouchActive = false;
            const SWIPE_THRESHOLD = 50;
            const SWIPE_EDGE_ZONE = 30;
            const VERTICAL_THRESHOLD = 30;

            document.addEventListener('touchstart', function(e) {
                if (window.innerWidth >= 768) return;
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                touchEndX = touchStartX;
                touchEndY = touchStartY;
                isTouchActive = true;
            });

            document.addEventListener('touchmove', function(e) {
                if (!isTouchActive) return;
                touchEndX = e.touches[0].clientX;
                touchEndY = e.touches[0].clientY;
                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;
                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                    e.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('touchend', function(e) {
                if (!isTouchActive) return;
                isTouchActive = false;
                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                if (Math.abs(deltaY) > VERTICAL_THRESHOLD) return;

                const isSidebarOpen = sidebar && sidebar.classList.contains('show');

                if (deltaX > SWIPE_THRESHOLD) {
                    if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                        openSidebar();
                    }
                } else if (deltaX < -SWIPE_THRESHOLD) {
                    if (isSidebarOpen) {
                        closeSidebar();
                    }
                }

                touchStartX = 0;
                touchStartY = 0;
                touchEndX = 0;
                touchEndY = 0;
            });
        });

        // Preparar modal para nuevo gasto
        function prepararNuevoGasto() {
            document.getElementById('modalGastoTitulo').textContent = 'Nuevo Gasto';
            document.getElementById('gasto_id').value = '';
            document.getElementById('gasto_concepto').value = '';
            document.getElementById('gasto_categoria').value = '';
            document.getElementById('gasto_monto').value = '';
            document.getElementById('gasto_fecha').value = '';
            document.getElementById('gasto_sucursal').value = '';
            document.getElementById('gasto_metodo_pago').value = '';
            document.getElementById('gasto_descripcion').value = '';
            document.getElementById('gasto_proveedor').value = '';
            document.getElementById('gasto_numero_referencia').value = '';
        }

        // Preparar modal para editar un gasto manual existente
        function prepararEditarGasto(gasto) {
            document.getElementById('modalGastoTitulo').textContent = 'Editar Gasto';
            document.getElementById('gasto_id').value = gasto.id;
            document.getElementById('gasto_concepto').value = gasto.concepto || '';
            document.getElementById('gasto_categoria').value = gasto.categoria || '';
            document.getElementById('gasto_monto').value = gasto.monto || '';

            if (gasto.fecha) {
                // Convertir 'YYYY-MM-DD HH:MM:SS' a formato datetime-local 'YYYY-MM-DDTHH:MM'
                const fecha = gasto.fecha.replace(' ', 'T').substring(0, 16);
                document.getElementById('gasto_fecha').value = fecha;
            } else {
                document.getElementById('gasto_fecha').value = '';
            }

            document.getElementById('gasto_sucursal').value = gasto.sucursal_id || '';
            document.getElementById('gasto_metodo_pago').value = gasto.metodo_pago || '';
            document.getElementById('gasto_descripcion').value = gasto.descripcion || '';
            document.getElementById('gasto_proveedor').value = gasto.proveedor || '';
            document.getElementById('gasto_numero_referencia').value = gasto.numero_referencia || '';

            const modal = new bootstrap.Modal(document.getElementById('modalGasto'));
            modal.show();
        }

        // Confirmar y eliminar un gasto manual
        function confirmarEliminarGasto(id) {
            if (confirm('¿Eliminar este gasto? Esta acción no se puede deshacer.')) {
                document.getElementById('eliminar_gasto_id').value = id;
                document.getElementById('formEliminarGasto').submit();
            }
        }
    </script>
</body>

</html>