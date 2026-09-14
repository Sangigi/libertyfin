<?php
// recalcular_comisiones.php
//
// Uso único (se puede repetir sin daño): recorre TODAS las ventas con
// comisiones asignadas y regenera `pago_comisiones`, que es la tabla de la
// que viven los reportes.
//
// Hace falta porque las comisiones se asignan DESPUÉS de cobrar: las ventas
// capturadas antes de este cambio tienen su comisión en `venta_comisiones`
// pero nada en `pago_comisiones`, así que no salían en ningún reporte.
//
// Solo admin. Abrir en el navegador: /recalcular_comisiones.php

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die('No autorizado');
}
if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    die('Solo un administrador puede ejecutar este recálculo');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/includes/comisiones_devengadas.php';

header('Content-Type: text/html; charset=utf-8');

$conn = getEmpresaDBConnection($_SESSION['empresa_db']);

// Ventas que tienen al menos una comisión viva asignada.
$ventas = $conn->query("
    SELECT DISTINCT venta_id FROM venta_comisiones WHERE cancelada = 0 ORDER BY venta_id
")->fetchAll(PDO::FETCH_COLUMN);

$filas = [];
$total_antes = 0.0;
$total_despues = 0.0;

foreach ($ventas as $venta_id) {
    $venta_id = (int)$venta_id;

    $st = $conn->prepare("SELECT COALESCE(SUM(monto),0) FROM pago_comisiones WHERE venta_id = ?");
    $st->execute([$venta_id]);
    $antes = (float)$st->fetchColumn();

    // Se borra y se vuelve a generar con la regla vigente, para que las
    // ventas viejas queden igual que las nuevas.
    $conn->prepare("DELETE FROM pago_comisiones WHERE venta_id = ?")->execute([$venta_id]);
    sincronizarComisionesDeVenta($conn, $venta_id);

    $st->execute([$venta_id]);
    $despues = (float)$st->fetchColumn();

    $st2 = $conn->prepare("SELECT codigo_venta, total FROM ventas WHERE id = ?");
    $st2->execute([$venta_id]);
    $v = $st2->fetch(PDO::FETCH_ASSOC) ?: ['codigo_venta' => '?', 'total' => 0];

    $total_antes   += $antes;
    $total_despues += $despues;

    // Diagnóstico: por qué una venta puede quedar en 0
    $st3 = $conn->prepare("
        SELECT COUNT(*) AS n_com,
               COALESCE(SUM(monto_comision),0) AS asignado,
               COALESCE(SUM(precio_unitario * cantidad),0) AS venta_lineas,
               COALESCE(SUM(gasto_operacion),0) AS gasto
        FROM venta_comisiones WHERE venta_id = ? AND cancelada = 0
    ");
    $st3->execute([$venta_id]);
    $diag = $st3->fetch(PDO::FETCH_ASSOC);

    $st4 = $conn->prepare("
        SELECT COUNT(*) AS n_pagos, COALESCE(SUM(monto),0) AS cobrado
        FROM venta_pagos WHERE venta_id = ? AND cancelado = 0
    ");
    $st4->execute([$venta_id]);
    $pag = $st4->fetch(PDO::FETCH_ASSOC);

    $motivo = '';
    if ((int)$pag['n_pagos'] === 0)          $motivo = 'La venta no tiene pagos registrados';
    elseif ((float)$v['total'] <= 0)         $motivo = 'La venta tiene total 0';
    elseif ($despues <= 0 && (float)$pag['cobrado'] <= (float)$diag['gasto'])
                                             $motivo = 'Lo cobrado no alcanza a cubrir el gasto de operación';

    $filas[] = [
        'id'      => $venta_id,
        'folio'   => $v['codigo_venta'],
        'total'   => (float)$v['total'],
        'antes'   => $antes,
        'despues' => $despues,
        'pagos'   => (int)$pag['n_pagos'],
        'cobrado' => (float)$pag['cobrado'],
        'gasto'   => (float)$diag['gasto'],
        'motivo'  => $motivo
    ];
}

function m($n) { return '$' . number_format((float)$n, 2); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recálculo de comisiones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <h4>Recálculo de comisiones devengadas</h4>
    <p class="text-muted">
        Se revisaron <strong><?php echo count($filas); ?></strong> ventas con comisiones asignadas.
        Ya puedes volver a generar el reporte del periodo.
    </p>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Generado antes</div>
                <div class="fs-5 fw-bold"><?php echo m($total_antes); ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Generado ahora</div>
                <div class="fs-5 fw-bold text-success"><?php echo m($total_despues); ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Diferencia</div>
                <div class="fs-5 fw-bold"><?php echo m($total_despues - $total_antes); ?></div>
            </div></div>
        </div>
    </div>

    <table class="table table-sm table-hover">
        <thead class="table-light">
            <tr>
                <th>Venta</th><th>Folio</th>
                <th class="text-end">Total</th>
                <th class="text-end">Cobrado</th>
                <th class="text-end">Gasto</th>
                <th class="text-end">Comisión antes</th>
                <th class="text-end">Comisión ahora</th>
                <th>Nota</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
            <tr>
                <td>#<?php echo $f['id']; ?></td>
                <td><code><?php echo htmlspecialchars($f['folio']); ?></code></td>
                <td class="text-end"><?php echo m($f['total']); ?></td>
                <td class="text-end"><?php echo m($f['cobrado']); ?></td>
                <td class="text-end"><?php echo m($f['gasto']); ?></td>
                <td class="text-end text-muted"><?php echo m($f['antes']); ?></td>
                <td class="text-end fw-bold <?php echo $f['despues'] > $f['antes'] ? 'text-success' : ''; ?>">
                    <?php echo m($f['despues']); ?>
                </td>
                <td class="small text-danger"><?php echo htmlspecialchars($f['motivo']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($filas)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">
                No hay ventas con comisiones asignadas todavía.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <a href="reportes.php" class="btn btn-primary">Ir a Reportes</a>
</body>
</html>