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

        $colaboradores = $conn->query("
            SELECT id, nombre, area_id FROM comision_colaboradores WHERE activo = 1 ORDER BY nombre
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'areas' => $areas,
            'colaboradores' => $colaboradores,
            // Llaves vacias por compatibilidad: si algun front todavia no
            // actualizado hace .forEach sobre ellas, no truena. Ya no se usan
            // los conceptos/roles ni los "porcentajes de reparto".
            'reglas' => [],
            'porcentajes' => []
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

        // Solo las activas: las canceladas se conservan en la tabla para
        // auditoría, pero no se muestran ni suman.
        $stmt = $conn->prepare("
            SELECT * FROM venta_comisiones
            WHERE venta_detalle_id = ? AND cancelada = 0
            ORDER BY id
        ");
        $stmt->execute([$venta_detalle_id]);
        $comisiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'comisiones' => $comisiones,
            // El front usa esto para mostrar u ocultar el botón de cancelar.
            'es_admin'   => (($_SESSION['usuario_rol'] ?? '') === 'admin')
        ]);
        exit();
    }

    // =====================================================================
    // Listar TODAS las comisiones activas de una venta (todos sus productos)
    // Se usa en la sección "Comisiones" del detalle de la venta.
    // =====================================================================
    if ($accion === 'listar_comisiones_venta') {
        $venta_id = intval($_GET['venta_id'] ?? $_POST['venta_id'] ?? 0);
        if ($venta_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'venta_id inválido']);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT vc.*, p.nombre AS producto_nombre
            FROM venta_comisiones vc
            LEFT JOIN venta_detalles vd ON vd.id = vc.venta_detalle_id
            LEFT JOIN productos p       ON p.id  = vd.producto_id
            WHERE vc.venta_id = ? AND vc.cancelada = 0
            ORDER BY p.nombre, vc.area_nombre, vc.id
        ");
        $stmt->execute([$venta_id]);
        $comisiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'comisiones' => $comisiones,
            'es_admin'   => (($_SESSION['usuario_rol'] ?? '') === 'admin')
        ]);
        exit();
    }

    // =====================================================================
    // Guardar (agregar) una comisión a un producto de una venta
    // =====================================================================
    if ($accion === 'guardar_comision') {
        $venta_id = intval($_POST['venta_id'] ?? 0);
        $venta_detalle_id = intval($_POST['venta_detalle_id'] ?? 0);
        $area_id = intval($_POST['area_id'] ?? 0);
        $colaborador_id = intval($_POST['colaborador_id'] ?? 0);

        // El porcentaje YA NO viene de comision_reglas: lo captura la persona
        // al momento, porque cambia de un caso a otro.
        $porcentaje = floatval($_POST['porcentaje'] ?? 0);

        if ($venta_id <= 0 || $venta_detalle_id <= 0 || $area_id <= 0 || $colaborador_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Faltan datos requeridos (venta, producto, área o colaborador)']);
            exit();
        }
        if ($porcentaje <= 0 || $porcentaje > 100) {
            echo json_encode(['success' => false, 'message' => 'Captura un porcentaje entre 0.01 y 100']);
            exit();
        }

        // Traer el detalle de la venta (precio, cantidad) y el costo actual del producto
        $stmt_detalle = $conn->prepare("
            SELECT vd.cantidad, vd.precio_unitario, vd.descuento, vd.producto_id, p.costo
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

        // Los precios de venta_detalles traen el IVA incluido. Se deriva el
        // factor desde la venta para comisionar sobre la base y no sobre el
        // impuesto:   factor = total / (subtotal - descuento)
        $stmt_iva = $conn->prepare("SELECT subtotal, descuento, total FROM ventas WHERE id = ?");
        $stmt_iva->execute([$venta_id]);
        $vta_tot    = $stmt_iva->fetch(PDO::FETCH_ASSOC);
        $base_venta = (float)($vta_tot['subtotal'] ?? 0) - (float)($vta_tot['descuento'] ?? 0);
        $factor_iva = ($base_venta > 0) ? ((float)$vta_tot['total'] / $base_venta) : 1.0;
        if ($factor_iva <= 0) $factor_iva = 1.0;

        $cantidad        = floatval($detalle['cantidad']);
        $precio_unitario = floatval($detalle['precio_unitario']) / $factor_iva;
        $costo_unitario  = floatval($detalle['costo'] ?? 0);

        // El descuento otorgado al cliente SÍ reduce la base: se comisiona
        // sobre lo realmente cobrado, no sobre el precio de lista.
        $descuento_linea = floatval($detalle['descuento'] ?? 0) / $factor_iva;

        $venta_linea    = $precio_unitario * $cantidad;
        $utilidad_linea = ($venta_linea - $descuento_linea) - ($costo_unitario * $cantidad);

        // -----------------------------------------------------------------
        // GASTO DE OPERACIÓN
        // Se resta de la utilidad ANTES de calcular la comisión.
        //
        // OJO: solo cuentan los gastos capturados A MANO y ligados a esta
        // venta. Los gastos automáticos (tipo='automatico') los genera el
        // sistema al cerrar la venta y representan el costo de mercancía, que
        // YA está restado arriba vía $costo_unitario. Incluirlos aquí
        // restaría el costo dos veces.
        //
        // El discriminador correcto es `tipo`, NO `origen`: los gastos
        // manuales ligados a una venta también se guardan con origen='venta'.
        // -----------------------------------------------------------------
        $stmt_gasto = $conn->prepare("
            SELECT COALESCE(SUM(monto), 0)
            FROM gastos
            WHERE venta_id = ?
              AND tipo      = 'manual'
              AND categoria <> 'Costo de venta'
        ");
        $stmt_gasto->execute([$venta_id]);
        $gasto_total_venta = floatval($stmt_gasto->fetchColumn());

        // El gasto está registrado a nivel VENTA, pero la comisión se calcula
        // por línea de producto. Se prorratea en proporción a la utilidad que
        // aporta cada línea: si la venta trae un solo producto, le toca el
        // 100%; si trae varios, cada uno absorbe su parte proporcional.
        $gasto_operacion = 0.0;
        if ($gasto_total_venta > 0) {
            $stmt_util = $conn->prepare("
                SELECT COALESCE(SUM(GREATEST(0,
                           ((vd.precio_unitario * vd.cantidad) - COALESCE(vd.descuento, 0)) / ?
                           - (p.costo * vd.cantidad))), 0)
                FROM venta_detalles vd
                INNER JOIN productos p ON vd.producto_id = p.id
                WHERE vd.venta_id = ?
            ");
            $stmt_util->execute([$factor_iva, $venta_id]);
            $utilidad_total_venta = floatval($stmt_util->fetchColumn());

            if ($utilidad_total_venta > 0) {
                $proporcion = max(0, $utilidad_linea) / $utilidad_total_venta;
                $gasto_operacion = $gasto_total_venta * $proporcion;
            } else {
                // Sin utilidad en toda la venta: se reparte en partes iguales
                // entre las líneas para no cargarle todo a una sola.
                $stmt_n = $conn->prepare("SELECT COUNT(*) FROM venta_detalles WHERE venta_id = ?");
                $stmt_n->execute([$venta_id]);
                $n_lineas = max(1, intval($stmt_n->fetchColumn()));
                $gasto_operacion = $gasto_total_venta / $n_lineas;
            }
        }
        $gasto_operacion = round($gasto_operacion, 2);

        // Base comisionable = utilidad - gasto de operación. Nunca negativa:
        // si el gasto se come la utilidad, la comisión es 0, no un cargo.
        $monto_base = max(0, $utilidad_linea - $gasto_operacion);

        // Snapshot del área. Ya no hay concepto/rol: la comisión es
        // área + colaborador + porcentaje.
        $stmt_area = $conn->prepare("SELECT nombre FROM comision_areas WHERE id = ?");
        $stmt_area->execute([$area_id]);
        $area_nombre = $stmt_area->fetchColumn();

        if (!$area_nombre) {
            echo json_encode(['success' => false, 'message' => 'El área seleccionada no existe']);
            exit();
        }

        // `venta_comisiones.concepto` es NOT NULL: se guarda el nombre del
        // área como etiqueta, y `regla_id` queda en NULL.
        $regla_id = null;
        $concepto = $area_nombre;

        $stmt_colab = $conn->prepare("SELECT nombre FROM comision_colaboradores WHERE id = ?");
        $stmt_colab->execute([$colaborador_id]);
        $colaborador_nombre = $stmt_colab->fetchColumn();

        if (!$colaborador_nombre) {
            echo json_encode(['success' => false, 'message' => 'El colaborador seleccionado no existe']);
            exit();
        }

        // Un solo porcentaje, el capturado. `porcentaje_reparto` se queda en
        // 100 fijo: existia para dividir un concepto entre varias personas y
        // provocaba el error de multiplicar dos veces (41% x 41%).
        $porcentaje_regla = $porcentaje;
        $porcentaje_reparto = 100.00;
        $monto_comision = round($monto_base * ($porcentaje_regla / 100), 2);

        $stmt_ins = $conn->prepare("
            INSERT INTO venta_comisiones
                (venta_id, venta_detalle_id, area_id, area_nombre, regla_id, concepto,
                 colaborador_id, colaborador_nombre, porcentaje_regla, porcentaje_reparto,
                 costo_unitario, gasto_operacion, precio_unitario, descuento_linea,
                 cantidad, monto_base, monto_comision, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_ins->execute([
            $venta_id, $venta_detalle_id, $area_id, $area_nombre, $regla_id, $concepto,
            $colaborador_id, $colaborador_nombre, $porcentaje_regla, $porcentaje_reparto,
            $costo_unitario, $gasto_operacion, round($precio_unitario, 2),
            round($descuento_linea, 2),
            $cantidad, $monto_base, $monto_comision, $_SESSION['usuario_id'] ?? null
        ]);

        // Aviso (no bloquea): la suma de los porcentajes asignados a este
        // producto no deberia pasar de 100%, o se reparte mas de la utilidad.
        $aviso = null;
        $stmt_sum = $conn->prepare("
            SELECT COALESCE(SUM(porcentaje_regla), 0), COALESCE(SUM(monto_comision), 0)
            FROM venta_comisiones
            WHERE venta_detalle_id = ? AND cancelada = 0
        ");
        $stmt_sum->execute([$venta_detalle_id]);
        list($pct_acumulado, $repartido) = $stmt_sum->fetch(PDO::FETCH_NUM);
        $pct_acumulado = floatval($pct_acumulado);
        if ($pct_acumulado > 100.01) {
            $aviso = "Atención: los porcentajes asignados a este producto suman "
                   . number_format($pct_acumulado, 2) . "%. Se está repartiendo $"
                   . number_format(floatval($repartido), 2) . " de una base de $"
                   . number_format($monto_base, 2) . ".";
        }

        echo json_encode([
            'success' => true,
            'message' => "Comisión asignada: {$colaborador_nombre} ({$area_nombre}) - $" . number_format($monto_comision, 2),
            'aviso' => $aviso,
            'desglose' => [
                'precio_unitario'  => round($precio_unitario, 2),
                'costo_unitario'   => round($costo_unitario, 2),
                'descuento_linea'  => round($descuento_linea, 2),
                'cantidad'         => $cantidad,
                'utilidad_linea'   => round($utilidad_linea, 2),
                'gasto_operacion'  => $gasto_operacion,
                'monto_base'       => round($monto_base, 2),
                'porcentaje'       => $porcentaje_regla,
                'pct_acumulado'    => round($pct_acumulado, 2)
            ],
            'monto_base' => $monto_base,
            'monto_comision' => $monto_comision
        ]);
        exit();
    }

    // =====================================================================
    // Cancelar la comisión de una persona (cancelación LÓGICA)
    //
    // No se borra la fila: se marca cancelada = 1 y se guarda quién y
    // cuándo. Una comisión es un registro de dinero; si se borra sin
    // rastro, un reporte que no cuadre es imposible de explicar.
    //
    // Solo admin. La validación va aquí, en el servidor: esconder el
    // botón en el front no protege nada.
    // =====================================================================
    if ($accion === 'cancelar_comision' || $accion === 'eliminar_comision') {
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Solo un administrador puede cancelar comisiones']);
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

        // Traer el detalle antes de cancelar, para el mensaje y el log
        $stmt_c = $conn->prepare("
            SELECT colaborador_nombre, area_nombre, monto_comision, cancelada
            FROM venta_comisiones WHERE id = ?
        ");
        $stmt_c->execute([$id]);
        $com = $stmt_c->fetch(PDO::FETCH_ASSOC);

        if (!$com) {
            echo json_encode(['success' => false, 'message' => 'La comisión no existe']);
            exit();
        }
        if ((int)$com['cancelada'] === 1) {
            echo json_encode(['success' => false, 'message' => 'Esa comisión ya estaba cancelada']);
            exit();
        }

        $stmt = $conn->prepare("
            UPDATE venta_comisiones
            SET cancelada = 1,
                cancelada_por = ?,
                fecha_cancelacion = NOW(),
                motivo_cancelacion = ?
            WHERE id = ? AND cancelada = 0
        ");
        $stmt->execute([$_SESSION['usuario_id'] ?? null, mb_substr($motivo, 0, 255), $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Comisión cancelada: ' . $com['colaborador_nombre']
                       . ' (' . $com['area_nombre'] . ') - $'
                       . number_format((float)$com['monto_comision'], 2)
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}