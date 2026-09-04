<?php
// editar_venta.php
// Permite editar una venta ya registrada (cantidad, precio unitario y
// descuento por producto, más cliente/descripción/método de pago).
// Solo el rol 'admin' de la cuenta puede usar este endpoint.
//
// Al editar, se recalcula en cascada:
//  - subtotal/descuento/total de cada venta_detalle y de la venta
//  - el stock (se revierte la cantidad vieja y se aplica la nueva)
//  - el gasto automático de "Costo de mercancía" ligado a la venta
//  - las comisiones (venta_comisiones) del producto, si el monto_base
//    cambia por el nuevo precio/costo
//
// Espera (POST, JSON):
// {
//   "venta_id": 123,
//   "cliente_id": 5 | null,
//   "descripcion": "texto" | null,
//   "detalles": [
//       { "id": 456, "cantidad": 2, "precio_unitario": 150.00, "descuento": 0 },
//       ...
//   ]
// }

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Solo admin puede editar ventas.
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Se requieren permisos de administrador para editar una venta']);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    // Fallback por si se manda como form-data en vez de JSON
    $input = $_POST;
    if (isset($input['detalles']) && is_string($input['detalles'])) {
        $input['detalles'] = json_decode($input['detalles'], true);
    }
}

$venta_id = intval($input['venta_id'] ?? 0);
$detalles_nuevos = $input['detalles'] ?? [];

if ($venta_id <= 0 || !is_array($detalles_nuevos) || empty($detalles_nuevos)) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos: se requiere venta_id y al menos un detalle']);
    exit();
}

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    $conn->beginTransaction();

    // Verificar que la venta existe
    $stmt_venta = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
    $stmt_venta->execute([$venta_id]);
    $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'La venta no existe']);
        exit();
    }

    $sucursal_id = $venta['sucursal_id'];
    $subtotal_nuevo = 0;
    $descuento_nuevo_total = 0;
    $costo_total_venta = 0;

    foreach ($detalles_nuevos as $det) {
        $detalle_id = intval($det['id'] ?? 0);
        $cantidad_nueva = floatval($det['cantidad'] ?? 0);
        $precio_nuevo = floatval($det['precio_unitario'] ?? 0);
        $descuento_nuevo = floatval($det['descuento'] ?? 0);

        if ($detalle_id <= 0 || $cantidad_nueva <= 0) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Detalle inválido: cada línea requiere id y cantidad mayor a 0']);
            exit();
        }

        // Traer el detalle actual (para conocer producto y cantidad vieja)
        $stmt_det = $conn->prepare("SELECT * FROM venta_detalles WHERE id = ? AND venta_id = ?");
        $stmt_det->execute([$detalle_id, $venta_id]);
        $detalle_actual = $stmt_det->fetch(PDO::FETCH_ASSOC);

        if (!$detalle_actual) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => "El detalle #$detalle_id no pertenece a esta venta"]);
            exit();
        }

        $producto_id = $detalle_actual['producto_id'];
        $cantidad_vieja = floatval($detalle_actual['cantidad']);

        // Traer costo actual del producto (para recalcular gasto y comisiones)
        $stmt_prod = $conn->prepare("SELECT costo FROM productos WHERE id = ?");
        $stmt_prod->execute([$producto_id]);
        $costo_unitario = floatval($stmt_prod->fetchColumn() ?: 0);

        // Ajustar stock: revertir la cantidad vieja y aplicar la nueva
        $diferencia = $cantidad_nueva - $cantidad_vieja;
        if ($diferencia != 0) {
            $stmt_stock = $conn->prepare("
                UPDATE producto_sucursal
                SET stock = stock - ?
                WHERE producto_id = ? AND sucursal_id = ?
            ");
            $stmt_stock->execute([$diferencia, $producto_id, $sucursal_id]);
        }

        $subtotal_linea = $precio_nuevo * $cantidad_nueva;
        $total_linea = max(0, $subtotal_linea - $descuento_nuevo);

        $stmt_update_det = $conn->prepare("
            UPDATE venta_detalles
            SET cantidad = ?, precio_unitario = ?, subtotal = ?, descuento = ?, total = ?
            WHERE id = ?
        ");
        $stmt_update_det->execute([
            $cantidad_nueva, $precio_nuevo, $subtotal_linea, $descuento_nuevo, $total_linea, $detalle_id
        ]);

        $subtotal_nuevo += $subtotal_linea;
        $descuento_nuevo_total += $descuento_nuevo;
        $costo_total_venta += $costo_unitario * $cantidad_nueva;

        // Recalcular monto_base/monto_comision de las comisiones ligadas a
        // este detalle, si es que tiene alguna asignada.
        $monto_base_nuevo = max(0, ($precio_nuevo - $costo_unitario) * $cantidad_nueva);

        $stmt_comisiones = $conn->prepare("
            SELECT id, porcentaje_regla, porcentaje_reparto
            FROM venta_comisiones
            WHERE venta_detalle_id = ?
        ");
        $stmt_comisiones->execute([$detalle_id]);
        $comisiones_detalle = $stmt_comisiones->fetchAll(PDO::FETCH_ASSOC);

        if ($comisiones_detalle) {
            $stmt_update_com = $conn->prepare("
                UPDATE venta_comisiones
                SET costo_unitario = ?, precio_unitario = ?, cantidad = ?, monto_base = ?, monto_comision = ?
                WHERE id = ?
            ");
            foreach ($comisiones_detalle as $com) {
                $monto_comision_nuevo = $monto_base_nuevo
                    * (floatval($com['porcentaje_regla']) / 100)
                    * (floatval($com['porcentaje_reparto']) / 100);

                $stmt_update_com->execute([
                    $costo_unitario, $precio_nuevo, $cantidad_nueva,
                    $monto_base_nuevo, $monto_comision_nuevo, $com['id']
                ]);
            }
        }
    }

    // Recalcular totales de la venta. El sistema actual no maneja IVA por
    // línea (caja.php siempre guarda iva = 0), así que replicamos ese
    // mismo criterio aquí para no introducir inconsistencias.
    $subtotal_sin_iva = max(0, $subtotal_nuevo - $descuento_nuevo_total);
    $iva_total = 0;
    $total_nuevo = $subtotal_sin_iva + $iva_total;

    $cliente_id = array_key_exists('cliente_id', $input) ? ($input['cliente_id'] ?: null) : $venta['cliente_id'];
    $descripcion = array_key_exists('descripcion', $input)
        ? (trim((string)$input['descripcion']) !== '' ? mb_substr(trim((string)$input['descripcion']), 0, 500) : null)
        : $venta['descripcion'];

    $stmt_update_venta = $conn->prepare("
        UPDATE ventas
        SET subtotal = ?, descuento = ?, iva = ?, total = ?, cliente_id = ?, descripcion = ?
        WHERE id = ?
    ");
    $stmt_update_venta->execute([
        $subtotal_nuevo, $descuento_nuevo_total, $iva_total, $total_nuevo,
        $cliente_id, $descripcion, $venta_id
    ]);

    // Recalcular el gasto automático de costo de mercancía ligado a esta venta
    $stmt_check_gasto = $conn->prepare("
        SELECT id FROM gastos WHERE venta_id = ? AND tipo = 'automatico' LIMIT 1
    ");
    $stmt_check_gasto->execute([$venta_id]);
    $gasto_id = $stmt_check_gasto->fetchColumn();

    if ($gasto_id) {
        if ($costo_total_venta > 0) {
            $stmt_update_gasto = $conn->prepare("UPDATE gastos SET monto = ? WHERE id = ?");
            $stmt_update_gasto->execute([$costo_total_venta, $gasto_id]);
        } else {
            $stmt_delete_gasto = $conn->prepare("DELETE FROM gastos WHERE id = ?");
            $stmt_delete_gasto->execute([$gasto_id]);
        }
    } elseif ($costo_total_venta > 0) {
        $stmt_insert_gasto = $conn->prepare("
            INSERT INTO gastos (concepto, categoria, monto, tipo, origen, venta_id, usuario_id, sucursal_id, metodo_pago, fecha, descripcion)
            VALUES (?, 'Costo de venta', ?, 'automatico', 'venta', ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt_insert_gasto->execute([
            "Costo de mercancía - Venta #" . $venta['codigo_venta'],
            $costo_total_venta,
            $venta_id,
            $_SESSION['usuario_id'],
            $sucursal_id,
            $venta['metodo_pago'],
            "Costo recalculado automáticamente al editar la venta " . $venta['codigo_venta']
        ]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Venta actualizada correctamente',
        'nuevo_total' => $total_nuevo
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("Error al editar venta $venta_id: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al editar: ' . $e->getMessage()]);
}