<?php
// guardar_comision_producto.php
// Endpoint AJAX llamado desde caja.php (o desde el ticket ya cerrado) para
// asignar una comisión a un producto específico de una venta ya guardada
// (venta_detalle_id). Se puede asignar más de una comisión por producto
// (por ejemplo: Abogado + Vendedor + Gerente + Over, cada uno con su propio
// colaborador).

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    $accion = $_POST['accion'] ?? ($_GET['accion'] ?? '');

    // =====================================================================
    // Listar catálogos (áreas + reglas + colaboradores + % de reparto) para
    // construir el selector en el modal de caja.
    // =====================================================================
    if ($accion === 'obtener_catalogos') {
        $areas = $conn->query("
            SELECT id, nombre FROM comision_areas WHERE activo = 1 ORDER BY nombre
        ")->fetchAll(PDO::FETCH_ASSOC);

        $reglas = $conn->query("
            SELECT id, area_id, concepto, porcentaje
            FROM comision_reglas
            WHERE activo = 1
            ORDER BY area_id, orden
        ")->fetchAll(PDO::FETCH_ASSOC);

        $colaboradores = $conn->query("
            SELECT id, nombre, area_id FROM comision_colaboradores WHERE activo = 1 ORDER BY nombre
        ")->fetchAll(PDO::FETCH_ASSOC);

        $porcentajes = $conn->query("
            SELECT id, valor FROM comision_porcentajes_reparto WHERE activo = 1 ORDER BY valor DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'areas' => $areas,
            'reglas' => $reglas,
            'colaboradores' => $colaboradores,
            'porcentajes' => $porcentajes
        ]);
        exit();
    }

    // =====================================================================
    // Obtener las comisiones ya asignadas a un producto de una venta
    // =====================================================================
    if ($accion === 'listar_comisiones_detalle') {
        $venta_detalle_id = intval($_GET['venta_detalle_id'] ?? $_POST['venta_detalle_id'] ?? 0);
        if ($venta_detalle_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_detalle_id inválido']);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT * FROM venta_comisiones WHERE venta_detalle_id = ? ORDER BY id
        ");
        $stmt->execute([$venta_detalle_id]);
        $comisiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'comisiones' => $comisiones]);
        exit();
    }

    // =====================================================================
    // Guardar (agregar) una comisión a un producto de una venta
    // =====================================================================
    if ($accion === 'guardar_comision') {
        $venta_id = intval($_POST['venta_id'] ?? 0);
        $venta_detalle_id = intval($_POST['venta_detalle_id'] ?? 0);
        $area_id = intval($_POST['area_id'] ?? 0);
        $regla_id = intval($_POST['regla_id'] ?? 0);
        $colaborador_id = intval($_POST['colaborador_id'] ?? 0);
        $porcentaje_reparto = floatval($_POST['porcentaje_reparto'] ?? 100);

        if ($venta_id <= 0 || $venta_detalle_id <= 0 || $regla_id <= 0 || $colaborador_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Faltan datos requeridos (venta, producto, concepto o colaborador)']);
            exit();
        }

        // Traer el detalle de la venta (precio, cantidad) y el costo actual del producto
        $stmt_detalle = $conn->prepare("
            SELECT vd.cantidad, vd.precio_unitario, vd.producto_id, p.costo
            FROM venta_detalles vd
            INNER JOIN productos p ON vd.producto_id = p.id
            WHERE vd.id = ? AND vd.venta_id = ?
        ");
        $stmt_detalle->execute([$venta_detalle_id, $venta_id]);
        $detalle = $stmt_detalle->fetch(PDO::FETCH_ASSOC);

        if (!$detalle) {
            echo json_encode(['success' => false, 'message' => 'No se encontró el producto de la venta indicado']);
            exit();
        }

        $cantidad = floatval($detalle['cantidad']);
        $precio_unitario = floatval($detalle['precio_unitario']);
        $costo_unitario = floatval($detalle['costo'] ?? 0);

        // Utilidad de la línea = (precio - costo) * cantidad. Nunca negativa.
        $monto_base = max(0, ($precio_unitario - $costo_unitario) * $cantidad);

        // Traer el área y la regla (para guardar snapshot + porcentaje del rol)
        $stmt_area = $conn->prepare("SELECT nombre FROM comision_areas WHERE id = ?");
        $stmt_area->execute([$area_id]);
        $area_nombre = $stmt_area->fetchColumn() ?: '';

        $stmt_regla = $conn->prepare("SELECT concepto, porcentaje FROM comision_reglas WHERE id = ?");
        $stmt_regla->execute([$regla_id]);
        $regla = $stmt_regla->fetch(PDO::FETCH_ASSOC);

        if (!$regla) {
            echo json_encode(['success' => false, 'message' => 'El concepto/regla seleccionado no existe']);
            exit();
        }

        $stmt_colab = $conn->prepare("SELECT nombre FROM comision_colaboradores WHERE id = ?");
        $stmt_colab->execute([$colaborador_id]);
        $colaborador_nombre = $stmt_colab->fetchColumn();

        if (!$colaborador_nombre) {
            echo json_encode(['success' => false, 'message' => 'El colaborador seleccionado no existe']);
            exit();
        }

        $porcentaje_regla = floatval($regla['porcentaje']);
        $monto_comision = $monto_base * ($porcentaje_regla / 100) * ($porcentaje_reparto / 100);

        $stmt_ins = $conn->prepare("
            INSERT INTO venta_comisiones
                (venta_id, venta_detalle_id, area_id, area_nombre, regla_id, concepto,
                 colaborador_id, colaborador_nombre, porcentaje_regla, porcentaje_reparto,
                 costo_unitario, precio_unitario, cantidad, monto_base, monto_comision, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_ins->execute([
            $venta_id, $venta_detalle_id, $area_id, $area_nombre, $regla_id, $regla['concepto'],
            $colaborador_id, $colaborador_nombre, $porcentaje_regla, $porcentaje_reparto,
            $costo_unitario, $precio_unitario, $cantidad, $monto_base, $monto_comision,
            $_SESSION['usuario_id'] ?? null
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Comisión asignada: {$colaborador_nombre} ({$regla['concepto']}) - $" . number_format($monto_comision, 2),
            'monto_base' => $monto_base,
            'monto_comision' => $monto_comision
        ]);
        exit();
    }

    // =====================================================================
    // Eliminar una comisión ya asignada
    // =====================================================================
    if ($accion === 'eliminar_comision') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM venta_comisiones WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Comisión eliminada']);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}