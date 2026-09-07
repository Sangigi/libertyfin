<?php
// ajustar_venta.php
//
// ⚠️ HERRAMIENTA TEMPORAL DE NORMALIZACIÓN ⚠️
//
// Sirve para corregir ventas capturadas ANTES de que existieran los
// anticipos: donde se registró el monto del anticipo (500) en lugar del
// total real de la venta (30,000).
//
// Cuando termines de normalizar el histórico, BORRA ESTE ARCHIVO y el
// bloque "Normalizar venta" de ventas_lista.php. No debe quedar en
// producción: permite reescribir el monto de una venta ya cobrada.
//
// Qué recalcula en cadena al cambiar el total (nada de esto es opcional,
// si se omite el reporte queda descuadrado):
//   1. venta_detalles      · precios escalados por el mismo factor
//   2. ventas              · subtotal, descuento, IVA y total
//   3. venta_comisiones    · monto_base y monto_comision con los precios nuevos
//   4. pago_comisiones     · se regeneran, porque cambió la proporción cobrada
//
// Los COSTOS no se escalan: el costo del producto fue el que fue. Si
// también quedó mal capturado, corrígelo en el producto y vuelve a
// asignar la comisión.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}
if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Solo un administrador puede normalizar una venta']);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    $accion = $_POST['accion'] ?? ($_GET['accion'] ?? '');

    // =====================================================================
    // Estado actual de la venta
    // =====================================================================
    if ($accion === 'obtener_venta') {
        $venta_id = intval($_GET['venta_id'] ?? 0);
        if ($venta_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_id inválido']);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT v.subtotal, v.descuento, v.iva, v.total,
                   COALESCE((SELECT SUM(p.monto) FROM venta_pagos p
                             WHERE p.venta_id = v.id AND p.cancelado = 0), 0) AS cobrado,
                   (SELECT COUNT(*) FROM venta_detalles vd WHERE vd.venta_id = v.id) AS n_productos,
                   (SELECT COUNT(*) FROM venta_comisiones vc
                    WHERE vc.venta_id = v.id AND vc.cancelada = 0) AS n_comisiones
            FROM ventas v WHERE v.id = ?
        ");
        $stmt->execute([$venta_id]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$v) {
            echo json_encode(['success' => false, 'message' => 'La venta no existe']);
            exit();
        }

        $base = (float)$v['subtotal'] - (float)$v['descuento'];
        echo json_encode([
            'success'      => true,
            'total'        => round((float)$v['total'], 2),
            'cobrado'      => round((float)$v['cobrado'], 2),
            'saldo'        => round((float)$v['total'] - (float)$v['cobrado'], 2),
            'iva_pct'      => $base > 0 ? round(((float)$v['iva'] / $base) * 100, 2) : 0,
            'n_productos'  => (int)$v['n_productos'],
            'n_comisiones' => (int)$v['n_comisiones']
        ]);
        exit();
    }

    // =====================================================================
    // Ajustar el total de la venta
    // =====================================================================
    if ($accion === 'ajustar_total') {
        $venta_id      = intval($_POST['venta_id'] ?? 0);
        $nuevo_total   = round(floatval($_POST['nuevo_total'] ?? 0), 2);
        $monto_cobrado = isset($_POST['monto_cobrado']) && $_POST['monto_cobrado'] !== ''
                       ? round(floatval($_POST['monto_cobrado']), 2) : null;
        $motivo        = trim($_POST['motivo'] ?? '');

        if ($venta_id <= 0 || $nuevo_total <= 0) {
            echo json_encode(['success' => false, 'message' => 'Captura un total mayor a 0']);
            exit();
        }
        if ($motivo === '') {
            echo json_encode(['success' => false, 'message' => 'Escribe el motivo del ajuste']);
            exit();
        }

        $stmt = $conn->prepare("SELECT subtotal, descuento, iva, total, estado FROM ventas WHERE id = ?");
        $stmt->execute([$venta_id]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            echo json_encode(['success' => false, 'message' => 'La venta no existe']);
            exit();
        }

        $total_viejo = round((float)$v['total'], 2);
        if ($total_viejo <= 0) {
            echo json_encode(['success' => false, 'message' => 'La venta tiene total 0, no se puede escalar']);
            exit();
        }
        if ($monto_cobrado !== null && $monto_cobrado > $nuevo_total + 0.005) {
            echo json_encode(['success' => false, 'message' => 'Lo cobrado no puede ser mayor al total']);
            exit();
        }

        // Factor de escala. Todo lo que sea "precio" se multiplica por él;
        // los costos NO.
        $f = $nuevo_total / $total_viejo;

        // Porcentaje de IVA vigente en la venta (el precio lo trae incluido)
        $base_vieja = (float)$v['subtotal'] - (float)$v['descuento'];
        $factor_iva = $base_vieja > 0 ? ($total_viejo / $base_vieja) : 1.0;
        if ($factor_iva <= 0) $factor_iva = 1.0;

        $conn->beginTransaction();
        try {
            // --- 1 · Detalles de la venta ---
            $conn->prepare("
                UPDATE venta_detalles
                SET precio_unitario = ROUND(precio_unitario * ?, 2),
                    subtotal        = ROUND(subtotal * ?, 2),
                    descuento       = ROUND(COALESCE(descuento, 0) * ?, 2),
                    total           = ROUND(total * ?, 2)
                WHERE venta_id = ?
            ")->execute([$f, $f, $f, $f, $venta_id]);

            // --- 2 · Cabecera de la venta ---
            $nueva_base      = round($nuevo_total / $factor_iva, 2);
            $nuevo_iva       = round($nuevo_total - $nueva_base, 2);
            $prop_desc       = ((float)$v['subtotal'] > 0)
                             ? ((float)$v['descuento'] / (float)$v['subtotal']) : 0;
            $nuevo_subtotal  = round($nueva_base / max(0.000001, (1 - $prop_desc)), 2);
            $nuevo_descuento = round($nuevo_subtotal - $nueva_base, 2);

            $conn->prepare("
                UPDATE ventas SET subtotal = ?, descuento = ?, iva = ?, total = ? WHERE id = ?
            ")->execute([$nuevo_subtotal, $nuevo_descuento, $nuevo_iva, $nuevo_total, $venta_id]);

            // --- 3 · Recalcular las comisiones asignadas ---
            // Los precios escalan; costo y gasto de operación se quedan.
            //   base = (precio x cant - descuento) - costo x cant - gasto
            $conn->prepare("
                UPDATE venta_comisiones
                SET precio_unitario = ROUND(precio_unitario * ?, 2),
                    descuento_linea = ROUND(descuento_linea * ?, 2)
                WHERE venta_id = ? AND cancelada = 0
            ")->execute([$f, $f, $venta_id]);

            $conn->prepare("
                UPDATE venta_comisiones
                SET monto_base = GREATEST(0, ROUND(
                        (precio_unitario * cantidad) - descuento_linea
                        - (costo_unitario * cantidad) - gasto_operacion, 2))
                WHERE venta_id = ? AND cancelada = 0
            ")->execute([$venta_id]);

            $conn->prepare("
                UPDATE venta_comisiones
                SET monto_comision = ROUND(monto_base * (porcentaje_regla / 100), 2)
                WHERE venta_id = ? AND cancelada = 0
            ")->execute([$venta_id]);

            // --- 4 · Ajustar lo cobrado, si se indicó ---
            if ($monto_cobrado !== null) {
                // Se reescribe el pago más antiguo con el monto correcto y se
                // cancelan los demás: es una normalización, no un abono.
                $st = $conn->prepare("
                    SELECT id FROM venta_pagos
                    WHERE venta_id = ? AND cancelado = 0 ORDER BY fecha_pago, id
                ");
                $st->execute([$venta_id]);
                $ids = $st->fetchAll(PDO::FETCH_COLUMN);

                if (empty($ids)) {
                    if ($monto_cobrado > 0) {
                        $conn->prepare("
                            INSERT INTO venta_pagos
                                (venta_id, tipo, monto, fecha_pago, metodo_pago, notas, usuario_id)
                            SELECT ?, ?, ?, DATE(fecha), metodo_pago, 'Normalización de venta histórica', ?
                            FROM ventas WHERE id = ?
                        ")->execute([
                            $venta_id,
                            ($monto_cobrado < $nuevo_total - 0.005) ? 'anticipo' : 'liquidacion',
                            $monto_cobrado, $_SESSION['usuario_id'] ?? null, $venta_id
                        ]);
                    }
                } else {
                    $primero = array_shift($ids);
                    $conn->prepare("
                        UPDATE venta_pagos SET monto = ?, tipo = ?,
                            notas = CONCAT(COALESCE(notas,''), ' · Normalizado')
                        WHERE id = ?
                    ")->execute([
                        $monto_cobrado,
                        ($monto_cobrado < $nuevo_total - 0.005) ? 'anticipo' : 'liquidacion',
                        $primero
                    ]);
                    foreach ($ids as $otro) {
                        $conn->prepare("
                            UPDATE venta_pagos
                            SET cancelado = 1, cancelado_por = ?, fecha_cancelacion = NOW(),
                                motivo_cancelacion = 'Normalización de venta histórica'
                            WHERE id = ?
                        ")->execute([$_SESSION['usuario_id'] ?? null, $otro]);
                    }
                }
            }

            // --- 5 · Regenerar las comisiones por pago ---
            // La proporción cobrada cambió, así que las que había ya no valen.
            $conn->prepare("DELETE FROM pago_comisiones WHERE venta_id = ?")->execute([$venta_id]);

            $st = $conn->prepare("
                SELECT id, monto, fecha_pago FROM venta_pagos
                WHERE venta_id = ? AND cancelado = 0
            ");
            $st->execute([$venta_id]);
            $pagos = $st->fetchAll(PDO::FETCH_ASSOC);

            $st_c = $conn->prepare("
                SELECT id, colaborador_id, colaborador_nombre, area_nombre,
                       porcentaje_regla, monto_comision
                FROM venta_comisiones WHERE venta_id = ? AND cancelada = 0
            ");
            $st_c->execute([$venta_id]);
            $asig = $st_c->fetchAll(PDO::FETCH_ASSOC);

            $st_i = $conn->prepare("
                INSERT INTO pago_comisiones
                    (pago_id, venta_comision_id, venta_id, colaborador_id, colaborador_nombre,
                     area_nombre, porcentaje, proporcion_cobrada, monto, fecha_pago)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $regeneradas = 0;
            foreach ($pagos as $pg) {
                $prop = (float)$pg['monto'] / $nuevo_total;
                foreach ($asig as $a) {
                    $m = round((float)$a['monto_comision'] * $prop, 2);
                    if ($m <= 0) continue;
                    $st_i->execute([
                        $pg['id'], $a['id'], $venta_id, $a['colaborador_id'], $a['colaborador_nombre'],
                        $a['area_nombre'], $a['porcentaje_regla'], round($prop, 6), $m, $pg['fecha_pago']
                    ]);
                    $regeneradas++;
                }
            }

            // --- 6 · Dejar rastro ---
            $conn->prepare("
                UPDATE ventas
                SET descripcion = TRIM(CONCAT(COALESCE(descripcion, ''), ' [Total normalizado de ',
                                    ?, ' a ', ?, ': ', ?, ']'))
                WHERE id = ?
            ")->execute([
                number_format($total_viejo, 2), number_format($nuevo_total, 2),
                mb_substr($motivo, 0, 150), $venta_id
            ]);

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        $cobrado_final = $monto_cobrado !== null ? $monto_cobrado : null;
        if ($cobrado_final === null) {
            $st = $conn->prepare("
                SELECT COALESCE(SUM(monto), 0) FROM venta_pagos
                WHERE venta_id = ? AND cancelado = 0
            ");
            $st->execute([$venta_id]);
            $cobrado_final = round((float)$st->fetchColumn(), 2);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Venta normalizada: total de $' . number_format($total_viejo, 2)
                       . ' a $' . number_format($nuevo_total, 2) . '. '
                       . $regeneradas . ' comisión(es) por pago regenerada(s).',
            'total'   => $nuevo_total,
            'cobrado' => $cobrado_final,
            'saldo'   => round($nuevo_total - $cobrado_final, 2)
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}