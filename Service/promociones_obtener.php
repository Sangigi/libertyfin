<?php
/**
 * Service/promociones_obtener.php
 *
 * Archivo HÍBRIDO:
 *   - Incluido desde PHP (require_once desde caja.php) → solo define el motor.
 *   - Llamado por HTTP (fetch desde JS)               → devuelve JSON.
 *
 * NO declara env() ni EnvLoader.
 *
 * Consulta:
 *   - promocion_productos    → productos específicos
 *   - promociones_aplicables → categorías y marcas
 *   - promociones_combo      → combos
 *   - promociones_sucursales → sucursales
 *
 * IMPORTANTE: las columnas opcionales (dias_semana, metodo_pago, tipo_cliente,
 * hora_inicio, hora_fin) pueden estar como NULL o como string vacío ''.
 * El motor trata ambos casos como "sin restricción".
 */

// =====================================================================
// CARGA DE DEPENDENCIAS
// =====================================================================
$__root = dirname(__DIR__);

if (!function_exists('env')) {
    require_once $__root . '/env_loader.php';
}
if (!function_exists('config')) {
    require_once $__root . '/config.php';
}
if (!function_exists('getEmpresaDBConnection')) {
    require_once $__root . '/config/database.php';
}

unset($__root);

// =====================================================================
// MOTOR DE PROMOCIONES
// =====================================================================

if (!function_exists('obtenerPromocionesVigentes')) {

    function obtenerPromocionesVigentes(PDO $conn, int $sucursal_id, array $contexto = []): array
    {
        $ahora      = date('Y-m-d H:i:s');
        $hora       = date('H:i:s');
        $dia_semana = (int)date('N'); // 1=lunes ... 7=domingo

        $metodo_pago  = $contexto['metodo_pago']  ?? null;
        $tipo_cliente = $contexto['tipo_cliente'] ?? null;

        // Normalizar: '' → null
        if ($metodo_pago === '')  $metodo_pago  = null;
        if ($tipo_cliente === '') $tipo_cliente = null;

        $sql = "
            SELECT p.*
            FROM promociones p
            WHERE p.activo = 1
              AND ? BETWEEN p.fecha_inicio AND p.fecha_fin
              AND (
                    p.todas_sucursales = 1
                    OR EXISTS (
                        SELECT 1 FROM promociones_sucursales ps
                        WHERE ps.promocion_id = p.id AND ps.sucursal_id = ?
                    )
              )
              AND (
                    p.hora_inicio IS NULL OR p.hora_inicio = ''
                    OR p.hora_fin    IS NULL OR p.hora_fin    = ''
                    OR (
                        CASE
                            WHEN p.hora_inicio <= p.hora_fin
                                THEN ? BETWEEN p.hora_inicio AND p.hora_fin
                            ELSE
                                (? >= p.hora_inicio OR ? <= p.hora_fin)
                        END
                    )
              )
              AND (
                    p.dias_semana IS NULL OR p.dias_semana = ''
                    OR FIND_IN_SET(?, p.dias_semana) > 0
              )
              AND (
                    p.metodo_pago IS NULL OR p.metodo_pago = ''
                    OR ? IS NULL
                    OR p.metodo_pago = ?
              )
              AND (
                    p.tipo_cliente IS NULL OR p.tipo_cliente = ''
                    OR ? IS NULL
                    OR p.tipo_cliente = ?
              )
            ORDER BY p.prioridad ASC, p.id ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $ahora,
            $sucursal_id,
            $hora, $hora, $hora,
            $dia_semana,
            $metodo_pago, $metodo_pago,
            $tipo_cliente, $tipo_cliente,
        ]);

        $promos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($promos)) return [];

        $ids = array_column($promos, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        // ---- 1) Aplicables (categorías y marcas) ----
        $stmtA = $conn->prepare("
            SELECT promocion_id, tipo, referencia_id, referencia_nombre
            FROM promociones_aplicables
            WHERE promocion_id IN ($ph)
        ");
        $stmtA->execute($ids);
        $aplicables = [];
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $aplicables[$a['promocion_id']][] = $a;
        }

        // ---- 2) Productos específicos (tabla dedicada) ----
        $stmtP = $conn->prepare("
            SELECT promocion_id, producto_id
            FROM promocion_productos
            WHERE promocion_id IN ($ph)
        ");
        $stmtP->execute($ids);
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $pp) {
            $aplicables[$pp['promocion_id']][] = [
                'promocion_id'      => $pp['promocion_id'],
                'tipo'              => 'producto',
                'referencia_id'     => (int)$pp['producto_id'],
                'referencia_nombre' => null,
            ];
        }

        // ---- 3) Combos ----
        $stmtC = $conn->prepare("
            SELECT promocion_id, producto_id, cantidad
            FROM promociones_combo
            WHERE promocion_id IN ($ph)
        ");
        $stmtC->execute($ids);
        $combos = [];
        foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $combos[$c['promocion_id']][] = $c;
        }

        // ---- 4) Fusionar ----
        foreach ($promos as &$p) {
            $p['aplicables'] = $aplicables[$p['id']] ?? [];
            $p['combo']      = $combos[$p['id']]      ?? [];
        }
        unset($p);

        return $promos;
    }
}

if (!function_exists('promocionAplicaAProducto')) {

    function promocionAplicaAProducto(array $promo, array $item): bool
    {
        $aplica_a = $promo['aplica_a'];

        if ($aplica_a === 'venta_completa') return true;

        if ($aplica_a === 'producto') {
            foreach ($promo['aplicables'] as $a) {
                if ($a['tipo'] === 'producto' && (int)$a['referencia_id'] === (int)$item['id']) {
                    return true;
                }
            }
            return false;
        }

        if ($aplica_a === 'categoria') {
            $cat_id = (int)($item['categoria_id'] ?? 0);
            if ($cat_id <= 0) return false;
            foreach ($promo['aplicables'] as $a) {
                if ($a['tipo'] === 'categoria' && (int)$a['referencia_id'] === $cat_id) {
                    return true;
                }
            }
            return false;
        }

        if ($aplica_a === 'marca') {
            $marca = trim((string)($item['marca'] ?? ''));
            if ($marca === '') return false;
            foreach ($promo['aplicables'] as $a) {
                if ($a['tipo'] === 'marca'
                    && strcasecmp(trim($a['referencia_nombre']), $marca) === 0) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }
}

if (!function_exists('calcularDescuentoPromocion')) {

    function calcularDescuentoPromocion(array $promo, array $item): ?array
    {
        $cantidad   = (float)$item['cantidad'];
        $precio     = (float)$item['precio'];
        $subtotal   = $precio * $cantidad;
        if ($subtotal <= 0) return null;

        $tipo = $promo['tipo_promocion'];

        switch ($tipo) {

            case 'descuento_porcentual':
                $pct = (float)$promo['valor_descuento'];
                if ($pct <= 0 || $pct > 100) return null;
                $desc = round($subtotal * ($pct / 100), 2);
                return [
                    'descuento'    => $desc,
                    'precio_final' => round($subtotal - $desc, 2),
                    'detalle'      => "{$pct}% desc.",
                ];

            case 'descuento_fijo':
                $monto = (float)$promo['valor_descuento'];
                if ($monto <= 0) return null;
                if ($promo['aplica_a'] === 'venta_completa') return null;
                $desc = round(min($monto * $cantidad, $subtotal), 2);
                return [
                    'descuento'    => $desc,
                    'precio_final' => round($subtotal - $desc, 2),
                    'detalle'      => "\${$monto} desc.",
                ];

            case 'precio_especial':
                $nuevo = (float)$promo['precio_especial'];
                if ($nuevo <= 0 || $nuevo >= $precio) return null;
                $nuevo_subtotal = round($nuevo * $cantidad, 2);
                $desc = round($subtotal - $nuevo_subtotal, 2);
                return [
                    'descuento'    => $desc,
                    'precio_final' => $nuevo_subtotal,
                    'detalle'      => "Precio esp. \${$nuevo}",
                ];

            case 'llevalo_paga':
                $lleva = (int)$promo['cantidad_lleva'];
                $paga  = (int)$promo['cantidad_paga'];
                if ($lleva <= 0 || $paga <= 0 || $paga >= $lleva) return null;
                $grupos   = floor($cantidad / $lleva);
                $resto    = $cantidad - ($grupos * $lleva);
                $unidades_cobradas = ($grupos * $paga) + $resto;
                $nuevo_subtotal = round($unidades_cobradas * $precio, 2);
                $desc = round($subtotal - $nuevo_subtotal, 2);
                if ($desc <= 0) return null;
                return [
                    'descuento'    => $desc,
                    'precio_final' => $nuevo_subtotal,
                    'detalle'      => "{$lleva}x{$paga}",
                ];

            case 'precio_volumen':
                $min   = (int)$promo['cantidad_minima_volumen'];
                $nuevo = (float)$promo['precio_volumen'];
                if ($min <= 0 || $nuevo <= 0 || $cantidad < $min || $nuevo >= $precio) return null;
                $nuevo_subtotal = round($nuevo * $cantidad, 2);
                $desc = round($subtotal - $nuevo_subtotal, 2);
                return [
                    'descuento'    => $desc,
                    'precio_final' => $nuevo_subtotal,
                    'detalle'      => "Mayoreo \${$nuevo}",
                ];

            case 'combo':
                return null;
        }

        return null;
    }
}

if (!function_exists('aplicarPromocionesAVenta')) {

    function aplicarPromocionesAVenta(PDO $conn, array $carrito, int $sucursal_id, array $contexto = []): array
    {
        $resultado = [
            'carrito'             => $carrito,
            'descuento_por_linea' => [],
            'descuento_combo'     => 0.0,
            'descuento_venta'     => 0.0,
            'promos_aplicadas'    => [],
        ];

        if (empty($carrito)) return $resultado;

        $promos = obtenerPromocionesVigentes($conn, $sucursal_id, $contexto);
        if (empty($promos)) return $resultado;

        $promos_linea = array_filter($promos, fn($p) => $p['tipo_promocion'] !== 'combo');
        $promos_combo = array_filter($promos, fn($p) => $p['tipo_promocion'] === 'combo');

        // ---------- 1) Promociones por línea ----------
        foreach ($carrito as $idx => $item) {

            $mejor = null;

            foreach ($promos_linea as $promo) {

                if (!promocionAplicaAProducto($promo, $item)) continue;

                $calc = calcularDescuentoPromocion($promo, $item);
                if (!$calc || $calc['descuento'] <= 0) continue;

                if ($mejor !== null) {
                    $ya_acumulable = (int)$mejor['promo']['acumulable'] === 1;
                    $nueva_acum    = (int)$promo['acumulable'] === 1;

                    if (!$ya_acumulable && !$nueva_acum) {
                        if ($calc['descuento'] > $mejor['calc']['descuento']) {
                            $mejor = ['promo' => $promo, 'calc' => $calc];
                        }
                        continue;
                    }
                    if (!$ya_acumulable && $nueva_acum) {
                        continue;
                    }
                    if ($ya_acumulable && !$nueva_acum) {
                        $mejor = ['promo' => $promo, 'calc' => $calc];
                        continue;
                    }
                    $mejor['calc']['descuento']    += $calc['descuento'];
                    $mejor['calc']['precio_final']  = max(0, $mejor['calc']['precio_final'] - $calc['descuento']);
                    continue;
                }

                $mejor = ['promo' => $promo, 'calc' => $calc];
            }

            if ($mejor !== null) {
                $desc = round($mejor['calc']['descuento'], 2);
                $resultado['carrito'][$idx]['promocion'] = [
                    'id'     => (int)$mejor['promo']['id'],
                    'nombre' => $mejor['promo']['nombre'],
                    'tipo'   => $mejor['promo']['tipo_promocion'],
                    'detalle'=> $mejor['calc']['detalle'],
                ];
                $resultado['carrito'][$idx]['descuento_promo'] = $desc;

                $resultado['descuento_por_linea'][$idx] = $desc;
                $resultado['descuento_venta'] += $desc;

                $pid = (int)$mejor['promo']['id'];
                if (!isset($resultado['promos_aplicadas'][$pid])) {
                    $resultado['promos_aplicadas'][$pid] = [
                        'nombre' => $mejor['promo']['nombre'],
                        'monto'  => 0.0,
                    ];
                }
                $resultado['promos_aplicadas'][$pid]['monto'] += $desc;
            }
        }

        // ---------- 2) Promociones combo (CANTIDADES EXACTAS) ----------
        foreach ($promos_combo as $promo) {

            if (empty($promo['combo'])) continue;

            // Mapa: producto_id => cantidad requerida por el combo
            $requeridos = [];
            foreach ($promo['combo'] as $req) {
                $pid_req  = (int)$req['producto_id'];
                $cant_req = (float)$req['cantidad'];
                if ($pid_req <= 0 || $cant_req <= 0) continue;
                if (!isset($requeridos[$pid_req])) $requeridos[$pid_req] = 0;
                $requeridos[$pid_req] += $cant_req;
            }
            if (empty($requeridos)) continue;

            // Mapa: producto_id => cantidad total en el carrito
            $en_carrito = [];
            foreach ($carrito as $i => $item) {
                $pid = (int)$item['id'];
                $en_carrito[$pid] = ($en_carrito[$pid] ?? 0) + (float)$item['cantidad'];
            }

            // ✅ Validar coincidencia EXACTA producto por producto
            $cumple = true;
            foreach ($requeridos as $pid_req => $cant_req) {
                $cant_tiene = $en_carrito[$pid_req] ?? 0;
                if (abs($cant_tiene - $cant_req) > 0.0001) {
                    $cumple = false;
                    break;
                }
            }
            if (!$cumple) continue;

            // ⚠️ OPCIONAL: rechazar el combo si el carrito tiene productos
            // que NO pertenecen al combo. Descomenta las siguientes 4 líneas
            // si quieres que el combo SOLO aplique cuando el carrito sea
            // exactamente el combo (sin productos extra):
            /*
            foreach ($en_carrito as $pid => $cant) {
                if (!isset($requeridos[$pid])) { $cumple = false; break; }
            }
            if (!$cumple) continue;
            */

            // Calcular precio normal del combo (precio_unitario × cantidad_requerida)
            $precio_normal_combo = 0.0;
            $indices_usados = [];
            foreach ($requeridos as $pid_req => $cant_req) {
                foreach ($carrito as $i => $item) {
                    if ((int)$item['id'] === $pid_req) {
                        $precio_normal_combo += (float)$item['precio'] * $cant_req;
                        $indices_usados[] = $i;
                        break;
                    }
                }
            }

            $precio_combo = (float)$promo['precio_especial'];
            if ($precio_combo <= 0 || $precio_combo >= $precio_normal_combo) continue;

            $descuento_combo = round($precio_normal_combo - $precio_combo, 2);
            if ($descuento_combo <= 0) continue;

            $resultado['descuento_combo'] += $descuento_combo;
            $resultado['descuento_venta'] += $descuento_combo;

            $pid = (int)$promo['id'];
            if (!isset($resultado['promos_aplicadas'][$pid])) {
                $resultado['promos_aplicadas'][$pid] = [
                    'nombre' => $promo['nombre'],
                    'monto'  => 0.0,
                ];
            }
            $resultado['promos_aplicadas'][$pid]['monto'] += $descuento_combo;

            if (!empty($indices_usados)) {
                $resultado['carrito'][$indices_usados[0]]['combo_aplicado'] = [
                    'promocion_id' => $pid,
                    'nombre'       => $promo['nombre'],
                    'descuento'    => $descuento_combo,
                ];
            }
        }

        $resultado['descuento_venta'] = round($resultado['descuento_venta'], 2);
        return $resultado;
    }
}

// =====================================================================
// MODO ENDPOINT AJAX
// =====================================================================

$__es_endpoint = (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
);

if ($__es_endpoint) {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
        exit();
    }

    try {
        $dbname = $_SESSION['empresa_db'] ?? '';
        if (empty($dbname)) {
            throw new Exception('Base de datos de empresa no especificada');
        }

        $conn = getEmpresaDBConnection($dbname);

        $sucursal_id = (int)($_SESSION['sucursal_id'] ?? 0);
        $producto_id = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;

        $contexto = [
            'metodo_pago'  => $_GET['metodo_pago']  ?? null,
            'tipo_cliente' => $_GET['tipo_cliente'] ?? null,
        ];

        $promos = obtenerPromocionesVigentes($conn, $sucursal_id, $contexto);

        // ---------- Filtrado por producto concreto ----------
        if ($producto_id > 0) {
            $stmt = $conn->prepare("
                SELECT id, categoria_id, marca, subprecio AS precio, nombre, codigo
                FROM productos WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$producto_id]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                echo json_encode(['success' => true, 'promociones' => []]);
                exit();
            }

            $item = [
                'id'           => (int)$producto['id'],
                'categoria_id' => (int)$producto['categoria_id'],
                'marca'        => $producto['marca'] ?? '',
                'precio'       => (float)$producto['precio'],
                'cantidad'     => 1,
            ];

            $aplicables = [];
            foreach ($promos as $promo) {
                if ($promo['tipo_promocion'] === 'combo') continue;
                if (!promocionAplicaAProducto($promo, $item)) continue;

                $item_eval = $item;
                if ($promo['tipo_promocion'] === 'llevalo_paga') {
                    $item_eval['cantidad'] = (int)$promo['cantidad_lleva'];
                } elseif ($promo['tipo_promocion'] === 'precio_volumen') {
                    $item_eval['cantidad'] = (int)$promo['cantidad_minima_volumen'];
                }

                $calc = calcularDescuentoPromocion($promo, $item_eval);
                if (!$calc) continue;

                $aplicables[] = [
                    'id'             => (int)$promo['id'],
                    'nombre'         => $promo['nombre'],
                    'tipo_promocion' => $promo['tipo_promocion'],
                    'aplica_a'       => $promo['aplica_a'],
                    'descripcion'    => $promo['descripcion'] ?? '',
                    'color_badge'    => $promo['color_badge'] ?? '#667eea',
                    'prioridad'      => (int)$promo['prioridad'],
                    'acumulable'     => (int)$promo['acumulable'],
                    'detalle'        => $calc['detalle'],
                    'descuento'      => $calc['descuento'],
                ];
            }

            usort($aplicables, fn($a, $b) => $a['prioridad'] <=> $b['prioridad']);

            echo json_encode([
                'success'     => true,
                'promociones' => $aplicables,
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        // ---------- Sin filtro: todas las vigentes ----------
        $respuesta = [];
        foreach ($promos as $p) {
            $respuesta[] = [
                'id'             => (int)$p['id'],
                'nombre'         => $p['nombre'],
                'tipo_promocion' => $p['tipo_promocion'],
                'aplica_a'       => $p['aplica_a'],
                'fecha_inicio'   => $p['fecha_inicio'],
                'fecha_fin'      => $p['fecha_fin'],
                'prioridad'      => (int)$p['prioridad'],
                'acumulable'     => (int)$p['acumulable'],
            ];
        }

        echo json_encode([
            'success'     => true,
            'promociones' => $respuesta,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log("Error en promociones_obtener.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener promociones: ' . $e->getMessage(),
        ]);
    }
}