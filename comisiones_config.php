<?php
// comisiones_config.php
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

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

    $areas = $conn->query("SELECT * FROM comision_areas ORDER BY activo DESC, nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

    $colaboradores = $conn->query("
        SELECT cc.*, ca.nombre as area_nombre
        FROM comision_colaboradores cc
        LEFT JOIN comision_areas ca ON cc.area_id = ca.id
        ORDER BY cc.activo DESC, cc.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Configuración de Comisiones - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/crm-theme.css">
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }

        /* ===== Base ===== */
        html, body {
            -webkit-text-size-adjust: 100%;
            overflow-x: hidden;
            max-width: 100vw;
        }

        /* ===== Navbar ===== */
        .navbar-brand {
            font-size: 1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 60vw;
        }
        .btn-config { white-space: nowrap; }

        @media (max-width: 576px) {
            .container-fluid {
                padding-left: 10px !important;
                padding-right: 10px !important;
            }
            .navbar-brand {
                font-size: 0.9rem;
                max-width: 55vw;
            }
        }

        /* ===== Tabs ===== */
        .nav-tabs {
            flex-wrap: nowrap;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .nav-tabs::-webkit-scrollbar { display: none; }
        .nav-tabs .nav-link { white-space: nowrap; font-size: 0.95rem; }

        /* Asegurar visibilidad del panel activo (por si crm-theme lo oculta) */
        .tab-content > .tab-pane { display: none; }
        .tab-content > .tab-pane.active { display: block; }
        .tab-content > .tab-pane.active.show { display: block; }

        /* ===== Alert info ===== */
        .info-collapse-toggle { display: none; }
        @media (max-width: 768px) {
            .info-collapse-toggle { display: inline-block; }
            .info-text-full { display: none; }
        }

        /* ===== Formularios ===== */
        .form-control, .form-select {
            min-height: 44px;
            font-size: 16px;
        }

        /* ===== Modal ===== */
        @media (max-width: 576px) {
            .modal-dialog { margin: 0.5rem; }
        }

        /* =================================================================
           COMPONENTE PROPIO: tabla-com
           Clases con nombre único para evitar cualquier herencia de crm-theme.css
           ================================================================= */

        .card-com {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
            overflow: visible;
        }

        .card-com-header {
            padding: 0.85rem 1rem;
            font-weight: 600;
            border-bottom: 1px solid #e9ecef;
            border-radius: 12px 12px 0 0;
            background: #f8f9fa;
            color: #333;
        }

        .card-com-header.primary {
            background: var(--primary-color);
            color: #fff;
            border-bottom-color: var(--primary-color);
        }

        .card-com-body {
            padding: 1rem;
        }

        .card-com-body.p-0 {
            padding: 0;
        }

        /* ===== tabla-com: DESKTOP ===== */
        .tabla-com {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .tabla-com thead th {
            background: #f8f9fa;
            padding: 10px 12px;
            text-align: left;
            font-size: 0.85rem;
            color: #555;
            font-weight: 600;
            border-bottom: 1px solid #dee2e6;
        }
        .tabla-com tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f1f1;
            font-size: 0.92rem;
            vertical-align: middle;
        }
        .tabla-com tbody tr:last-child td { border-bottom: none; }

        .tabla-com .acciones-com {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
        }
        .tabla-com .btn-accion {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            min-height: 34px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0;
        }
        .tabla-com .btn-accion.edit    { border-color: #0d6efd; color: #0d6efd; }
        .tabla-com .btn-accion.toggle  { border-color: #ffc107; color: #b8860b; }
        .tabla-com .btn-accion.delete  { border-color: #dc3545; color: #dc3545; }
        .tabla-com .btn-accion:hover   { background: #f1f3f5; }

        .tabla-com .badge-com {
            display: inline-block;
            font-size: 0.75rem;
            padding: 0.3em 0.6em;
            border-radius: 6px;
            background: #6c757d;
            color: #fff;
        }
        .tabla-com .badge-com.ok  { background: #198754; }
        .tabla-com .badge-com.off { background: #6c757d; }

        /* ===== tabla-com: MÓVIL (cards) ===== */
        @media (max-width: 768px) {

            /* Forzar que TODOS los contenedores no recorten */
            .card-com,
            .card-com-body,
            .card-com-body.p-0,
            .tab-content,
            .tab-pane,
            .container-fluid,
            .row,
            [class*="col-"] {
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
                min-height: 0 !important;
            }

            /* La tabla se convierte en una pila de cards */
            .tabla-com {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                border-collapse: separate !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .tabla-com thead {
                display: none !important;
            }

            .tabla-com tbody {
                display: block !important;
                width: 100% !important;
                padding: 10px !important;
                box-sizing: border-box !important;
            }

            .tabla-com tbody tr {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                margin: 0 0 12px 0 !important;
                padding: 12px 14px !important;
                border: 1px solid #e0e0e0 !important;
                border-radius: 10px !important;
                background: #fff !important;
                box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important;
            }
            .tabla-com tbody tr:last-child { margin-bottom: 0 !important; }

            .tabla-com tbody td {
                display: flex !important;
                width: 100% !important;
                box-sizing: border-box !important;
                padding: 6px 0 !important;
                border: none !important;
                border-bottom: none !important;
                text-align: left !important;
                white-space: normal !important;
                word-break: break-word !important;
                overflow-wrap: anywhere !important;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                font-size: 0.92rem !important;
                line-height: 1.4 !important;
                height: auto !important;
                min-height: 0 !important;
                overflow: visible !important;
            }

            .tabla-com tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #666;
                font-size: 0.85rem;
                flex-shrink: 0;
                white-space: nowrap;
            }

            .tabla-com tbody td.acciones-cell {
                border-top: 1px solid #eee !important;
                margin-top: 6px !important;
                padding-top: 10px !important;
                justify-content: flex-end !important;
            }
            .tabla-com tbody td.acciones-cell::before { display: none !important; }

            .tabla-com tbody td[colspan] {
                display: block !important;
                text-align: center !important;
                padding: 16px !important;
            }
            .tabla-com tbody td[colspan]::before { display: none !important; }

            .tabla-com .acciones-com {
                display: flex !important;
                gap: 8px !important;
                justify-content: flex-end !important;
                flex-wrap: nowrap !important;
            }
            .tabla-com .btn-accion {
                min-width: 42px !important;
                min-height: 40px !important;
                font-size: 1rem !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark mb-4">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="fas fa-percentage me-2"></i>
                <span class="d-none d-sm-inline">Configuración de </span>Comisiones
            </span>
            <a href="Configuracion" class="btn btn-outline-light btn-sm btn-config">
                <i class="fas fa-arrow-left me-1"></i>
                <span class="d-none d-sm-inline">Volver a Configuración</span>
                <span class="d-sm-none">Volver</span>
            </a>
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
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1 me-2">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Info:</strong>
                    <span class="info-text-full d-none d-md-inline">
                        Las comisiones se calculan sobre la <strong>utilidad de cada producto vendido</strong>
                        menos los <strong>gastos de operación</strong> de la venta.
                        Aquí defines las <strong>áreas</strong> y los <strong>colaboradores</strong>.
                        Al asignar la comisión en caja (o al editar la venta) eliges área, colaborador
                        y <strong>capturas el porcentaje</strong>, porque cambia de un caso a otro.
                    </span>
                </div>
                <button class="btn btn-sm btn-link info-collapse-toggle p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#infoDetalle">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            <div class="collapse mt-2 d-md-none" id="infoDetalle">
                Las comisiones se calculan sobre la <strong>utilidad de cada producto vendido</strong>
                menos los <strong>gastos de operación</strong> de la venta.
                Aquí defines las <strong>áreas</strong> y los <strong>colaboradores</strong>.
                Al asignar la comisión en caja (o al editar la venta) eliges área, colaborador
                y <strong>capturas el porcentaje</strong>, porque cambia de un caso a otro.
            </div>
        </div>

        <ul class="nav nav-tabs mb-4" id="comisionesTabs">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-areas">
                    <i class="fas fa-folder me-1"></i>Áreas
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-colaboradores">
                    <i class="fas fa-users me-1"></i>Colaboradores
                </button>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ===================== ÁREAS ===================== -->
            <div class="tab-pane fade show active" id="tab-areas">
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card-com mb-3">
                            <div class="card-com-header primary">
                                <i class="fas fa-folder-plus me-2"></i>Nueva Área
                            </div>
                            <div class="card-com-body">
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

                        <div class="card-com">
                            <div class="card-com-header"><i class="fas fa-list me-2"></i>Áreas existentes</div>
                            <div class="card-com-body p-0">
                                <table class="tabla-com">
                                    <tbody>
                                    <?php foreach ($areas as $a): ?>
                                        <tr>
                                            <td data-label="Área">
                                                <?php echo htmlspecialchars($a['nombre']); ?>
                                                <?php if (!$a['activo']): ?>
                                                    <span class="badge-com off ms-1">Inactiva</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="acciones-cell">
                                                <div class="acciones-com">
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="accion" value="cambiar_estado_area">
                                                        <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                        <input type="hidden" name="activo" value="<?php echo $a['activo'] ? 0 : 1; ?>">
                                                        <button type="submit" class="btn-accion toggle" title="Cambiar estado"><i class="fas fa-power-off"></i></button>
                                                    </form>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar el área \'<?php echo htmlspecialchars($a['nombre']); ?>\'?');">
                                                        <input type="hidden" name="accion" value="eliminar_area">
                                                        <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                        <button type="submit" class="btn-accion delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($areas)): ?>
                                        <tr><td colspan="2" class="text-center text-muted py-3">No hay áreas registradas</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== COLABORADORES ===================== -->
            <div class="tab-pane fade" id="tab-colaboradores">
                <div class="row">
                    <div class="col-lg-5">
                        <div class="card-com mb-3">
                            <div class="card-com-header primary">
                                <i class="fas fa-user-plus me-2"></i>Nuevo Colaborador
                            </div>
                            <div class="card-com-body">
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
                        <div class="card-com">
                            <div class="card-com-header"><i class="fas fa-list me-2"></i>Lista de Colaboradores</div>
                            <div class="card-com-body p-0">
                                <table class="tabla-com">
                                    <thead>
                                        <tr><th>Nombre</th><th>Área</th><th>Estado</th><th style="text-align:right;">Acciones</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($colaboradores as $c): ?>
                                        <tr>
                                            <td data-label="Nombre"><?php echo htmlspecialchars($c['nombre']); ?></td>
                                            <td data-label="Área">
                                                <?php echo $c['area_nombre'] ? '<span class="badge-com">' . htmlspecialchars($c['area_nombre']) . '</span>' : '<span class="text-muted">Sin área</span>'; ?>
                                            </td>
                                            <td data-label="Estado">
                                                <?php echo $c['activo'] ? '<span class="badge-com ok">Activo</span>' : '<span class="badge-com off">Inactivo</span>'; ?>
                                            </td>
                                            <td class="acciones-cell">
                                                <div class="acciones-com">
                                                    <button type="button" class="btn-accion edit btn-editar-colaborador"
                                                        data-id="<?php echo $c['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($c['nombre']); ?>"
                                                        data-area="<?php echo $c['area_id']; ?>"
                                                        title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="accion" value="cambiar_estado_colaborador">
                                                        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                        <input type="hidden" name="activo" value="<?php echo $c['activo'] ? 0 : 1; ?>">
                                                        <button type="submit" class="btn-accion toggle" title="Cambiar estado"><i class="fas fa-power-off"></i></button>
                                                    </form>
                                                    <button type="button" class="btn-accion delete btn-eliminar-colaborador"
                                                        data-id="<?php echo $c['id']; ?>" data-nombre="<?php echo htmlspecialchars($c['nombre']); ?>"
                                                        title="Eliminar">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </div>
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
    </div>

    <!-- Modal editar colaborador -->
    <div class="modal fade" id="modalEditarColaborador" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
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

        const infoCollapse = document.getElementById('infoDetalle');
        if (infoCollapse) {
            infoCollapse.addEventListener('show.bs.collapse', () => {
                document.querySelector('.info-collapse-toggle i').classList.replace('fa-chevron-down', 'fa-chevron-up');
            });
            infoCollapse.addEventListener('hide.bs.collapse', () => {
                document.querySelector('.info-collapse-toggle i').classList.replace('fa-chevron-up', 'fa-chevron-down');
            });
        }
    </script>
</body>
</html>