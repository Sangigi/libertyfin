<?php
// facturar_cliente.php - Página pública para que el cliente ingrese datos fiscales

session_start();
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/config/database.php';

$token      = $_GET['token'] ?? '';
$venta_id   = (int)($_GET['venta_id'] ?? 0);
$empresa_id = (int)($_GET['empresa_id'] ?? 0);
$empresa_db = $_GET['db'] ?? '';

if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token) || $venta_id <= 0
    || $empresa_id <= 0 || empty($empresa_db)) {
    die('Enlace inválido o incompleto.');
}

try {
    $conn = getEmpresaDBConnection($empresa_db);
} catch (Exception $e) {
    die('Error de conexión.');
}

$stmt = $conn->prepare("
    SELECT v.*, c.nombre AS cliente_nombre, c.rfc AS cliente_rfc,
           c.email AS cliente_email, c.regimen_fiscal, c.codigo_postal,
           c.estado_direccion, c.ciudad
    FROM ventas v
    LEFT JOIN clientes c ON c.id = v.cliente_id
    WHERE v.id = :venta_id AND v.factura_token = :token
    LIMIT 1
");
$stmt->execute([':venta_id' => $venta_id, ':token' => $token]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    die('Token inválido o venta no encontrada.');
}

if (!empty($venta['factura_token_expira'])
    && strtotime($venta['factura_token_expira']) < time()) {
    die('El enlace de facturación ha expirado.');
}

if (!empty($venta['factura_uuid'])) {
    die('Esta venta ya fue facturada. UUID: ' . htmlspecialchars($venta['factura_uuid']));
}

$regimenes = [
    '601' => 'General de Ley Personas Morales',
    '603' => 'Personas Morales con Fines no Lucrativos',
    '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
    '606' => 'Arrendamiento',
    '608' => 'Demás ingresos',
    '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
    '614' => 'Ingresos por intereses',
    '616' => 'Sin obligaciones fiscales',
    '620' => 'Sociedades Cooperativas de Producción',
    '621' => 'Incorporación Fiscal',
    '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
    '626' => 'Régimen Simplificado de Confianza'
];

$usos_cfdi = [
    'G01' => 'Adquisición de mercancías',
    'G02' => 'Devoluciones, descuentos o bonificaciones',
    'G03' => 'Gastos en general',
    'I01' => 'Construcciones',
    'I03' => 'Equipo de transporte',
    'I04' => 'Equipo de cómputo y accesorios',
    'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
    'D10' => 'Pagos por servicios educativos',
    'P01' => 'Por definir'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturar Venta <?= htmlspecialchars($venta['codigo_venta']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 620px;">
    <div class="card shadow">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0"><i class="fas fa-file-invoice"></i> Facturación de su compra</h4>
        </div>
        <div class="card-body">
            <p class="mb-1"><strong>Venta:</strong> <?= htmlspecialchars($venta['codigo_venta']) ?></p>
            <p class="mb-3"><strong>Total:</strong> $<?= number_format($venta['total'], 2) ?></p>
            <hr>

            <form id="facturaForm">
                <input type="hidden" name="token"      value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="venta_id"   value="<?= (int)$venta_id ?>">
                <input type="hidden" name="empresa_id" value="<?= (int)$empresa_id ?>">
                <input type="hidden" name="empresa_db" value="<?= htmlspecialchars($empresa_db) ?>">

                <div class="mb-3">
                    <label class="form-label">RFC *</label>
                    <input type="text" class="form-control" name="cliente_rfc" required maxlength="13"
                           value="<?= htmlspecialchars($venta['cliente_rfc'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Razón Social *</label>
                    <input type="text" class="form-control" name="cliente_nombre" required
                           value="<?= htmlspecialchars($venta['cliente_nombre'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Correo electrónico *</label>
                    <input type="email" class="form-control" name="cliente_email" required
                           value="<?= htmlspecialchars($venta['cliente_email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Régimen Fiscal *</label>
                    <select class="form-select" name="cliente_regimen" required>
                        <option value="">Seleccionar...</option>
                        <?php foreach ($regimenes as $k => $v):
                            $sel = ($venta['regimen_fiscal'] ?? '') === $k ? 'selected' : '';
                        ?>
                            <option value="<?= $k ?>" <?= $sel ?>><?= $k ?> - <?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Código Postal *</label>
                        <input type="text" class="form-control" name="cliente_zip" required maxlength="5"
                               value="<?= htmlspecialchars($venta['codigo_postal'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Estado</label>
                        <input type="text" class="form-control" name="cliente_estado"
                               value="<?= htmlspecialchars($venta['estado_direccion'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Ciudad</label>
                        <input type="text" class="form-control" name="cliente_ciudad"
                               value="<?= htmlspecialchars($venta['ciudad'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Método de pago *</label>
                        <select class="form-select" name="metodo_pago" required>
                            <option value="PUE">PUE (Pago en una sola exhibición)</option>
                            <option value="PPD">PPD (Pago en parcialidades)</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Uso de CFDI *</label>
                        <select class="form-select" name="uso_cfdi" required>
                            <?php foreach ($usos_cfdi as $k => $v): ?>
                                <option value="<?= $k ?>"><?= $k ?> - <?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success w-100" id="btnFacturar">
                    Generar Factura
                </button>
            </form>

            <div id="resultado" class="mt-3"></div>
        </div>
    </div>
</div>

<script>
document.getElementById('facturaForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnFacturar');
    btn.disabled = true;
    btn.textContent = 'Generando...';

    const formData = new FormData(this);
    try {
        const resp = await fetch('Service/facturar_cliente_publico.php', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        const div = document.getElementById('resultado');

        if (data.success) {
            div.innerHTML = `
                <div class="alert alert-success">
                    <strong>✅ ${data.message}</strong><br>
                    UUID: ${data.uuid || 'N/A'}<br>
                    Folio: ${data.folio || 'N/A'}
                </div>`;
            btn.textContent = 'Facturada';
        } else {
            div.innerHTML = `<div class="alert alert-danger">❌ ${data.message}</div>`;
            btn.disabled = false;
            btn.textContent = 'Generar Factura';
        }
    } catch (err) {
        document.getElementById('resultado').innerHTML =
            `<div class="alert alert-danger">Error de conexión: ${err.message}</div>`;
        btn.disabled = false;
        btn.textContent = 'Generar Factura';
    }
});
</script>
</body>
</html>