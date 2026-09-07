<?php
// cuentas_por_cobrar.php
// Qué se debe, quién debe y desde cuándo.
//
// El saldo NUNCA se guarda: siempre es total - SUM(pagos activos). Así no
// se puede desincronizar con los pagos.

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

function safe_html($v) {
    return ($v === null || $v === '') ? '' : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function money($v) { return '$' . number_format((float)$v, 2); }

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    $sql_colores = "SELECT color_primario, color_secundario FROM sistema_config LIMIT 1";
    $rc = $conn->query($sql_colores);
    $cc = $rc ? $rc->fetch(PDO::FETCH_ASSOC) : null;
    $color_primario   = $cc['color_primario']   ?? '#27ae60';
    $color_secundario = $cc['color_secundario'] ?? '#2ecc71';

    // Filtros
    $filtro_cliente = trim($_GET['cliente'] ?? '');
    $filtro_dias    = isset($_GET['dias']) && is_numeric($_GET['dias']) ? (int)$_GET['dias'] : 0;

    $where = [];
    $params = [];
    if ($filtro_cliente !== '') {
        $where[] = "cliente LIKE ?";
        $params[] = '%' . $filtro_cliente . '%';
    }
    if ($filtro_dias > 0) {
        $where[] = "dias_desde_la_venta >= ?";
        $params[] = $filtro_dias;
    }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $conn->prepare("
        SELECT * FROM v_cuentas_por_cobrar
        $where_sql
        ORDER BY dias_desde_la_venta DESC, saldo DESC
    ");
    $stmt->execute($params);
    $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totales y antigüedad de saldos
    $total_vendido = 0; $total_cobrado = 0; $total_saldo = 0;
    $antiguedad = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];

    foreach ($cuentas as $c) {
        $total_vendido += (float)$c['total'];
        $total_cobrado += (float)$c['cobrado'];
        $total_saldo   += (float)$c['saldo'];
        $d = (int)$c['dias_desde_la_venta'];
        if     ($d <= 30) $antiguedad['0-30']  += (float)$c['saldo'];
        elseif ($d <= 60) $antiguedad['31-60'] += (float)$c['saldo'];
        elseif ($d <= 90) $antiguedad['61-90'] += (float)$c['saldo'];
        else              $antiguedad['90+']   += (float)$c['saldo'];
    }

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuentas por Cobrar - <?php echo safe_html($_SESSION['empresa_nombre'] ?? ''); ?></title>
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
    <link rel="stylesheet" href="css/crm-theme.css">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <h2 class="mb-0 fs-4">
                    <i class="fas fa-hand-holding-dollar me-2"></i>Cuentas por Cobrar
                </h2>
                <button class="btn btn-outline-secondary no-print" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Imprimir
                </button>
            </div>

            <!-- Resumen -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">VENTAS CON SALDO</div>
                        <h4 class="mb-0"><?php echo count($cuentas); ?></h4>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">TOTAL VENDIDO</div>
                        <h4 class="mb-0"><?php echo money($total_vendido); ?></h4>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">COBRADO</div>
                        <h4 class="mb-0 text-success"><?php echo money($total_cobrado); ?></h4>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-danger"><div class="card-body">
                        <div class="text-muted small">POR COBRAR</div>
                        <h4 class="mb-0 text-danger"><?php echo money($total_saldo); ?></h4>
                    </div></div>
                </div>
            </div>

            <!-- Antigüedad de saldos -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-hourglass-half me-2"></i>Antigüedad de saldos</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $colores = ['0-30' => 'success', '31-60' => 'info', '61-90' => 'warning', '90+' => 'danger'];
                        $etiquetas = ['0-30' => '0 a 30 días', '31-60' => '31 a 60 días',
                                      '61-90' => '61 a 90 días', '90+' => 'Más de 90 días'];
                        foreach ($antiguedad as $rango => $monto):
                            $pct = $total_saldo > 0 ? ($monto / $total_saldo * 100) : 0;
                        ?>
                            <div class="col-md-3">
                                <div class="border rounded p-2">
                                    <div class="text-muted small"><?php echo $etiquetas[$rango]; ?></div>
                                    <div class="fw-bold text-<?php echo $colores[$rango]; ?>"><?php echo money($monto); ?></div>
                                    <div class="progress mt-1" style="height:6px;">
                                        <div class="progress-bar bg-<?php echo $colores[$rango]; ?>"
                                             style="width: <?php echo round($pct, 1); ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?php echo round($pct, 1); ?>%</small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="card mb-4 no-print">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small mb-1">Cliente</label>
                            <input type="text" class="form-control form-control-sm" name="cliente"
                                   value="<?php echo safe_html($filtro_cliente); ?>" placeholder="Buscar por nombre...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Antigüedad mínima</label>
                            <select class="form-select form-select-sm" name="dias">
                                <option value="0">Todas</option>
                                <option value="31"  <?php echo $filtro_dias == 31 ? 'selected' : ''; ?>>Más de 30 días</option>
                                <option value="61"  <?php echo $filtro_dias == 61 ? 'selected' : ''; ?>>Más de 60 días</option>
                                <option value="91"  <?php echo $filtro_dias == 91 ? 'selected' : ''; ?>>Más de 90 días</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                <i class="fas fa-filter me-1"></i>Filtrar
                            </button>
                            <a href="cuentas_por_cobrar.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Detalle -->
            <div class="card">
                <div class="card-header"><i class="fas fa-list me-2"></i>Detalle</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Folio</th><th>Cliente</th><th>Fecha venta</th>
                                    <th class="text-end">Total</th><th class="text-end">Cobrado</th>
                                    <th class="text-end">Saldo</th><th class="text-center">% cobrado</th>
                                    <th class="text-center">Días</th><th>Último pago</th>
                                    <th class="no-print"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($cuentas)): ?>
                                <tr><td colspan="10" class="text-center text-success py-4">
                                    <i class="fas fa-check-circle fa-2x d-block mb-2"></i>
                                    No hay saldos pendientes
                                </td></tr>
                            <?php else: foreach ($cuentas as $c):
                                $d = (int)$c['dias_desde_la_venta'];
                                $cls = $d > 90 ? 'danger' : ($d > 60 ? 'warning' : ($d > 30 ? 'info' : 'success'));
                            ?>
                                <tr>
                                    <td class="text-nowrap"><code><?php echo safe_html($c['codigo_venta']); ?></code></td>
                                    <td><?php echo safe_html($c['cliente']); ?></td>
                                    <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($c['fecha_venta'])); ?></td>
                                    <td class="text-end"><?php echo money($c['total']); ?></td>
                                    <td class="text-end text-success"><?php echo money($c['cobrado']); ?></td>
                                    <td class="text-end fw-bold text-danger"><?php echo money($c['saldo']); ?></td>
                                    <td class="text-center">
                                        <div class="progress" style="height:14px; min-width:70px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo (float)$c['pct_cobrado']; ?>%">
                                                <?php echo round((float)$c['pct_cobrado']); ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $cls; ?>"><?php echo $d; ?></span>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php echo $c['ultimo_pago'] ? date('d/m/Y', strtotime($c['ultimo_pago'])) : '<span class="text-muted">—</span>'; ?>
                                    </td>
                                    <td class="no-print">
                                        <a href="ventas_lista.php?ver_venta=<?php echo (int)$c['venta_id']; ?>"
                                           class="btn btn-sm btn-outline-primary" title="Abrir la venta y registrar un abono">
                                            <i class="fas fa-hand-holding-dollar"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                            <?php if (!empty($cuentas)): ?>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="3" class="fw-bold text-end">TOTALES</td>
                                    <td class="text-end fw-bold"><?php echo money($total_vendido); ?></td>
                                    <td class="text-end fw-bold text-success"><?php echo money($total_cobrado); ?></td>
                                    <td class="text-end fw-bold text-danger"><?php echo money($total_saldo); ?></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    function toggleSidebar() {
        sidebar.classList.toggle('show');
        sidebarBackdrop.classList.toggle('show');
    }
    if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
    if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', toggleSidebar);
</script>
</body>
</html>