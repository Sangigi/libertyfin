<?php
// gastos_venta.php
// Endpoint AJAX para gastos de operación ligados a una venta específica
// (por ejemplo: flete, empaque, comisión de plataforma de pago, etc).
// Se usa desde caja.php (venta en curso) y desde ventas_lista.php
// (venta ya cerrada, para añadir gastos después).
//
// Acciones (accion=...):
//   agregar_gasto            POST: venta_id, concepto, monto
//   listar_gastos_venta      GET/POST: venta_id
//   eliminar_gasto           POST: id

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
    // Agregar un gasto de operación a una venta
    // =====================================================================
    if ($accion === 'agregar_gasto') {
        $venta_id = intval($_POST['venta_id'] ?? 0);
        $concepto = trim($_POST['concepto'] ?? '');
        $monto = floatval($_POST['monto'] ?? 0);

        if ($venta_id <= 0 || $concepto === '' || $monto <= 0) {
            echo json_encode(['success' => false, 'message' => 'Faltan datos: venta, concepto o monto inválido']);
            exit();
        }

        // Confirmar que la venta existe y traer sucursal/método de pago
        $stmt_venta = $conn->prepare("SELECT sucursal_id, metodo_pago FROM ventas WHERE id = ?");
        $stmt_venta->execute([$venta_id]);
        $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            echo json_encode(['success' => false, 'message' => 'La venta no existe']);
            exit();
        }

        $stmt_ins = $conn->prepare("
            INSERT INTO gastos (concepto, categoria, monto, tipo, origen, venta_id, usuario_id, sucursal_id, metodo_pago, descripcion, fecha)
            VALUES (?, 'Gasto de operación', ?, 'manual', 'venta', ?, ?, ?, ?, ?, NOW())
        ");
        $stmt_ins->execute([
            $concepto,
            $monto,
            $venta_id,
            $_SESSION['usuario_id'] ?? null,
            $venta['sucursal_id'],
            $venta['metodo_pago'],
            'Gasto de operación agregado a la venta #' . $venta_id
        ]);

        echo json_encode([
            'success' => true,
            'id' => $conn->lastInsertId(),
            'message' => "Gasto agregado: $concepto - $" . number_format($monto, 2)
        ]);
        exit();
    }

    // =====================================================================
    // Listar los gastos de operación ya agregados a una venta
    // =====================================================================
    if ($accion === 'listar_gastos_venta') {
        $venta_id = intval($_GET['venta_id'] ?? $_POST['venta_id'] ?? 0);
        if ($venta_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_id inválido']);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT id, concepto, monto, tipo, fecha
            FROM gastos
            WHERE venta_id = ? AND origen = 'venta'
            ORDER BY id
        ");
        $stmt->execute([$venta_id]);
        $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'gastos' => $gastos]);
        exit();
    }

    // =====================================================================
    // Eliminar un gasto de operación (no elimina el gasto automático de
    // costo de mercancía, ese se maneja aparte).
    // =====================================================================
    if ($accion === 'eliminar_gasto') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM gastos WHERE id = ? AND tipo = 'manual' AND origen = 'venta'");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Gasto eliminado']);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);

} catch (Exception $e) {
    error_log("Error en gastos_venta.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}