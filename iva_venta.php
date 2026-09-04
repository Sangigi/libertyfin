<?php
// iva_venta.php
// Consulta y actualiza el IVA de una venta ya cerrada.
//
// El IVA es OPCIONAL: si la venta no lo lleva, se deja en 0.
//
// Importante: el IVA NO forma parte de la base de comisión. Las comisiones
// se calculan sobre (precio - costo - gasto de operación) por línea, que
// son montos sin IVA. Por eso cambiar el IVA aquí ajusta el total de la
// venta pero NO recalcula ninguna comisión.

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
    // Consultar el IVA actual de la venta
    // =====================================================================
    if ($accion === 'obtener_iva_venta') {
        $venta_id = intval($_GET['venta_id'] ?? $_POST['venta_id'] ?? 0);
        if ($venta_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_id inválido']);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT subtotal, descuento, iva, total
            FROM ventas WHERE id = ?
        ");
        $stmt->execute([$venta_id]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$v) {
            echo json_encode(['success' => false, 'message' => 'La venta no existe']);
            exit();
        }

        // El precio se captura con IVA incluido, asi que el total es fijo y
        // la base sale de dividirlo: base = total / (1 + pct/100)
        $base = (float)$v['subtotal'] - (float)$v['descuento'];
        $pct  = $base > 0 ? round(((float)$v['iva'] / $base) * 100, 2) : 0;

        echo json_encode([
            'success'    => true,
            'base'       => round($base, 2),
            'iva'        => round((float)$v['iva'], 2),
            'porcentaje' => $pct,
            'total'      => round((float)$v['total'], 2),
            'es_admin'   => (($_SESSION['usuario_rol'] ?? '') === 'admin')
        ]);
        exit();
    }

    // =====================================================================
    // Actualizar el IVA de la venta
    //
    // Solo admin: cambiar el IVA mueve el total de una venta ya cobrada.
    // La validación va aquí, en el servidor, no solo escondiendo el botón.
    // =====================================================================
    if ($accion === 'actualizar_iva_venta') {
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Solo un administrador puede modificar el IVA de una venta']);
            exit();
        }

        $venta_id = intval($_POST['venta_id'] ?? 0);
        $pct = floatval($_POST['porcentaje'] ?? -1);

        if ($venta_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_id inválido']);
            exit();
        }
        if ($pct < 0 || $pct > 100) {
            echo json_encode(['success' => false, 'message' => 'El IVA debe estar entre 0 y 100']);
            exit();
        }

        $stmt = $conn->prepare("SELECT subtotal, descuento, total, estado FROM ventas WHERE id = ?");
        $stmt->execute([$venta_id]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$v) {
            echo json_encode(['success' => false, 'message' => 'La venta no existe']);
            exit();
        }
        if ($v['estado'] === 'cancelada') {
            echo json_encode(['success' => false, 'message' => 'No se puede modificar una venta cancelada']);
            exit();
        }

        // El precio se cobró con IVA incluido, así que EL TOTAL NO CAMBIA:
        // lo que cambia es cuánto de ese total es base y cuánto impuesto.
        //   base = total / (1 + pct/100)      iva = total - base
        // Cambiar esto no altera lo que el cliente pagó.
        $total  = round((float)$v['total'], 2);
        $factor = 1 + ($pct / 100);
        $base   = round($total / $factor, 2);
        $iva    = round($total - $base, 2);

        // El descuento se reexpresa sin IVA para que la resta cierre:
        //   subtotal - descuento = base
        $prop_desc = ((float)$v['subtotal'] > 0)
                   ? ((float)$v['descuento'] / (float)$v['subtotal']) : 0;
        $subtotal_nuevo  = round($base / max(0.000001, (1 - $prop_desc)), 2);
        $descuento_nuevo = round($subtotal_nuevo - $base, 2);

        $stmt_up = $conn->prepare("
            UPDATE ventas SET subtotal = ?, descuento = ?, iva = ?, total = ? WHERE id = ?
        ");
        $stmt_up->execute([$subtotal_nuevo, $descuento_nuevo, $iva, $total, $venta_id]);

        echo json_encode([
            'success'    => true,
            'message'    => 'IVA actualizado a ' . number_format($pct, 2) . '% ($' . number_format($iva, 2) . ' incluidos en el total). '
                          . 'El total de la venta no cambia: $' . number_format($total, 2),
            'base'       => round($base, 2),
            'iva'        => $iva,
            'porcentaje' => round($pct, 2),
            'total'      => $total
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}