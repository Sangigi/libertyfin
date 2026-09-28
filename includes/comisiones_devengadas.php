<?php
// includes/comisiones_devengadas.php
//
// Una sola regla para toda la aplicación: cuánta comisión se ha DEVENGADO
// de una venta según el dinero que realmente entró.
//
//   cobrado de la línea = (precio x cant - descuento) x % cobrado
//   base                = cobrado de la línea - costo x cant - gasto
//   devengado           = base x % de la comisión
//
// El gasto de operación se descuenta COMPLETO: se desembolsó una sola vez,
// no se va pagando en abonos.

if (!function_exists('comision_devengada_linea')) {
    function comision_devengada_linea(array $c, float $prop_cobrado): float {
        $cantidad = (float)($c['cantidad'] ?? 0);
        $costo    = (float)($c['costo_unitario'] ?? 0) * $cantidad;
        $gasto    = (float)($c['gasto_operacion'] ?? 0);

        $venta_linea = ((float)($c['precio_unitario'] ?? 0) * $cantidad)
                     - (float)($c['descuento_linea'] ?? 0);

        // Hay ventas viejas donde el precio unitario quedó en 0 aunque la
        // línea sí tenía importe. En esos casos se reconstruye desde
        // monto_base, que es lo que se guardó al asignar la comisión:
        //   monto_base = venta de la línea - costo - gasto
        if ($venta_linea <= 0 && isset($c['monto_base'])) {
            $venta_linea = (float)$c['monto_base'] + $costo + $gasto;
        }

        $base = ($venta_linea * $prop_cobrado) - $costo - $gasto;
        if ($base < 0) $base = 0.0;

        return round($base * ((float)($c['porcentaje_regla'] ?? 0) / 100), 2);
    }
}

if (!function_exists('sincronizarComisionesDeVenta')) {
    /**
     * Deja `pago_comisiones` al día con lo que hoy debería estar devengado.
     *
     * Hace falta porque la comisión se suele asignar DESPUÉS de haber
     * cobrado (se vende en caja y luego se reparte). Sin esto, esas
     * comisiones nunca aparecerían en los reportes, que leen `pago_comisiones`.
     *
     * También corrige a la baja: si se cancela una comisión o se ajusta el
     * total de la venta, lo generado de más se elimina.
     */
    function sincronizarComisionesDeVenta($conn, $venta_id) {
        $venta_id = (int)$venta_id;
        if ($venta_id <= 0) return 0;

        $st = $conn->prepare("SELECT total FROM ventas WHERE id = ?");
        $st->execute([$venta_id]);
        $total = (float)$st->fetchColumn();
        if ($total <= 0) return 0;

        // Pagos vivos de la venta. Lo que falte por generar se cuelga del
        // último pago recibido: es el que "liberó" ese dinero.
        $st = $conn->prepare("
            SELECT id, fecha_pago FROM venta_pagos
            WHERE venta_id = ? AND cancelado = 0
            ORDER BY fecha_pago, id
        ");
        $st->execute([$venta_id]);
        $pagos = $st->fetchAll(PDO::FETCH_ASSOC);
        if (empty($pagos)) {
            // Sin pagos no hay nada devengado: se limpia lo que hubiera.
            $conn->prepare("DELETE FROM pago_comisiones WHERE venta_id = ?")->execute([$venta_id]);
            return 0;
        }
        $ultimo = end($pagos);

        $st = $conn->prepare("
            SELECT COALESCE(SUM(monto), 0) FROM venta_pagos
            WHERE venta_id = ? AND cancelado = 0
        ");
        $st->execute([$venta_id]);
        $cobrado = (float)$st->fetchColumn();
        $prop = min(1.0, $cobrado / $total);

        // Comisiones canceladas: fuera de los reportes.
        $conn->prepare("
            DELETE pc FROM pago_comisiones pc
            INNER JOIN venta_comisiones vc ON vc.id = pc.venta_comision_id
            WHERE pc.venta_id = ? AND vc.cancelada = 1
        ")->execute([$venta_id]);

        $st = $conn->prepare("
            SELECT id, colaborador_id, colaborador_nombre, area_nombre,
                   porcentaje_regla, monto_comision, monto_base,
                   cantidad, precio_unitario, descuento_linea,
                   costo_unitario, gasto_operacion
            FROM venta_comisiones
            WHERE venta_id = ? AND cancelada = 0
        ");
        $st->execute([$venta_id]);
        $asignaciones = $st->fetchAll(PDO::FETCH_ASSOC);

        $st_prev = $conn->prepare("
            SELECT COALESCE(SUM(monto), 0) FROM pago_comisiones WHERE venta_comision_id = ?
        ");
        $st_del = $conn->prepare("DELETE FROM pago_comisiones WHERE venta_comision_id = ?");
        $st_ins = $conn->prepare("
            INSERT INTO pago_comisiones
                (pago_id, venta_comision_id, venta_id, colaborador_id, colaborador_nombre,
                 area_nombre, porcentaje, proporcion_cobrada, monto, fecha_pago)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $tocadas = 0;
        foreach ($asignaciones as $a) {
            $devengado = comision_devengada_linea($a, $prop);

            $st_prev->execute([$a['id']]);
            $ya = round((float)$st_prev->fetchColumn(), 2);
            $dif = round($devengado - $ya, 2);

            if (abs($dif) < 0.005) continue;

            if ($dif < 0) {
                // Se generó de más (comisión bajada, venta reajustada, pago
                // cancelado): se rehace limpio en un solo renglón.
                $st_del->execute([$a['id']]);
                $ya = 0.0;
                $dif = $devengado;
                if ($dif <= 0) { $tocadas++; continue; }
            }

            $st_ins->execute([
                $ultimo['id'], $a['id'], $venta_id, $a['colaborador_id'], $a['colaborador_nombre'],
                $a['area_nombre'], $a['porcentaje_regla'], round($prop, 6),
                round($dif, 2), $ultimo['fecha_pago']
            ]);
            $tocadas++;
        }

        return $tocadas;
    }
}