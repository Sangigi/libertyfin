<?php
// comisiones_config.php
// Pantalla de configuración del módulo de comisiones, a nivel EMPRESA.
// Vive junto a Configuracion.php y usa el mismo patrón de sesión/BD.

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

$mensaje = '';
$tipo_mensaje = '';

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // =============================================
    // PROCESAR FORMULARIOS
    // =============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

        // ---- Áreas ----
        if ($_POST['accion'] === 'crear_area') {
            $nombre = trim($_POST['nombre'] ?? '');
            if ($nombre === '') {
                $mensaje = 'El nombre del área es obligatorio';
                $tipo_mensaje = 'danger';
            } else {
                try {
                    $stmt = $conn->prepare("INSERT INTO comision_areas (nombre) VALUES (?)");
                    $stmt->execute([$nombre]);
                    $mensaje = 'Área agregada correctamente';
                    $tipo_mensaje = 'success';
                } catch (Exception $e) {
                    $mensaje = 'Ya existe un área con ese nombre';
                    $tipo_mensaje = 'danger';
                }
            }
        }

        if ($_POST['accion'] === 'cambiar_estado_area') {
            $id = intval($_POST['id'] ?? 0);
            $activo = intval($_POST['activo'] ?? 0);
            $stmt = $conn->prepare("UPDATE comision_areas SET activo = ? WHERE id = ?");
            $stmt->execute([$activo, $id]);
            $mensaje = $activo ? 'Área activada' : 'Área desactivada';
            $tipo_mensaje = 'success';
        }

        if ($_POST['accion'] === 'eliminar_area') {
            $id = intval($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM comision_areas WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = 'Área eliminada (junto con sus reglas)';
            $tipo_mensaje = 'success';
        }

        // ---- Colaboradores ----
        if ($_POST['accion'] === 'crear_colaborador') {
            $nombre = trim($_POST['nombre'] ?? '');
            $area_id = !empty($_POST['area_id']) ? intval($_POST['area_id']) : null;
            if ($nombre === '') {
                $mensaje = 'El nombre del colaborador es obligatorio';
                $tipo_mensaje = 'danger';
            } else {
                $stmt = $conn->prepare("INSERT INTO comision_colaboradores (nombre, area_id) VALUES (?, ?)");
                $stmt->execute([$nombre, $area_id]);
                $mensaje = 'Colaborador agregado correctamente';
                $tipo_mensaje = 'success';
            }
        }

        if ($_POST['accion'] === 'editar_colaborador') {
            $id = intval($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $area_id = !empty($_POST['area_id']) ? intval($_POST['area_id']) : null;
            if ($id > 0 && $nombre !== '') {
                $stmt = $conn->prepare("UPDATE comision_colaboradores SET nombre = ?, area_id = ? WHERE id = ?");
                $stmt->execute([$nombre, $area_id, $id]);
                $mensaje = 'Colaborador actualizado correctamente';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'Datos inválidos para actualizar colaborador';
                $tipo_mensaje = 'danger';
            }
        }

        if ($_POST['accion'] === 'cambiar_estado_colaborador') {
            $id = intval($_POST['id'] ?? 0);
            $activo = intval($_POST['activo'] ?? 0);
            $stmt = $conn->prepare("UPDATE comision_colaboradores SET activo = ? WHERE id = ?");
            $stmt->execute([$activo, $id]);
            $mensaje = $activo ? 'Colaborador activado' : 'Colaborador desactivado';
            $tipo_mensaje = 'success';
        }

        if ($_POST['accion'] === 'eliminar_colaborador') {
            $id = intval($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM comision_colaboradores WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = 'Colaborador eliminado';
            $tipo_mensaje = 'success';
        }

        // ---- Reglas (conceptos + %) por área — totalmente editable, no fijo ----
        if ($_POST['accion'] === 'guardar_reglas_area') {
            $area_id = intval($_POST['area_id'] ?? 0);
            $conceptos = $_POST['concepto'] ?? [];
            $porcentajes = $_POST['porcentaje'] ?? [];

            if ($area_id <= 0) {
                $mensaje = 'Área no válida';
                $tipo_mensaje = 'danger';
            } else {
                $conn->beginTransaction();
                try {
                    $stmt_del = $conn->prepare("DELETE FROM comision_reglas WHERE area_id = ?");
                    $stmt_del->execute([$area_id]);

                    $stmt_ins = $conn->prepare("INSERT INTO comision_reglas (area_id, concepto, porcentaje, orden) VALUES (?, ?, ?, ?)");
                    $orden = 1;
                    foreach ($conceptos as $i => $concepto) {
                        $concepto = trim($concepto);
                        $porcentaje = floatval($porcentajes[$i] ?? 0);
                        if ($concepto === '') continue;
                        $stmt_ins->execute([$area_id, $concepto, $porcentaje, $orden]);
                        $orden++;
                    }
                    $conn->commit();
                    $mensaje = 'Reglas guardadas correctamente';
                    $tipo_mensaje = 'success';
                } catch (Exception $e) {
                    $conn->rollBack();
                    $mensaje = 'Error al guardar reglas: ' . $e->getMessage();
                    $tipo_mensaje = 'danger';
                }
            }
        }

        // ---- Porcentajes de reparto ----
        if ($_POST['accion'] === 'crear_porcentaje_reparto') {
            $valor = floatval($_POST['valor'] ?? 0);
            if ($valor <= 0 || $valor > 100) {
                $mensaje = 'El porcentaje debe estar entre 0 y 100';
                $tipo_mensaje = 'danger';
            } else {
                try {
                    $stmt = $conn->prepare("INSERT INTO comision_porcentajes_reparto (valor) VALUES (?)");
                    $stmt->execute([$valor]);
                    $mensaje = 'Porcentaje de reparto agregado';
                    $tipo_mensaje = 'success';
                } catch (Exception $e) {
                    $mensaje = 'Ese porcentaje ya existe o hubo un error';
                    $tipo_mensaje = 'danger';
                }
            }
        }

        if ($_POST['accion'] === 'cambiar_estado_porcentaje') {
            $id = intval($_POST['id'] ?? 0);
            $activo = intval($_POST['activo'] ?? 0);
            $stmt = $conn->prepare("UPDATE comision_porcentajes_reparto SET activo = ? WHERE id = ?");
            $stmt->execute([$activo, $id]);
            $mensaje = $activo ? 'Porcentaje activado' : 'Porcentaje desactivado';
            $tipo_mensaje = 'success';
        }

        if ($_POST['accion'] === 'eliminar_porcentaje') {
            $id = intval($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM comision_porcentajes_reparto WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = 'Porcentaje eliminado';
            $tipo_mensaje = 'success';
        }

        $_SESSION['comisiones_mensaje'] = $mensaje;
        $_SESSION['comisiones_tipo_mensaje'] = $tipo_mensaje;
        header('Location: comisiones_config.php');
        exit();
    }

    if (isset($_SESSION['comisiones_mensaje'])) {
        $mensaje = $_SESSION['comisiones_mensaje'];
        $tipo_mensaje = $_SESSION['comisiones_tipo_mensaje'];
        unset($_SESSION['comisiones_mensaje'], $_SESSION['comisiones_tipo_mensaje']);
    }

    // =============================================
    // CARGAR DATOS
    // =============================================
    $areas = $conn->query("SELECT * FROM comision_areas ORDER BY activo DESC, nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

    $colaboradores = $conn->query("
        SELECT cc.*, ca.nombre as area_nombre
        FROM comision_colaboradores cc
        LEFT JOIN comision_areas ca ON cc.area_id = ca.id
        ORDER BY cc.activo DESC, cc.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $reglas_por_area = [];
    $stmt_reglas = $conn->query("SELECT * FROM comision_reglas ORDER BY area_id, orden ASC, id ASC");
    while ($r = $stmt_reglas->fetch(PDO::FETCH_ASSOC)) {
        $reglas_por_area[$r['area_id']][] = $r;
    }

    $porcentajes_reparto = $conn->query("SELECT * FROM comision_porcentajes_reparto ORDER BY valor DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Colores de la empresa (para mantener consistencia visual con el resto del sistema)
    $sql_colores = "SELECT color_primario, color_secundario FROM sistema_config LIMIT 1";
    $result_colores = $conn->query($sql_colores);
    $colores_config = $result_colores ? $result_colores->fetch(PDO::FETCH_ASSOC) : null;
    $color_primario = $colores_config['color_primario'] ?? '#27ae60';
    $color_secundario = $colores_config['color_secundario'] ?? '#2ecc71';

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Comisiones - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .navbar { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); }
        .card { border: none; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .badge-area { font-size: 0.75rem; padding: 4px 10px; }
        .suma-indicador { font-weight: bold; }
        .suma-ok { color: #198754; }
        .suma-neutral { color: #6c757d; }
        .porcentaje-input { width: 100px; }
        .btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); }
        .btn-primary:hover { background-color: var(--secondary-color); border-color: var(--secondary-color); }
        .nav-tabs .nav-link.active { color: var(--primary-color); font-weight: 600; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark mb-4">
        <div class="container-fluid">
            <span class="navbar-brand"><i class="fas fa-percentage me-2"></i>Configuración de Comisiones</span>
            <a href="Configuracion" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver a Configuración</a>
        </div>
    </nav>

    <div class="container-fluid px-4">

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($mensaje); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Las comisiones se calculan sobre la <strong>utilidad de cada producto vendido</strong>
            (precio de venta menos el costo del producto, antes de IVA). Puedes crear las áreas,
            conceptos/roles y porcentajes que necesites — nada aquí es fijo.
        </div>

        <ul class="nav nav-tabs mb-4" id="comisionesTabs">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-areas">
                    <i class="fas fa-folder me-1"></i>Áreas y Reglas
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-colaboradores">
                    <i class="fas fa-users me-1"></i>Colaboradores
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reparto">
                    <i class="fas fa-percent me-1"></i>Porcentajes de Reparto
                </button>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ===================== ÁREAS Y REGLAS ===================== -->
            <div class="tab-pane fade show active" id="tab-areas">
                <div class="row">
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <i class="fas fa-folder-plus me-2"></i>Nueva Área
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="accion" value="crear_area">
                                    <div class="mb-3">
                                        <label class="form-label">Nombre del área</label>
                                        <input type="text" class="form-control" name="nombre" required placeholder="Ej. Legal, Marketing...">
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-save me-1"></i>Agregar Área
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header"><i class="fas fa-list me-2"></i>Áreas existentes</div>
                            <div class="card-body p-0">
                                <table class="table table-hover mb-0">
                                    <tbody>
                                    <?php foreach ($areas as $a): ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($a['nombre']); ?>
                                                <?php if (!$a['activo']): ?><span class="badge bg-secondary ms-1">Inactiva</span><?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="accion" value="cambiar_estado_area">
                                                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                    <input type="hidden" name="activo" value="<?php echo $a['activo'] ? 0 : 1; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-power-off"></i></button>
                                                </form>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar el área \'<?php echo htmlspecialchars($a['nombre']); ?>\'? Se borrarán también sus reglas.');">
                                                    <input type="hidden" name="accion" value="eliminar_area">
                                                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($areas)): ?>
                                        <tr><td class="text-center text-muted py-3">No hay áreas registradas</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <?php foreach ($areas as $area): ?>
                            <?php $reglas = $reglas_por_area[$area['id']] ?? []; ?>
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-sitemap me-2"></i><?php echo htmlspecialchars($area['nombre']); ?></span>
                                    <span class="suma-indicador suma-neutral" id="suma-<?php echo $area['id']; ?>">Suma: 0%</span>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="form-reglas-area" data-area="<?php echo $area['id']; ?>">
                                        <input type="hidden" name="accion" value="guardar_reglas_area">
                                        <input type="hidden" name="area_id" value="<?php echo $area['id']; ?>">
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle filas-reglas" data-area="<?php echo $area['id']; ?>">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Concepto / Rol</th>
                                                        <th style="width: 140px;">Porcentaje (%)</th>
                                                        <th style="width: 50px;"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php if (!empty($reglas)): ?>
                                                    <?php foreach ($reglas as $r): ?>
                                                    <tr>
                                                        <td><input type="text" class="form-control form-control-sm" name="concepto[]" value="<?php echo htmlspecialchars($r['concepto']); ?>" required></td>
                                                        <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm porcentaje-input input-porcentaje" name="porcentaje[]" value="<?php echo $r['porcentaje']; ?>" required></td>
                                                        <td><button type="button" class="btn btn-sm btn-outline-danger btn-quitar-fila"><i class="fas fa-times"></i></button></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td><input type="text" class="form-control form-control-sm" name="concepto[]" placeholder="ej. Vendedor" required></td>
                                                        <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm porcentaje-input input-porcentaje" name="porcentaje[]" value="0" required></td>
                                                        <td><button type="button" class="btn btn-sm btn-outline-danger btn-quitar-fila"><i class="fas fa-times"></i></button></td>
                                                    </tr>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-agregar-fila" data-area="<?php echo $area['id']; ?>">
                                            <i class="fas fa-plus me-1"></i>Agregar concepto
                                        </button>
                                        <button type="submit" class="btn btn-sm btn-success float-end">
                                            <i class="fas fa-save me-1"></i>Guardar reglas
                                        </button>
                                    </form>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle me-1"></i>No es obligatorio que sume 100% — se muestra solo como referencia, ya que los conceptos son libres.
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($areas)): ?>
                            <div class="card"><div class="card-body text-center text-muted py-5">
                                <i class="fas fa-folder-open fa-3x mb-3"></i><p>Crea primero un área para poder definir sus reglas</p>
                            </div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===================== COLABORADORES ===================== -->
            <div class="tab-pane fade" id="tab-colaboradores">
                <div class="row">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <i class="fas fa-user-plus me-2"></i>Nuevo Colaborador
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="accion" value="crear_colaborador">
                                    <div class="mb-3">
                                        <label class="form-label">Nombre</label>
                                        <input type="text" class="form-control" name="nombre" required placeholder="Ej. Xathziri">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Área (opcional)</label>
                                        <select class="form-select" name="area_id">
                                            <option value="">Sin área específica</option>
                                            <?php foreach ($areas as $a): ?>
                                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-save me-1"></i>Agregar Colaborador
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header"><i class="fas fa-list me-2"></i>Lista de Colaboradores</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr><th>Nombre</th><th>Área</th><th>Estado</th><th class="text-end">Acciones</th></tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($colaboradores as $c): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($c['nombre']); ?></td>
                                                <td><?php echo $c['area_nombre'] ? '<span class="badge bg-secondary badge-area">' . htmlspecialchars($c['area_nombre']) . '</span>' : '<span class="text-muted">Sin área</span>'; ?></td>
                                                <td><?php echo $c['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>'; ?></td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-editar-colaborador"
                                                        data-id="<?php echo $c['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($c['nombre']); ?>"
                                                        data-area="<?php echo $c['area_id']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="accion" value="cambiar_estado_colaborador">
                                                        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                        <input type="hidden" name="activo" value="<?php echo $c['activo'] ? 0 : 1; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-power-off"></i></button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-eliminar-colaborador"
                                                        data-id="<?php echo $c['id']; ?>" data-nombre="<?php echo htmlspecialchars($c['nombre']); ?>">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($colaboradores)): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-4">No hay colaboradores registrados</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== PORCENTAJES DE REPARTO ===================== -->
            <div class="tab-pane fade" id="tab-reparto">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Estos porcentajes se usan cuando un concepto (rol) se reparte entre varios
                    colaboradores. Al asignar la comisión en caja por producto, se elige cuál de
                    estos porcentajes le corresponde a cada colaborador dentro de ese concepto.
                </div>
                <div class="row">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <i class="fas fa-plus-circle me-2"></i>Nuevo Porcentaje
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="accion" value="crear_porcentaje_reparto">
                                    <div class="mb-3">
                                        <label class="form-label">Valor (%)</label>
                                        <input type="number" step="0.01" min="0.01" max="100" class="form-control" name="valor" required placeholder="Ej. 20">
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-save me-1"></i>Agregar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header"><i class="fas fa-list me-2"></i>Porcentajes disponibles</div>
                            <div class="card-body p-0">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light"><tr><th>Valor</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($porcentajes_reparto as $p): ?>
                                        <tr>
                                            <td><strong><?php echo number_format($p['valor'], 2); ?>%</strong></td>
                                            <td><?php echo $p['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>'; ?></td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="accion" value="cambiar_estado_porcentaje">
                                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                                    <input type="hidden" name="activo" value="<?php echo $p['activo'] ? 0 : 1; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-power-off"></i></button>
                                                </form>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este porcentaje?');">
                                                    <input type="hidden" name="accion" value="eliminar_porcentaje">
                                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal editar colaborador -->
    <div class="modal fade" id="modalEditarColaborador" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Colaborador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="editar_colaborador">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Nombre</label>
                            <input type="text" class="form-control" name="nombre" id="edit_nombre" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Área</label>
                            <select class="form-select" name="area_id" id="edit_area">
                                <option value="">Sin área específica</option>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" id="formEliminarColaborador" style="display:none;">
        <input type="hidden" name="accion" value="eliminar_colaborador">
        <input type="hidden" name="id" id="del_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.btn-editar-colaborador').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_nombre').value = this.dataset.nombre;
                document.getElementById('edit_area').value = this.dataset.area || '';
                new bootstrap.Modal(document.getElementById('modalEditarColaborador')).show();
            });
        });

        document.querySelectorAll('.btn-eliminar-colaborador').forEach(btn => {
            btn.addEventListener('click', function () {
                if (confirm(`¿Eliminar al colaborador "${this.dataset.nombre}"? Esta acción no se puede deshacer.`)) {
                    document.getElementById('del_id').value = this.dataset.id;
                    document.getElementById('formEliminarColaborador').submit();
                }
            });
        });

        document.querySelectorAll('.btn-agregar-fila').forEach(btn => {
            btn.addEventListener('click', function () {
                const area = this.dataset.area;
                const tabla = document.querySelector(`.filas-reglas[data-area="${area}"] tbody`);
                const fila = document.createElement('tr');
                fila.innerHTML = `
                    <td><input type="text" class="form-control form-control-sm" name="concepto[]" placeholder="ej. Vendedor" required></td>
                    <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm porcentaje-input input-porcentaje" name="porcentaje[]" value="0" required></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger btn-quitar-fila"><i class="fas fa-times"></i></button>
                `;
                tabla.appendChild(fila);
                calcularSuma(area);
            });
        });

        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-quitar-fila')) {
                const fila = e.target.closest('tr');
                const tabla = fila.closest('.filas-reglas');
                const area = tabla.dataset.area;
                fila.remove();
                calcularSuma(area);
            }
        });

        document.addEventListener('input', function (e) {
            if (e.target.classList.contains('input-porcentaje')) {
                const tabla = e.target.closest('.filas-reglas');
                calcularSuma(tabla.dataset.area);
            }
        });

        function calcularSuma(area) {
            const tabla = document.querySelector(`.filas-reglas[data-area="${area}"]`);
            let suma = 0;
            tabla.querySelectorAll('.input-porcentaje').forEach(input => {
                suma += parseFloat(input.value) || 0;
            });
            const indicador = document.getElementById('suma-' + area);
            indicador.textContent = 'Suma: ' + suma.toFixed(2) + '%';
            indicador.classList.toggle('suma-ok', Math.abs(suma - 100) < 0.01);
        }

        document.querySelectorAll('.filas-reglas').forEach(tabla => calcularSuma(tabla.dataset.area));
    </script>
</body>
</html>