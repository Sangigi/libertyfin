<?php
// pagos_venta.php
// Anticipos, abonos y cuentas por cobrar. También permite corregir la
// fecha de una venta.
//
// MODELO
//   `ventas`      guarda el TOTAL de la venta (lo vendido).
//   `venta_pagos` guarda lo que realmente entró al banco o a la caja.
//   saldo = total - SUM(pagos activos). Nunca se guarda en una columna,
//   siempre se calcula, para que no se pueda desincronizar.
//
// COMISIONES (opción B)
//   La comisión NO se genera al vender: nace con cada pago, y solo por la
//   parte cobrada:
//       comisión del pago = monto_comision x (monto del pago / total)
//   Si el cliente nunca liquida, esa comisión nunca existe.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

function esAdmin() {
    return ($_SESSION['usuario_rol'] ?? '') === 'admin';
}

/**
 * Devuelve total, cobrado y saldo de una venta.
 */
function resumenVenta($conn, $venta_id) {
    $stmt = $conn->prepare("
        SELECT v.total,
               COALESCE((SELECT SUM(p.monto) FROM venta_pagos p
                         WHERE p.venta_id = v.id AND p.cancelado = 0), 0) AS cobrado
        FROM ventas v WHERE v.id = ?
    ");
    $stmt->execute([$venta_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $total   = round((float)$r['total'], 2);
    $cobrado = round((float)$r['cobrado'], 2);
    return [
        'total'       => $total,
        'cobrado'     => $cobrado,
        'saldo'       => round($total - $cobrado, 2),
        'pct_cobrado' => $total > 0 ? round($cobrado / $total * 100, 2) : 0
    ];
}

/**
 * Genera las comisiones que le corresponden a un pago.
 * Se llama justo después de insertar el pago.
 */
function generarComisionesDelPago($conn, $pago_id, $venta_id, $monto_pago, $fecha_pago) {
    $stmt_v = $conn->prepare("SELECT total FROM ventas WHERE id = ?");
    $stmt_v->execute([$venta_id]);
    $total = (float)$stmt_v->fetchColumn();
    if ($total <= 0) return 0;

    $proporcion = $monto_pago / $total;

    $stmt_c = $conn->prepare("
        SELECT id, colaborador_id, colaborador_nombre, area_nombre,
               porcentaje_regla, monto_comision
        FROM venta_comisiones
        WHERE venta_id = ? AND cancelada = 0
    ");
    $stmt_c->execute([$venta_id]);
    $asignaciones = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

    if (empty($asignaciones)) return 0;

    $stmt_i = $conn->prepare("
        INSERT INTO pago_comisiones
            (pago_id, venta_comision_id, venta_id, colaborador_id, colaborador_nombre,
             area_nombre, porcentaje, proporcion_cobrada, monto, fecha_pago)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $generadas = 0;
    foreach ($asignaciones as $a) {
        $monto = round((float)$a['monto_comision'] * $proporcion, 2);
        if ($monto <= 0) continue;
        $stmt_i->execute([
            $pago_id, $a['id'], $venta_id, $a['colaborador_id'], $a['colaborador_nombre'],
            $a['area_nombre'], $a['porcentaje_regla'], round($proporcion, 6), $monto, $fecha_pago
        ]);
        $generadas++;
    }
    return $generadas;
}

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    $accion = $_POST['accion'] ?? ($_GET['accion'] ?? '');

    // =====================================================================
    // Listar los pagos de una venta
    // =====================================================================
    if ($accion === 'listar_pagos') {
        $venta_id = intval($_GET['venta_id'] ?? $_POST['venta_id'] ?? 0);
        if ($venta_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_id inválido']);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT p.*, u.nombre AS usuario_nombre,
                   (SELECT COALESCE(SUM(pc.monto), 0) FROM pago_comisiones pc
                    WHERE pc.pago_id = p.id) AS comision_generada
            FROM venta_pagos p
            LEFT JOIN usuarios u ON u.id = p.usuario_id
            WHERE p.venta_id = ? AND p.cancelado = 0
            ORDER BY p.fecha_pago, p.id
        ");
        $stmt->execute([$venta_id]);

        echo json_encode([
            'success'  => true,
            'pagos'    => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'resumen'  => resumenVenta($conn, $venta_id),
            'es_admin' => esAdmin()
        ]);
        exit();
    }

    // =====================================================================
    // Registrar un anticipo o abono
    // =====================================================================
    if ($accion === 'registrar_pago') {
        $venta_id    = intval($_POST['venta_id'] ?? 0);
        $monto       = round(floatval($_POST['monto'] ?? 0), 2);
        $fecha_pago  = trim($_POST['fecha_pago'] ?? '');
        $tipo        = $_POST['tipo'] ?? 'abono';
        $metodo      = trim($_POST['metodo_pago'] ?? '');
        $banco       = trim($_POST['banco'] ?? '');
        $referencia  = trim($_POST['referencia'] ?? '');
        $notas       = trim($_POST['notas'] ?? '');

        if (!in_array($tipo, ['anticipo', 'abono', 'liquidacion'], true)) {
            $tipo = 'abono';
        }
        if ($venta_id <= 0 || $monto <= 0) {
            echo json_encode(['success' => false, 'message' => 'Captura un monto mayor a 0']);
            exit();
        }
        $d = DateTime::createFromFormat('Y-m-d', $fecha_pago);
        if (!$d || $d->format('Y-m-d') !== $fecha_pago) {
            echo json_encode(['success' => false, 'message' => 'Fecha de pago inválida']);
            exit();
        }

        $res = resumenVenta($conn, $venta_id);
        if (!$res) {
            echo json_encode(['success' => false, 'message' => 'La venta no existe']);
            exit();
        }
        // No se puede cobrar más de lo que vale la venta: eso descuadraría
        // la comisión (pasaría del 100% de lo asignado).
        if ($monto > $res['saldo'] + 0.005) {
            echo json_encode([
                'success' => false,
                'message' => 'El pago ($' . number_format($monto, 2) . ') es mayor al saldo pendiente ($'
                           . number_format($res['saldo'], 2) . ')'
            ]);
            exit();
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO venta_pagos
                    (venta_id, tipo, monto, fecha_pago, metodo_pago, banco, referencia,
                     notas, usuario_id, sucursal_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $venta_id, $tipo, $monto, $fecha_pago,
                $metodo ?: null, $banco ?: null, $referencia ?: null, $notas ?: null,
                $_SESSION['usuario_id'] ?? null, $_SESSION['sucursal_id'] ?? null
            ]);
            $pago_id = $conn->lastInsertId();

            $n = generarComisionesDelPago($conn, $pago_id, $venta_id, $monto, $fecha_pago);
            $conn->commit();

            $res = resumenVenta($conn, $venta_id);
            echo json_encode([
                'success' => true,
                'message' => 'Pago registrado por $' . number_format($monto, 2)
                           . ($n > 0 ? " · $n comisión(es) generada(s)" : ''),
                'pago_id' => $pago_id,
                'resumen' => $res,
                'liquidada' => $res['saldo'] <= 0.005
            ]);
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        exit();
    }

    // =====================================================================
    // Cancelar un pago (cancelación lógica)
    // Al cancelarlo se borran las comisiones que ese pago generó: nunca
    // entró el dinero, así que esa comisión no debe existir.
    // =====================================================================
    if ($accion === 'cancelar_pago') {
        if (!esAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Solo un administrador puede cancelar un pago']);
            exit();
        }

        $id = intval($_POST['id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit();
        }
        if ($motivo === '') {
            echo json_encode(['success' => false, 'message' => 'Escribe el motivo de la cancelación']);
            exit();
        }

        $stmt = $conn->prepare("SELECT venta_id, monto, cancelado FROM venta_pagos WHERE id = ?");
        $stmt->execute([$id]);
        $pago = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pago) {
            echo json_encode(['success' => false, 'message' => 'El pago no existe']);
            exit();
        }
        if ((int)$pago['cancelado'] === 1) {
            echo json_encode(['success' => false, 'message' => 'Ese pago ya estaba cancelado']);
            exit();
        }

        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM pago_comisiones WHERE pago_id = ?")->execute([$id]);
            $conn->prepare("
                UPDATE venta_pagos
                SET cancelado = 1, cancelado_por = ?, fecha_cancelacion = NOW(),
                    motivo_cancelacion = ?
                WHERE id = ? AND cancelado = 0
            ")->execute([$_SESSION['usuario_id'] ?? null, mb_substr($motivo, 0, 255), $id]);
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Pago cancelado por $' . number_format((float)$pago['monto'], 2)
                       . '. Se retiraron las comisiones que había generado.',
            'resumen' => resumenVenta($conn, $pago['venta_id'])
        ]);
        exit();
    }

    // =====================================================================
    // Corregir la fecha de la venta
    //
    // Solo admin, sin restricción de periodo: sirve para registrar en
    // septiembre una venta que fue de agosto.
    //
    // OJO: mover una venta cambia reportes de meses ya cerrados. Por eso
    // se conserva la fecha original y queda registrado quién la movió.
    // =====================================================================
    if ($accion === 'actualizar_fecha_venta') {
        if (!esAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Solo un administrador puede cambiar la fecha de una venta']);
            exit();
        }

        $venta_id   = intval($_POST['venta_id'] ?? 0);
        $nueva      = trim($_POST['fecha'] ?? '');
        $motivo     = trim($_POST['motivo'] ?? '');

        if ($venta_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_id inválido']);
            exit();
        }
        $d = DateTime::createFromFormat('Y-m-d', $nueva);
        if (!$d || $d->format('Y-m-d') !== $nueva) {
            echo json_encode(['success' => false, 'message' => 'Fecha inválida (formato AAAA-MM-DD)']);
            exit();
        }
        if ($motivo === '') {
            echo json_encode(['success' => false, 'message' => 'Escribe el motivo del cambio de fecha']);
            exit();
        }

        $stmt = $conn->prepare("SELECT fecha, fecha_original FROM ventas WHERE id = ?");
        $stmt->execute([$venta_id]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            echo json_encode(['success' => false, 'message' => 'La venta no existe']);
            exit();
        }

        // La hora se conserva; solo se recorre el día.
        $hora = date('H:i:s', strtotime($v['fecha']));
        $nueva_completa = $nueva . ' ' . $hora;

        // La fecha original se guarda una sola vez, en el primer cambio.
        $original = $v['fecha_original'] ?: $v['fecha'];

        $conn->prepare("
            UPDATE ventas
            SET fecha = ?, fecha_original = ?, fecha_modificada_por = ?,
                fecha_modificacion = NOW(), motivo_fecha = ?
            WHERE id = ?
        ")->execute([
            $nueva_completa, $original, $_SESSION['usuario_id'] ?? null,
            mb_substr($motivo, 0, 255), $venta_id
        ]);

        echo json_encode([
            'success'  => true,
            'message'  => 'Fecha de la venta movida a ' . date('d/m/Y', strtotime($nueva_completa))
                        . '. Los reportes de ese mes van a cambiar.',
            'fecha'    => $nueva_completa,
            'original' => $original
        ]);
        exit();
    }

    // =====================================================================
    // Cuentas por cobrar
    // =====================================================================
    if ($accion === 'cuentas_por_cobrar') {
        $stmt = $conn->query("
            SELECT * FROM v_cuentas_por_cobrar ORDER BY dias_desde_la_venta DESC
        ");
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_saldo = 0;
        foreach ($filas as $f) { $total_saldo += (float)$f['saldo']; }

        echo json_encode([
            'success'     => true,
            'cuentas'     => $filas,
            'total_saldo' => round($total_saldo, 2)
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}