<?php
// caja.php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Registrar todos los errores en un archivo
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Fatal error: " . print_r($error, true));
        // Si es una petición AJAX, responder con JSON de error
        if (isset($_POST['actualizar_descuento_ajax']) || isset($_POST['agregar_producto_ajax']) || isset($_POST['actualizar_cantidad_ajax']) || isset($_POST['actualizar_precio_ajax'])) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Error fatal: ' . $error['message']
            ]);
            exit();
        }
    }
});
session_start();
date_default_timezone_set('America/Mexico_City');

require_once 'vendor/autoload.php';

use Facturapi\Facturapi;

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// ========== FUNCIÓN PARA OBTENER PRECIO CON MAYOREO ==========
/**
 * Obtiene el precio de un producto según la cantidad (aplica precio de mayoreo si aplica)
 * @param int $producto_id ID del producto
 * @param float $cantidad Cantidad solicitada
 * @param mysqli $conn Conexión a la base de datos
 * @return float Precio calculado
 */
function obtenerPrecioConMayoreo($producto_id, $cantidad, $conn) {
    // Primero obtener el precio normal del producto
    $sql_precio_normal = "SELECT subprecio as precio FROM productos WHERE id = ? AND activo = 1";
    $stmt = $conn->prepare($sql_precio_normal);
    if ($stmt) {
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $producto = $result->fetch_assoc();
            $precio_normal = floatval($producto['precio']);
            $stmt->close();
            
            // Buscar precio de mayoreo que aplique para esta cantidad
            $sql_mayoreo = "SELECT cantidad_minima, precio_especial 
                            FROM producto_precios_mayoreo 
                            WHERE producto_id = ? 
                            AND activo = 1 
                            AND cantidad_minima <= ?
                            ORDER BY cantidad_minima DESC 
                            LIMIT 1";
            
            $stmt_mayoreo = $conn->prepare($sql_mayoreo);
            if ($stmt_mayoreo) {
                $stmt_mayoreo->bind_param("id", $producto_id, $cantidad);
                $stmt_mayoreo->execute();
                $result_mayoreo = $stmt_mayoreo->get_result();
                
                if ($result_mayoreo->num_rows > 0) {
                    $precio_mayoreo = $result_mayoreo->fetch_assoc();
                    $stmt_mayoreo->close();
                    error_log("🎯 Precio de mayoreo aplicado - Producto ID: $producto_id, Cantidad: $cantidad, Precio especial: {$precio_mayoreo['precio_especial']}");
                    return floatval($precio_mayoreo['precio_especial']);
                }
                $stmt_mayoreo->close();
            }
        } else {
            $stmt->close();
        }
    }
    
    return $precio_normal ?? 0;
}

// ========== FUNCIÓN PARA OBTENER INFORMACIÓN COMPLETA DEL PRODUCTO CON PRECIO SEGÚN CANTIDAD ==========
function obtenerProductoConPrecio($producto_id, $cantidad, $conn, $sucursal_id) {
    $sql_producto = "
        SELECT 
            p.id,
            p.codigo,
            p.nombre,
            p.descripcion,
            p.subprecio as precio_base,
            p.subprecio as precio_sin_iva,
            p.costo,
            p.categoria_id,
            p.activo,
            p.imagen,
            p.descuento,
            p.unidad_medida,
            p.peso_kg,
            p.permite_fracciones,
            c.nombre as categoria_nombre,
            COALESCE(ps.stock, 0) as stock_sucursal,
            COALESCE(ps.stock_minimo, 0) as stock_minimo,
            p.stock as stock_general
        FROM productos p
        INNER JOIN categorias c ON p.categoria_id = c.id
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
        WHERE p.id = ? 
        AND p.activo = 1
    ";
    
    $stmt = $conn->prepare($sql_producto);
    if ($stmt) {
        $stmt->bind_param("ii", $sucursal_id, $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $producto = $result->fetch_assoc();
            $stmt->close();
            
            // Calcular precio según mayoreo
            $precio_final = obtenerPrecioConMayoreo($producto_id, $cantidad, $conn);
            $producto['precio_calculado'] = $precio_final;
            $producto['precio_original'] = floatval($producto['precio_base']);
            $producto['tiene_precio_mayoreo'] = ($precio_final < floatval($producto['precio_base']));
            
            return $producto;
        }
        $stmt->close();
    }
    
    return null;
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'] ?? '';

if (empty($dbname)) {
    error_log("ERROR: No se ha especificado la base de datos");
    $_SESSION['error_message'] = "Error de configuración. Contacte al administrador.";
    header("Location: dashboard.php");
    exit();
}

// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$servername_main = "libertyfin.com.mx";
$username_main = "juanc141_alexis";
$password_main = "Alexis1997";
$dbname_main = "juanc141_ventas";

$conn_main = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

// Obtener el plan de la empresa
$empresa_plan = "prueba"; // Valor por defecto
$timbres_totales = 0;
$timbres_disponibles = 0;
$empresa_id = $_SESSION['empresa_id'] ?? 0;

// API Key de Facturapi - FIJA (sk_user para operaciones administrativas)
$FACTURAPI_SECRET_KEY = "sk_user_4SfZdPjwrnrf1zCvryxFaBGxdQETtLhGgoZbUQQuPY";

if ($conn_main) {
    $sql_plan = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_plan = $conn_main->prepare($sql_plan);
    $stmt_plan->bind_param("i", $empresa_id);
    $stmt_plan->execute();
    $result_plan = $stmt_plan->get_result();
    if ($result_plan->num_rows > 0) {
        $plan_data = $result_plan->fetch_assoc();
        $empresa_plan = $plan_data['plan'];
        $timbres_totales = $plan_data['timbres_totales'] ?? 0;
        $timbres_disponibles = $plan_data['timbres_disponibles'] ?? 0;
    }
    $stmt_plan->close();
    $conn_main->close();
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Conectar a la base de datos
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // OBTENER CONFIGURACIÓN DEL SISTEMA - PERO NO APLICAR IVA
    $sql_config = "SELECT iva, moneda, color_primario, color_secundario FROM sistema_config WHERE id = 1";
    $result_config = $conn->query($sql_config);
    $config = $result_config ? $result_config->fetch_assoc() : null;

    $iva_porcentaje = 0; // FORZAR IVA CERO
    $moneda = $config['moneda'] ?? 'MXN';

    // Obtener colores personalizados o usar valores por defecto
    $color_primario = $config['color_primario'] ?? '#27ae60';
    $color_secundario = $config['color_secundario'] ?? '#2ecc71';

    // OBTENER LOGO DE LA EMPRESA
    $logo_empresa = null;
    $logo_path = null;
    $empresa_nombre = $_SESSION['empresa_nombre'] ?? 'Sistema';

    // Intentar obtener el logo de la empresa
    try {
        $sql_logo_config = "SELECT nombre_empresa, direccion, telefono, rfc, logo as logo_empresa FROM sistema_config LIMIT 1";
        $result_logo = $conn->query($sql_logo_config);

        if ($result_logo && $result_logo->num_rows > 0) {
            $config_data = $result_logo->fetch_assoc();
            $empresa_logo = $config_data['logo_empresa'] ?? null;

            if (!empty($config_data['nombre_empresa'])) {
                $empresa_nombre = $config_data['nombre_empresa'];
                $_SESSION['empresa_nombre'] = $empresa_nombre;
            }

            // Buscar el archivo físico del logo
            if (!empty($empresa_logo)) {
                $logo_path = '';
                $rutas_posibles = [
                    $empresa_logo,
                    '../' . $empresa_logo,
                    '../../' . $empresa_logo,
                    'admin/' . $empresa_logo,
                    '../admin/' . $empresa_logo,
                    'logos/' . $empresa_logo,
                    'img/' . $empresa_logo,
                    'images/' . $empresa_logo,
                    'assets/' . $empresa_logo,
                    'uploads/' . $empresa_logo,
                    '../logos/' . $empresa_logo,
                    '../img/' . $empresa_logo,
                    '../images/' . $empresa_logo,
                    '../assets/' . $empresa_logo,
                    '../uploads/' . $empresa_logo
                ];

                foreach ($rutas_posibles as $ruta) {
                    if (file_exists($ruta) && is_file($ruta)) {
                        $logo_path = $ruta;
                        break;
                    }
                }

                if (!empty($logo_path) && file_exists($logo_path)) {
                    $logo_empresa = $logo_path;
                    $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
                    $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                    if (in_array($extension, $extensiones_validas)) {
                        $logo_data = base64_encode(file_get_contents($logo_path));
                        $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
                    } else {
                        error_log("Formato de imagen no válido para el logo: " . $extension);
                    }
                } else {
                    error_log("Logo no encontrado en ninguna ruta: " . $empresa_logo);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error al obtener logo de sistema_config: " . $e->getMessage());
    }
    
    // VERIFICACIÓN MEJORADA DE CAJA ABIERTA
    $caja_actual = null;
    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    $sucursal_id = $_SESSION['sucursal_id'] ?? 0;

    // PRIMERO: Intentar usar la caja de la sesión si existe
    if (isset($_SESSION['caja_actual_id']) && !empty($_SESSION['caja_actual_id'])) {
        $caja_id_sesion = $_SESSION['caja_actual_id'];

        $sql_caja_sesion = "SELECT * FROM caja WHERE id = ? AND estado = 'abierta'";
        $stmt_sesion = $conn->prepare($sql_caja_sesion);
        if ($stmt_sesion) {
            $stmt_sesion->bind_param("i", $caja_id_sesion);

            if ($stmt_sesion->execute()) {
                $result_sesion = $stmt_sesion->get_result();
                $caja_actual = $result_sesion->fetch_assoc();

                if ($caja_actual) {
                    error_log("✅ Caja encontrada por ID de sesión - ID: " . $caja_actual['id']);
                } else {
                    error_log("❌ Caja NO encontrada por ID de sesión - ID: $caja_id_sesion");
                    unset($_SESSION['caja_actual_id']);
                }
            } else {
                error_log("❌ Error en consulta por sesión: " . $stmt_sesion->error);
            }
            $stmt_sesion->close();
        }
    }

    // SEGUNDO: Si no se encontró por ID de sesión, buscar por usuario/sucursal
    if (!$caja_actual) {
        error_log("Buscando caja por usuario/sucursal...");

        $sql_caja = "SELECT * FROM caja WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql_caja);

        if ($stmt) {
            $stmt->bind_param("ii", $usuario_id, $sucursal_id);

            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $caja_actual = $result->fetch_assoc();

                if ($caja_actual) {
                    error_log("✅ Caja encontrada por usuario/sucursal - ID: " . $caja_actual['id']);
                } else {
                    error_log("❌ Caja NO encontrada por usuario/sucursal");
                }
            } else {
                error_log("❌ Error en ejecución de consulta: " . $stmt->error);
            }
            $stmt->close();
        } else {
            error_log("❌ Error en preparación de consulta: " . $conn->error);
        }
    }

    if ($caja_actual) {
        $_SESSION['caja_actual_id'] = $caja_actual['id'];
        $_SESSION['caja_actual'] = $caja_actual;
    } else {
        header("Location: caja_apertura.php");
        exit();
    }

    // Inicializar carrito si no existe
    if (!isset($_SESSION['carrito'])) {
        $_SESSION['carrito'] = [];
    }

    // Obtener categorías con conteo de productos
    $categorias_con_count = [];
    $sql_categorias = "
        SELECT c.*, COUNT(p.id) as producto_count
        FROM categorias c
        LEFT JOIN productos p ON c.id = p.categoria_id 
            AND p.activo = 1
        LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
            AND (COALESCE(ps.stock, 0) > 0)
        WHERE c.activo = 1
        GROUP BY c.id
        ORDER BY c.nombre
    ";

    $stmt = $conn->prepare($sql_categorias);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['sucursal_id']);
        $stmt->execute();
        $categorias_con_count = $stmt->get_result();
        $stmt->close();
    }

    // Obtener productos CON PRECIO SIN IVA Y SOLO CON STOCK EN LA SUCURSAL
    $productos = [];
    $categoria_seleccionada = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : null;
    $busqueda_nombre = isset($_GET['busqueda_nombre']) ? trim($_GET['busqueda_nombre']) : '';

    // Construir consulta de productos - SOLO PRODUCTOS CON STOCK EN LA SUCURSAL
    $columnas_productos = ",
            p.unidad_medida,
            p.peso_kg,
            p.permite_fracciones,
            p.imagen,
            p.descuento";

    // Construir la consulta basada en los filtros
    if (!$categoria_seleccionada && empty($busqueda_nombre)) {
        $sql_productos = "
            SELECT 
                p.id,
                p.codigo,
                p.nombre,
                p.descripcion,
                p.subprecio as precio_sin_iva,
                p.subprecio as precio,
                p.costo,
                p.categoria_id,
                p.activo,
                p.imagen,
                p.descuento" .
            $columnas_productos . ",
                c.nombre as categoria_nombre,
                COALESCE(ps.stock, 0) as stock_sucursal,
                COALESCE(ps.stock_minimo, 0) as stock_minimo,
                p.stock as stock_general
            FROM productos p
            INNER JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
            WHERE p.activo = 1
            AND (COALESCE(ps.stock, 0) > 0)
            ORDER BY p.nombre
            LIMIT 100
        ";

        $stmt = $conn->prepare($sql_productos);
        if ($stmt) {
            $stmt->bind_param("i", $_SESSION['sucursal_id']);
            $stmt->execute();
            $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } elseif ($categoria_seleccionada && empty($busqueda_nombre)) {
        $sql_productos = "
            SELECT 
                p.id,
                p.codigo,
                p.nombre,
                p.descripcion,
                p.subprecio as precio_sin_iva,
                p.subprecio as precio,
                p.costo,
                p.categoria_id,
                p.activo,
                p.imagen,
                p.descuento" .
            $columnas_productos . ",
                c.nombre as categoria_nombre,
                COALESCE(ps.stock, 0) as stock_sucursal,
                COALESCE(ps.stock_minimo, 0) as stock_minimo,
                p.stock as stock_general
            FROM productos p
            INNER JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
            WHERE p.categoria_id = ?
            AND p.activo = 1
            AND (COALESCE(ps.stock, 0) > 0)
            ORDER BY p.nombre
        ";

        $stmt = $conn->prepare($sql_productos);
        if ($stmt) {
            $stmt->bind_param("ii", $_SESSION['sucursal_id'], $categoria_seleccionada);
            $stmt->execute();
            $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } elseif (!empty($busqueda_nombre)) {
        if ($categoria_seleccionada) {
            $sql_productos = "
                SELECT 
                    p.id,
                    p.codigo,
                    p.nombre,
                    p.descripcion,
                    p.subprecio as precio_sin_iva,
                    p.subprecio as precio,
                    p.costo,
                    p.categoria_id,
                    p.activo,
                    p.imagen,
                    p.descuento" .
                $columnas_productos . ",
                    c.nombre as categoria_nombre,
                    COALESCE(ps.stock, 0) as stock_sucursal,
                    COALESCE(ps.stock_minimo, 0) as stock_minimo,
                    p.stock as stock_general
                FROM productos p
                INNER JOIN categorias c ON p.categoria_id = c.id
                LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
                WHERE p.categoria_id = ?
                AND p.activo = 1
                AND (COALESCE(ps.stock, 0) > 0)
                AND (p.nombre LIKE ? OR p.codigo LIKE ?)
                ORDER BY p.nombre
            ";

            $stmt = $conn->prepare($sql_productos);
            if ($stmt) {
                $search_term = "%" . $busqueda_nombre . "%";
                $stmt->bind_param("iiss", $_SESSION['sucursal_id'], $categoria_seleccionada, $search_term, $search_term);
                $stmt->execute();
                $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        } else {
            $sql_productos = "
                SELECT 
                    p.id,
                    p.codigo,
                    p.nombre,
                    p.descripcion,
                    p.subprecio as precio_sin_iva,
                    p.subprecio as precio,
                    p.costo,
                    p.categoria_id,
                    p.activo,
                    p.imagen,
                    p.descuento" .
                $columnas_productos . ",
                    c.nombre as categoria_nombre,
                    COALESCE(ps.stock, 0) as stock_sucursal,
                    COALESCE(ps.stock_minimo, 0) as stock_minimo,
                    p.stock as stock_general
                FROM productos p
                INNER JOIN categorias c ON p.categoria_id = c.id
                LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
                WHERE p.activo = 1
                AND (COALESCE(ps.stock, 0) > 0)
                AND (p.nombre LIKE ? OR p.codigo LIKE ?)
                ORDER BY p.nombre
                LIMIT 100
            ";

            $stmt = $conn->prepare($sql_productos);
            if ($stmt) {
                $search_term = "%" . $busqueda_nombre . "%";
                $stmt->bind_param("iss", $_SESSION['sucursal_id'], $search_term, $search_term);
                $stmt->execute();
                $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }
    }

    // Obtener clientes
    $clientes = [];
    $sql_clientes = "SELECT * FROM clientes WHERE activo = 1 ORDER BY nombre";
    $stmt = $conn->prepare($sql_clientes);
    if ($stmt) {
        $stmt->execute();
        $clientes = $stmt->get_result();
        $stmt->close();
    }
    
    // ========== MANEJO DE ACTUALIZACIÓN DE PRECIO UNITARIO VIA AJAX ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_precio_ajax'])) {
    // Asegurar que la sesión esté iniciada
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Limpiar cualquier búfer previo
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    $response = [
        'success' => false,
        'message' => '',
        'carrito_actualizado' => $_SESSION['carrito'] ?? [],
        'totales' => []
    ];
    
    try {
        // Verificar sesión
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            throw new Exception('Sesión no válida');
        }
        
        $index = intval($_POST['index']);
        $nuevo_precio = floatval(str_replace(',', '.', $_POST['precio']));
        
        if ($nuevo_precio <= 0) {
            throw new Exception("El precio debe ser mayor a 0");
        }
        
        if (isset($_SESSION['carrito'][$index])) {
            // Actualizar el precio unitario manualmente
            $_SESSION['carrito'][$index]['precio'] = $nuevo_precio;
            $_SESSION['carrito'][$index]['precio_base'] = $nuevo_precio;
            $_SESSION['carrito'][$index]['precio_sin_iva'] = $nuevo_precio;
            $_SESSION['carrito'][$index]['precio_original'] = $nuevo_precio;
            $_SESSION['carrito'][$index]['tiene_precio_mayoreo'] = false;
            
            // Recalcular subtotal
            $cantidad = floatval($_SESSION['carrito'][$index]['cantidad']);
            $subtotal = $cantidad * $nuevo_precio;
            $_SESSION['carrito'][$index]['subtotal'] = $subtotal;
            
            // Recalcular descuento si existe
            $descuento_porcentaje = floatval($_SESSION['carrito'][$index]['descuento_porcentaje'] ?? 0);
            if ($descuento_porcentaje > 0) {
                $descuento_total = $subtotal * ($descuento_porcentaje / 100);
                $_SESSION['carrito'][$index]['descuento'] = $descuento_total;
                $_SESSION['carrito'][$index]['subtotal_con_descuento'] = $subtotal - $descuento_total;
            } else {
                $_SESSION['carrito'][$index]['descuento'] = 0;
                $_SESSION['carrito'][$index]['subtotal_con_descuento'] = $subtotal;
            }
            
            $response['success'] = true;
            $response['message'] = "Precio unitario actualizado a $" . number_format($nuevo_precio, 2);
        } else {
            throw new Exception("Producto no encontrado en el carrito");
        }
        
        // Calcular totales
        $subtotal_carrito = 0;
        $descuento_carrito = 0;
        $subtotal_con_descuento_carrito = 0;
        
        foreach ($_SESSION['carrito'] as $item) {
            $subtotal_carrito += (float)$item['subtotal'];
            $descuento_carrito += (float)($item['descuento'] ?? 0);
            $subtotal_con_descuento_carrito += (float)($item['subtotal_con_descuento'] ?? $item['subtotal']);
        }
        
        $response['totales'] = [
            'subtotal' => (float)$subtotal_carrito,
            'descuento' => (float)$descuento_carrito,
            'subtotal_con_descuento' => (float)$subtotal_con_descuento_carrito,
            'iva' => 0,
            'total' => (float)$subtotal_con_descuento_carrito
        ];
        
        $response['carrito_actualizado'] = $_SESSION['carrito'];
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("Error en actualizar_precio_ajax: " . $e->getMessage());
    }
    
    // Asegurar que no haya salida previa
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode($response);
    exit();
}

    // ========== MANEJO DE ACTUALIZACIÓN DE CANTIDAD VIA AJAX (CON RECALCULO DE PRECIO POR MAYOREO) ==========
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cantidad_ajax'])) {
        header('Content-Type: application/json');

        $response = [
            'success' => false,
            'message' => '',
            'carrito_actualizado' => $_SESSION['carrito'] ?? [],
            'totales' => []
        ];

        try {
            $index = intval($_POST['index']);
            $cantidad = $_POST['cantidad'];

            if (isset($_SESSION['carrito'][$index])) {
                $producto_id = $_SESSION['carrito'][$index]['id'];
                $permite_decimales = $_SESSION['carrito'][$index]['permite_fracciones'] == 1;

                // Validar según permite decimales
                if ($permite_decimales) {
                    $cantidad = (float)$cantidad;
                    if ($cantidad <= 0) {
                        throw new Exception("La cantidad debe ser mayor a 0");
                    }
                } else {
                    $cantidad = (int)$cantidad;
                    if ($cantidad <= 0) {
                        throw new Exception("La cantidad debe ser un número entero mayor a 0");
                    }
                }

                // Verificar stock y obtener precio actualizado según mayoreo
                $sql_stock = "
                SELECT 
                    COALESCE(ps.stock, 0) as stock_sucursal, 
                    p.nombre,
                    p.permite_fracciones,
                    p.descuento,
                    p.subprecio as precio_base
                FROM productos p 
                LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
                WHERE p.id = ?
                AND p.activo = 1
            ";

                $stmt = $conn->prepare($sql_stock);
                if ($stmt) {
                    $stmt->bind_param("ii", $_SESSION['sucursal_id'], $producto_id);
                    $stmt->execute();
                    $stock_result = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $stock_actual = (float)($stock_result ? $stock_result['stock_sucursal'] : 0);
                    $producto_nombre = $stock_result ? $stock_result['nombre'] : 'Producto';
                    $descuento_porcentaje = (float)($stock_result ? $stock_result['descuento'] : 0);
                    $precio_base = (float)($stock_result ? $stock_result['precio_base'] : 0);

                    if (!$permite_decimales) {
                        if ($cantidad <= $stock_actual) {
                            $_SESSION['carrito'][$index]['cantidad'] = $cantidad;
                        } else {
                            throw new Exception("Stock insuficiente para: " . $producto_nombre . " (Stock: " . $stock_actual . ")");
                        }
                    } else {
                        $_SESSION['carrito'][$index]['cantidad'] = $cantidad;
                    }

                    // RECALCULAR PRECIO SEGÚN MAYOREO (solo si no se ha editado manualmente el precio)
                    // Verificar si el precio actual es diferente al precio base (podría ser manual)
                    $precio_actual = floatval($_SESSION['carrito'][$index]['precio']);
                    $precio_mayoreo = obtenerPrecioConMayoreo($producto_id, $cantidad, $conn);
                    
                    // Si el precio actual es igual al precio base original o es un precio de mayoreo válido, actualizar
                    // Si el usuario editó manualmente el precio, respetar ese cambio
                    $precio_base_original = floatval($_SESSION['carrito'][$index]['precio_original'] ?? $precio_base);
                    
                    if ($precio_actual == $precio_base_original || $precio_actual == $precio_base) {
                        // El precio no ha sido editado manualmente, aplicar mayoreo si corresponde
                        $precio_unitario = $precio_mayoreo;
                        $_SESSION['carrito'][$index]['tiene_precio_mayoreo'] = ($precio_unitario < $precio_base);
                        $_SESSION['carrito'][$index]['precio_base'] = $precio_base;
                        $_SESSION['carrito'][$index]['precio_original'] = $precio_base;
                    } else {
                        // El precio fue editado manualmente, mantener ese precio
                        $precio_unitario = $precio_actual;
                    }
                    
                    $_SESSION['carrito'][$index]['precio'] = $precio_unitario;
                    $_SESSION['carrito'][$index]['precio_sin_iva'] = $precio_unitario;
                    
                    // Recalcular subtotal
                    $subtotal = (float)$cantidad * $precio_unitario;
                    $_SESSION['carrito'][$index]['subtotal'] = $subtotal;

                    // Calcular descuento
                    if ($descuento_porcentaje > 0) {
                        $descuento_total = $subtotal * ($descuento_porcentaje / 100);
                        $_SESSION['carrito'][$index]['descuento'] = (float)$descuento_total;
                        $_SESSION['carrito'][$index]['subtotal_con_descuento'] = (float)($subtotal - $descuento_total);
                    } else {
                        $_SESSION['carrito'][$index]['descuento'] = 0;
                        $_SESSION['carrito'][$index]['subtotal_con_descuento'] = (float)$subtotal;
                    }

                    $response['success'] = true;
                    $response['message'] = "Cantidad actualizada: " . $producto_nombre . 
                        ($_SESSION['carrito'][$index]['tiene_precio_mayoreo'] ?? false ? " (Precio mayoreo aplicado)" : "");
                }
            } else {
                throw new Exception("Producto no encontrado en el carrito");
            }

            // Calcular totales
            $subtotal_carrito = 0;
            $descuento_carrito = 0;
            $subtotal_con_descuento_carrito = 0;

            foreach ($_SESSION['carrito'] as $item) {
                $subtotal_carrito += (float)$item['subtotal'];
                $descuento_carrito += (float)($item['descuento'] ?? 0);
                $subtotal_con_descuento_carrito += (float)($item['subtotal_con_descuento'] ?? $item['subtotal']);
            }

            $response['totales'] = [
                'subtotal' => (float)$subtotal_carrito,
                'descuento' => (float)$descuento_carrito,
                'subtotal_con_descuento' => (float)$subtotal_con_descuento_carrito,
                'iva' => 0,
                'total' => (float)$subtotal_con_descuento_carrito
            ];

            $response['carrito_actualizado'] = $_SESSION['carrito'];
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
        }

        echo json_encode($response);
        exit();
    }
    
    // ========== MANEJO DE ACTUALIZACIÓN DE DESCUENTO VIA AJAX ==========
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_descuento_ajax'])) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        error_log("=== INICIO actualizar_descuento_ajax ===");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            error_log("ERROR: Sesión no válida");
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Sesión no válida. Por favor inicie sesión nuevamente.'
            ]);
            exit();
        }

        if (!isset($conn) || $conn->connect_error) {
            error_log("ERROR: Conexión a BD no disponible");
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Error de conexión a la base de datos'
            ]);
            exit();
        }

        $response = [
            'success' => false,
            'message' => '',
            'carrito_actualizado' => [],
            'totales' => []
        ];

        try {
            $producto_id = isset($_POST['producto_id']) ? (int)$_POST['producto_id'] : 0;
            $descuento_porcentaje = isset($_POST['descuento_porcentaje']) ? (float)$_POST['descuento_porcentaje'] : 0;
            $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;

            error_log("Datos recibidos - producto_id: $producto_id, descuento: $descuento_porcentaje, index: $index");

            if ($producto_id <= 0) {
                throw new Exception('ID de producto no válido');
            }

            if ($index < 0 || !isset($_SESSION['carrito'][$index])) {
                throw new Exception('Producto no encontrado en el carrito');
            }

            if ($descuento_porcentaje < 0) $descuento_porcentaje = 0;
            if ($descuento_porcentaje > 100) $descuento_porcentaje = 100;

            $sql_update = "UPDATE productos SET descuento = ? WHERE id = ?";
            $stmt = $conn->prepare($sql_update);

            if (!$stmt) {
                error_log("Error preparando consulta: " . $conn->error);
                $sql_add = "ALTER TABLE productos ADD COLUMN descuento DECIMAL(5,2) DEFAULT 0";
                $conn->query($sql_add);
                $stmt = $conn->prepare($sql_update);
                if (!$stmt) {
                    throw new Exception('Error al preparar consulta después de crear columna');
                }
            }

            $stmt->bind_param("di", $descuento_porcentaje, $producto_id);

            if (!$stmt->execute()) {
                throw new Exception('Error al ejecutar consulta: ' . $stmt->error);
            }
            $stmt->close();

            error_log("Descuento actualizado en BD para producto ID: $producto_id");

            $item = &$_SESSION['carrito'][$index];
            $subtotal = $item['cantidad'] * $item['precio'];
            $descuento_total = $subtotal * ($descuento_porcentaje / 100);

            $item['descuento_porcentaje'] = $descuento_porcentaje;
            $item['descuento'] = $descuento_total;
            $item['subtotal_con_descuento'] = $subtotal - $descuento_total;

            $subtotal_carrito = 0;
            $descuento_carrito = 0;
            $subtotal_con_descuento_carrito = 0;

            foreach ($_SESSION['carrito'] as $item_cart) {
                $subtotal_carrito += $item_cart['subtotal'];
                $descuento_carrito += $item_cart['descuento'] ?? 0;
                $subtotal_con_descuento_carrito += $item_cart['subtotal_con_descuento'] ?? $item_cart['subtotal'];
            }

            $response['success'] = true;
            $response['message'] = "Descuento actualizado a {$descuento_porcentaje}%";
            $response['carrito_actualizado'] = $_SESSION['carrito'];
            $response['totales'] = [
                'subtotal' => $subtotal_carrito,
                'descuento' => $descuento_carrito,
                'subtotal_con_descuento' => $subtotal_con_descuento_carrito,
                'iva' => 0,
                'total' => $subtotal_con_descuento_carrito
            ];

            error_log("Respuesta exitosa preparada");
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
            error_log("EXCEPCIÓN: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        echo json_encode($response);
        error_log("=== FIN actualizar_descuento_ajax ===");
        exit();
    }

    // ========== AGREGAR PRODUCTO VIA AJAX (CON PRECIO POR MAYOREO) ==========
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_producto_ajax'])) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

        $response = [
            'success' => false,
            'message' => '',
            'carrito_actualizado' => [],
            'totales' => []
        ];

        try {
            if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
                throw new Exception('Sesión no válida');
            }

            if (!isset($conn) || $conn->connect_error) {
                throw new Exception('Error de conexión a la base de datos');
            }

            $producto_id = intval($_POST['producto_id']);
            $cantidad = floatval($_POST['cantidad']);

            if ($producto_id <= 0) {
                throw new Exception('ID de producto no válido');
            }

            if ($cantidad <= 0) {
                throw new Exception('La cantidad debe ser mayor a 0');
            }

            // Obtener información del producto CON PRECIO SEGÚN MAYOREO
            $producto = obtenerProductoConPrecio($producto_id, $cantidad, $conn, $_SESSION['sucursal_id']);

            if (!$producto) {
                throw new Exception('Producto no encontrado o inactivo');
            }

            $ruta_imagen = obtenerImagenProducto($producto['id'], $conn);
            $stock_disponible = (float)($producto['stock_sucursal'] ?? 0);
            $descuento_porcentaje = (float)($producto['descuento'] ?? 0);
            $precio_unitario = (float)($producto['precio_calculado'] ?? $producto['precio_sin_iva']);
            $precio_base = (float)($producto['precio_base'] ?? $producto['precio_sin_iva']);
            $tiene_precio_mayoreo = $producto['tiene_precio_mayoreo'] ?? false;

            // Normalizar unidad de medida
            $unidad = strtolower(trim($producto['unidad_medida']));

            $unidades_decimales = [
                'kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
            ];

            $unidades_enteras = ['pieza', 'piezas', 'unidad', 'unidades', 'pza', 'pzas'];

            $permite_decimales = false;

            if ($producto['permite_fracciones'] == 1) {
                $permite_decimales = true;
            }
            if (in_array($unidad, $unidades_decimales)) {
                $permite_decimales = true;
            }
            if (in_array($unidad, $unidades_enteras)) {
                $permite_decimales = false;
            }

            if ($permite_decimales) {
                if ($stock_disponible <= 0 && $stock_disponible > 0) {
                    throw new Exception("Stock insuficiente. Disponible: " . $stock_disponible);
                }
            } else {
                $cantidad = (int)$cantidad;
                $stock_carrito_actual = 0;
                foreach ($_SESSION['carrito'] as $item) {
                    if ($item['id'] == $producto_id) {
                        $stock_carrito_actual += (int)$item['cantidad'];
                    }
                }
                $stock_necesario = $stock_carrito_actual + $cantidad;
                if ($stock_disponible < $stock_necesario) {
                    throw new Exception("Stock insuficiente. Disponible: " . $stock_disponible);
                }
            }

            // Buscar si el producto ya está en el carrito
            $encontrado = false;
            $encontrado_index = -1;

            for ($i = 0; $i < count($_SESSION['carrito']); $i++) {
                if ($_SESSION['carrito'][$i]['id'] == $producto_id) {
                    $encontrado = true;
                    $encontrado_index = $i;
                    break;
                }
            }

            if ($encontrado && $encontrado_index >= 0) {
                // Actualizar producto existente - RECALCULAR PRECIO CON LA NUEVA CANTIDAD TOTAL
                $nueva_cantidad = (float)$_SESSION['carrito'][$encontrado_index]['cantidad'] + $cantidad;
                
                // Recalcular precio según mayoreo con la cantidad total
                $precio_mayoreo_actualizado = obtenerPrecioConMayoreo($producto_id, $nueva_cantidad, $conn);
                
                $_SESSION['carrito'][$encontrado_index]['cantidad'] = $nueva_cantidad;
                $_SESSION['carrito'][$encontrado_index]['precio'] = $precio_mayoreo_actualizado;
                $_SESSION['carrito'][$encontrado_index]['precio_base'] = $precio_base;
                $_SESSION['carrito'][$encontrado_index]['tiene_precio_mayoreo'] = ($precio_mayoreo_actualizado < $precio_base);
                
                $subtotal = $nueva_cantidad * $precio_mayoreo_actualizado;
                $_SESSION['carrito'][$encontrado_index]['subtotal'] = $subtotal;

                if ($descuento_porcentaje > 0) {
                    $descuento_total = $subtotal * ($descuento_porcentaje / 100);
                    $_SESSION['carrito'][$encontrado_index]['descuento'] = (float)$descuento_total;
                    $_SESSION['carrito'][$encontrado_index]['subtotal_con_descuento'] = (float)($subtotal - $descuento_total);
                } else {
                    $_SESSION['carrito'][$encontrado_index]['descuento'] = 0;
                    $_SESSION['carrito'][$encontrado_index]['subtotal_con_descuento'] = (float)$subtotal;
                }

                $_SESSION['carrito'][$encontrado_index]['imagen_ruta'] = $ruta_imagen;
                
                $response['message'] = "Producto actualizado: " . $producto['nombre'] . 
                    ($precio_mayoreo_actualizado < $precio_base ? " (Precio mayoreo aplicado)" : "");
            } else {
                // Agregar nuevo producto
                $subtotal = $precio_unitario * $cantidad;
                $descuento_total = 0;
                $subtotal_con_descuento = $subtotal;

                if ($descuento_porcentaje > 0) {
                    $descuento_total = $subtotal * ($descuento_porcentaje / 100);
                    $subtotal_con_descuento = $subtotal - $descuento_total;
                }

                $_SESSION['carrito'][] = [
                    'id' => $producto['id'],
                    'codigo' => $producto['codigo'],
                    'nombre' => $producto['nombre'],
                    'precio' => $precio_unitario,
                    'precio_base' => $precio_base,
                    'precio_sin_iva' => $precio_unitario,
                    'precio_original' => $precio_base,
                    'tiene_precio_mayoreo' => $tiene_precio_mayoreo,
                    'cantidad' => $permite_decimales ? (float)$cantidad : (int)$cantidad,
                    'subtotal' => (float)$subtotal,
                    'descuento' => (float)$descuento_total,
                    'descuento_porcentaje' => (float)$descuento_porcentaje,
                    'subtotal_con_descuento' => (float)$subtotal_con_descuento,
                    'tipo_venta' => $permite_decimales ? $producto['unidad_medida'] : 'unidad',
                    'unidad_medida' => $producto['unidad_medida'],
                    'peso_kg' => $producto['peso_kg'],
                    'permite_fracciones' => $permite_decimales ? 1 : 0,
                    'imagen' => $producto['imagen'],
                    'imagen_ruta' => $ruta_imagen
                ];
                
                $response['message'] = "Producto agregado: " . $producto['nombre'] . 
                    ($tiene_precio_mayoreo ? " (Precio mayoreo aplicado)" : "");
            }

            $response['success'] = true;

            // Calcular totales
            $subtotal_carrito = 0;
            $descuento_carrito = 0;
            $subtotal_con_descuento_carrito = 0;

            foreach ($_SESSION['carrito'] as $item) {
                $subtotal_carrito += (float)$item['subtotal'];
                $descuento_carrito += (float)($item['descuento'] ?? 0);
                $subtotal_con_descuento_carrito += (float)($item['subtotal_con_descuento'] ?? $item['subtotal']);
            }

            $response['carrito_actualizado'] = $_SESSION['carrito'];
            $response['totales'] = [
                'subtotal' => (float)$subtotal_carrito,
                'descuento' => (float)$descuento_carrito,
                'subtotal_con_descuento' => (float)$subtotal_con_descuento_carrito,
                'iva' => 0,
                'total' => (float)$subtotal_con_descuento_carrito
            ];
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
            error_log("Error en agregar_producto_ajax: " . $e->getMessage());
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        echo json_encode($response);
        exit();
    }
    
    // ========== MANEJO DE PETICIONES AJAX PARA ELIMINAR Y VACIAR ==========
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_producto_ajax'])) {
        header('Content-Type: application/json');

        $index = intval($_POST['index']);
        $response = [
            'success' => false,
            'message' => '',
            'carrito_actualizado' => $_SESSION['carrito'] ?? [],
            'totales' => []
        ];

        if (isset($_SESSION['carrito']) && array_key_exists($index, $_SESSION['carrito'])) {
            $producto_nombre = $_SESSION['carrito'][$index]['nombre'];
            array_splice($_SESSION['carrito'], $index, 1);
            $response['success'] = true;
            $response['message'] = "Producto eliminado: " . $producto_nombre;
        } else {
            $response['message'] = "Producto no encontrado en el carrito";
        }

        $subtotal_carrito = 0;
        $descuento_carrito = 0;
        $subtotal_con_descuento_carrito = 0;

        foreach ($_SESSION['carrito'] ?? [] as $item) {
            $subtotal_carrito += $item['subtotal'];
            $descuento_carrito += $item['descuento'] ?? 0;
            $subtotal_con_descuento_carrito += $item['subtotal_con_descuento'] ?? $item['subtotal'];
        }

        $iva_carrito = 0;
        $total_carrito = $subtotal_con_descuento_carrito;

        $response['totales'] = [
            'subtotal' => $subtotal_carrito,
            'descuento' => $descuento_carrito,
            'subtotal_con_descuento' => $subtotal_con_descuento_carrito,
            'iva' => $iva_carrito,
            'total' => $total_carrito
        ];

        $response['carrito_actualizado'] = $_SESSION['carrito'] ?? [];

        echo json_encode($response);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vaciar_carrito_ajax'])) {
        header('Content-Type: application/json');

        $response = [
            'success' => false,
            'message' => '',
            'carrito_actualizado' => [],
            'totales' => []
        ];

        if (!empty($_SESSION['carrito'])) {
            $_SESSION['carrito'] = [];
            $response['success'] = true;
            $response['message'] = "Carrito vaciado exitosamente";
            $response['carrito_actualizado'] = $_SESSION['carrito'];
            $response['totales'] = [
                'subtotal' => 0,
                'descuento' => 0,
                'subtotal_con_descuento' => 0,
                'iva' => 0,
                'total' => 0
            ];
        } else {
            $response['message'] = "El carrito ya está vacío";
        }

        echo json_encode($response);
        exit();
    }

    // ========== MANEJO DE PETICIONES AJAX PARA CLIENTE ==========
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cliente_ajax'])) {
        header('Content-Type: application/json');

        $cliente_id = isset($_POST['cliente_id']) ? ($_POST['cliente_id'] === '' ? null : intval($_POST['cliente_id'])) : null;

        $response = [
            'success' => false,
            'message' => '',
            'cliente_id' => $cliente_id
        ];

        try {
            if ($cliente_id === null) {
                unset($_SESSION['cliente_venta']);
                $response['success'] = true;
                $response['message'] = "Cliente cambiado a Cliente General";
            } else {
                $sql_cliente = "SELECT id, nombre FROM clientes WHERE id = ? AND activo = 1";
                $stmt = $conn->prepare($sql_cliente);
                if ($stmt) {
                    $stmt->bind_param("i", $cliente_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $cliente = $result->fetch_assoc();
                    $stmt->close();

                    if ($cliente) {
                        $_SESSION['cliente_venta'] = $cliente_id;
                        $response['success'] = true;
                        $response['message'] = "Cliente seleccionado: " . htmlspecialchars($cliente['nombre']);
                        $response['cliente_nombre'] = htmlspecialchars($cliente['nombre']);
                    } else {
                        $response['message'] = "Cliente no encontrado";
                    }
                }
            }
        } catch (Exception $e) {
            $response['message'] = "Error al actualizar cliente: " . $e->getMessage();
        }

        echo json_encode($response);
        exit();
    }

    // ========== PROCESAR VENTA CON FACTURAPI ==========
    if (isset($_POST['procesar_pago'])) {
        error_log("Plan de empresa: " . $empresa_plan);
        error_log("Timbres disponibles: " . $timbres_disponibles);

        if (empty($_SESSION['carrito'])) {
            $_SESSION['error_message'] = "El carrito está vacío";
        } else {
            $metodos_validos = ['efectivo', 'tarjeta', 'transferencia'];
            $metodo_pago = $_POST['metodo_pago'] ?? 'efectivo';
            if (!in_array($metodo_pago, $metodos_validos)) {
                $_SESSION['error_message'] = "Método de pago no válido";
                header("Location: caja.php");
                exit();
            }

            $efectivo_recibido = floatval($_POST['efectivo_recibido'] ?? 0);
            $cambio = floatval($_POST['cambio'] ?? 0);
            $descuento_total = floatval($_POST['descuento_total'] ?? 0);

            $subtotal_sin_iva = 0;
            $subtotal_sin_descuento = 0;
            $descuento_carrito = 0;

            foreach ($_SESSION['carrito'] as $item) {
                $subtotal_sin_descuento += $item['subtotal'];
                $descuento_carrito += $item['descuento'] ?? 0;
            }

            if ($descuento_total == 0) {
                $descuento_total = $descuento_carrito;
            }

            $subtotal_sin_iva = $subtotal_sin_descuento - $descuento_total;
            if ($subtotal_sin_iva < 0) {
                $subtotal_sin_iva = 0;
            }

            $iva_total = 0;
            $total = $subtotal_sin_iva;

            if ($metodo_pago === 'efectivo' && $efectivo_recibido < $total) {
                $_SESSION['error_message'] = "El efectivo recibido es menor al total a pagar";
                header("Location: caja.php");
                exit();
            }

            // Validar stock para cada producto
            foreach ($_SESSION['carrito'] as $item) {
                $sql_stock = "
                    SELECT 
                        COALESCE(ps.stock, 0) as stock_sucursal, 
                        p.nombre,
                        p.permite_fracciones
                    FROM productos p 
                    LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
                    WHERE p.id = ?
                    AND p.activo = 1
                ";
                $stmt = $conn->prepare($sql_stock);
                if ($stmt) {
                    $stmt->bind_param("ii", $_SESSION['sucursal_id'], $item['id']);
                    $stmt->execute();
                    $stock_result = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $stock_actual = $stock_result ? $stock_result['stock_sucursal'] : 0;
                    $producto_nombre = $stock_result ? $stock_result['nombre'] : 'Producto';
                    $permite_fracciones = $stock_result ? $stock_result['permite_fracciones'] : 0;

                    if ($permite_fracciones == 0) {
                        if ($stock_actual < intval($item['cantidad'])) {
                            $_SESSION['error_message'] = "Stock insuficiente en esta sucursal para: " . $producto_nombre . " (Stock: " . $stock_actual . ")";
                            header("Location: caja.php");
                            exit();
                        }
                    } else {
                        if ($stock_actual <= 0) {
                            $_SESSION['error_message'] = "Producto sin stock en esta sucursal: " . $producto_nombre;
                            header("Location: caja.php");
                            exit();
                        }
                    }
                }
            }

            $conn->begin_transaction();

            try {
                $codigo_venta = date('YmdHis');
                $cliente_id = $_SESSION['cliente_venta'] ?? null;
                if (empty($cliente_id)) {
                    $cliente_id = null;
                }

                $sql_venta = "
                    INSERT INTO ventas (codigo_venta, cliente_id, usuario_id, sucursal_id, caja_id, subtotal, descuento, iva, total, metodo_pago, estado, efectivo_recibido, cambio)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completada', ?, ?)
                ";
                $stmt = $conn->prepare($sql_venta);

                if ($stmt) {
                    $caja_id = $_SESSION['caja_actual_id'] ?? $caja_actual['id'];

                    $stmt->bind_param(
                        "siiiiddddsdd",
                        $codigo_venta,
                        $cliente_id,
                        $_SESSION['usuario_id'],
                        $_SESSION['sucursal_id'],
                        $caja_id,
                        $subtotal_sin_descuento,
                        $descuento_total,
                        $iva_total,
                        $total,
                        $metodo_pago,
                        $efectivo_recibido,
                        $cambio
                    );

                    if (!$stmt->execute()) {
                        throw new Exception("Error al insertar venta: " . $stmt->error);
                    }
                    $venta_id = $conn->insert_id;
                    $stmt->close();

                    error_log("✅ Venta insertada - ID: $venta_id, Código: $codigo_venta");

                    // Insertar detalles y actualizar stock
                    foreach ($_SESSION['carrito'] as $item) {
                        $precio_unitario_sin_iva = $item['precio'];
                        $subtotal_producto = $item['subtotal'];
                        $descuento_producto = $item['descuento'] ?? 0;
                        $total_producto = $item['subtotal_con_descuento'] ?? $subtotal_producto;

                        $permite_decimales = $item['permite_fracciones'] == 1;
                        $unidad_medida = $item['unidad_medida'] ?? 'unidad';

                        if ($permite_decimales) {
                            $cantidad = (float)$item['cantidad'];
                        } else {
                            $cantidad = (int)$item['cantidad'];
                        }

                        $sql_detalle = "
                            INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, subtotal, descuento, total, unidad_medida)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ";
                        $stmt = $conn->prepare($sql_detalle);
                        if ($stmt) {
                            $stmt->bind_param(
                                "iiddddds",
                                $venta_id,
                                $item['id'],
                                $cantidad,
                                $precio_unitario_sin_iva,
                                $subtotal_producto,
                                $descuento_producto,
                                $total_producto,
                                $unidad_medida
                            );

                            if (!$stmt->execute()) {
                                throw new Exception("Error al insertar detalle: " . $stmt->error);
                            }
                            $stmt->close();
                        }

                        $sql_update_stock = "
                            UPDATE producto_sucursal 
                            SET stock = stock - ? 
                            WHERE producto_id = ? AND sucursal_id = ?
                        ";

                        $stmt = $conn->prepare($sql_update_stock);
                        if ($stmt) {
                            $cantidad_a_descontar = $permite_decimales ? (float)$item['cantidad'] : (int)$item['cantidad'];
                            $stmt->bind_param("dii", $cantidad_a_descontar, $item['id'], $_SESSION['sucursal_id']);

                            if (!$stmt->execute()) {
                                error_log("⚠️ ERROR al actualizar stock - Producto ID: {$item['id']}, Nombre: {$item['nombre']}, Error: " . $stmt->error);
                            } else {
                                error_log("✅ STOCK ACTUALIZADO - Producto: {$item['nombre']}, Cantidad descontada: {$cantidad_a_descontar}, Sucursal: {$_SESSION['sucursal_id']}");
                            }
                            $stmt->close();
                        } else {
                            error_log("❌ ERROR al preparar consulta de actualización de stock: " . $conn->error);
                        }
                    }

                    // Actualizar caja
                    $caja_id = $_SESSION['caja_actual_id'] ?? $caja_actual['id'];
                    $sql_update_caja = "
                        UPDATE caja SET 
                            total_ventas = COALESCE(total_ventas, 0) + ?,
                            ventas_efectivo = COALESCE(ventas_efectivo, 0) + ?,
                            ventas_tarjeta = COALESCE(ventas_tarjeta, 0) + ?,
                            ventas_transferencia = COALESCE(ventas_transferencia, 0) + ?
                        WHERE id = ?
                    ";

                    $ventas_efectivo_inc = $metodo_pago == 'efectivo' ? $total : 0;
                    $ventas_tarjeta_inc = $metodo_pago == 'tarjeta' ? $total : 0;
                    $ventas_transferencia_inc = $metodo_pago == 'transferencia' ? $total : 0;

                    error_log("Actualizando caja ID: $caja_id con total: $total");

                    $stmt = $conn->prepare($sql_update_caja);
                    if ($stmt) {
                        $stmt->bind_param(
                            "ddddi",
                            $total,
                            $ventas_efectivo_inc,
                            $ventas_tarjeta_inc,
                            $ventas_transferencia_inc,
                            $caja_id
                        );

                        if (!$stmt->execute()) {
                            throw new Exception("Error al actualizar caja: " . $stmt->error);
                        }
                        $stmt->close();
                    }

                    error_log("✅ Caja actualizada correctamente");

                    $conn->commit();

                    $_SESSION['venta_realizada'] = [
                        'codigo_venta' => $codigo_venta,
                        'total' => $total,
                        'efectivo_recibido' => $efectivo_recibido,
                        'cambio' => $cambio,
                        'metodo_pago' => $metodo_pago,
                        'fecha' => date('Y-m-d H:i:s'),
                        'productos' => $_SESSION['carrito'],
                        'subtotal' => $subtotal_sin_descuento,
                        'descuento' => $descuento_total,
                        'iva' => $iva_total,
                        'iva_porcentaje' => 0,
                        'cliente_id' => $cliente_id,
                        'venta_id' => $venta_id,
                        'plan_empresa' => $empresa_plan,
                        'timbres_disponibles' => $timbres_disponibles
                    ];

                    $_SESSION['carrito'] = [];
                    unset($_SESSION['cliente_venta']);

                    header("Location: caja.php?venta_exitosa=true");
                    exit();
                } else {
                    throw new Exception("Error al preparar consulta de venta");
                }
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Error al procesar la venta: " . $e->getMessage();
                error_log("❌ Error en venta: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Error en caja.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Error del sistema. Por favor contacte al administrador.";
    header("Location: dashboard.php");
    exit();
}

// ========== FUNCIÓN PARA OBTENER LA RUTA DE LA IMAGEN DEL PRODUCTO ==========
function obtenerImagenProducto($producto_id, $conn)
{
    if (empty($producto_id)) {
        return null;
    }

    $sql_imagen = "SELECT ruta_imagen FROM producto_imagenes 
                   WHERE producto_id = ? 
                   ORDER BY es_principal DESC, orden ASC 
                   LIMIT 1";

    $stmt = $conn->prepare($sql_imagen);
    if ($stmt) {
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $imagen_data = $result->fetch_assoc();
            $imagen_producto = $imagen_data['ruta_imagen'];
            $stmt->close();

            $rutas_posibles = [
                $imagen_producto,
                '../' . $imagen_producto,
                '../../' . $imagen_producto,
                'admin/' . $imagen_producto,
                '../admin/' . $imagen_producto,
                'img/productos/' . $imagen_producto,
                'images/productos/' . $imagen_producto,
                'uploads/productos/' . $imagen_producto,
                'assets/productos/' . $imagen_producto,
                'productos/' . $imagen_producto,
                '../img/productos/' . $imagen_producto,
                '../images/productos/' . $imagen_producto,
                '../uploads/productos/' . $imagen_producto,
                '../assets/productos/' . $imagen_producto,
                '../productos/' . $imagen_producto
            ];

            foreach ($rutas_posibles as $ruta) {
                if (file_exists($ruta) && is_file($ruta)) {
                    return $ruta;
                }
            }
        } else {
            $stmt->close();
        }
    }

    if (!empty($producto_id)) {
        $sql_producto = "SELECT imagen FROM productos WHERE id = ?";
        $stmt_producto = $conn->prepare($sql_producto);
        if ($stmt_producto) {
            $stmt_producto->bind_param("i", $producto_id);
            $stmt_producto->execute();
            $result_producto = $stmt_producto->get_result();
            if ($result_producto->num_rows > 0) {
                $producto_data = $result_producto->fetch_assoc();
                $imagen_producto = $producto_data['imagen'];
                $stmt_producto->close();

                if (!empty($imagen_producto)) {
                    $rutas_posibles = [
                        $imagen_producto,
                        '../' . $imagen_producto,
                        '../../' . $imagen_producto,
                        'admin/' . $imagen_producto,
                        '../admin/' . $imagen_producto,
                        'img/productos/' . $imagen_producto,
                        'images/productos/' . $imagen_producto,
                        'uploads/productos/' . $imagen_producto,
                        'assets/productos/' . $imagen_producto,
                        'productos/' . $imagen_producto,
                        '../img/productos/' . $imagen_producto,
                        '../images/productos/' . $imagen_producto,
                        '../uploads/productos/' . $imagen_producto,
                        '../assets/productos/' . $imagen_producto,
                        '../productos/' . $imagen_producto
                    ];

                    foreach ($rutas_posibles as $ruta) {
                        if (file_exists($ruta) && is_file($ruta)) {
                            return $ruta;
                        }
                    }
                }
            } else {
                $stmt_producto->close();
            }
        }
    }

    return null;
}

// Calcular totales del carrito
$carrito_json = json_encode($_SESSION['carrito'] ?? []);
$carrito_count = count($_SESSION['carrito'] ?? []);
$subtotal_carrito = 0;
$descuento_carrito = 0;
$subtotal_con_descuento_carrito = 0;
$iva_carrito = 0;
$total_carrito = 0;

if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
    foreach ($_SESSION['carrito'] as $item) {
        $subtotal_carrito += $item['subtotal'];
        $descuento_carrito += $item['descuento'] ?? 0;
        $subtotal_con_descuento_carrito += $item['subtotal_con_descuento'] ?? $item['subtotal'];
    }
    $iva_carrito = 0;
    $total_carrito = $subtotal_con_descuento_carrito;
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caja - <?php echo htmlspecialchars($empresa_nombre); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* [TODOS TUS ESTILOS CSS EXISTENTES SE MANTIENEN IGUAL] */
        :root {
            --primary-color: <?php echo htmlspecialchars($color_primario); ?>;
            --secondary-color: <?php echo htmlspecialchars($color_secundario); ?>;
            --dark-green: <?php echo htmlspecialchars($color_primario); ?>;
            --light-green: <?php echo htmlspecialchars($color_primario); ?>20;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-green));
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .main-container {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 76px);
            padding: 10px;
            gap: 10px;
        }

        .desktop-layout {
            display: flex;
            flex: 1;
            gap: 10px;
            height: 100%;
        }

        .left-panel {
            flex: 7;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
            position: relative;
        }

        .right-panel {
            flex: 3;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
            gap: 0;
        }

        .left-section {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .left-section.scrollable-cart {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .totals-section-fixed {
            padding: 15px;
            background: white;
            border-top: 2px solid #e9ecef;
            margin-top: auto;
            flex-shrink: 0;
        }

        .cart-table-container {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }

        .right-section {
            padding: 5px;
            border-bottom: 1px solid #e9ecef;
            flex-shrink: 0;
        }

        .right-section:last-child {
            border-bottom: none;
        }

        .right-section.scrollable {
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 0;
        }

        .right-section.scrollable .section-title {
            flex-shrink: 0;
            padding: 15px 15px 10px 15px;
            margin: 0;
            background: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .product-grid-container {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 0 10px 10px 10px;
        }

        .product-grid {
            height: auto;
            min-height: min-content;
            padding: 0;
        }

        .right-section.compact {
            padding: 12px 15px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .mobile-content.scrollable {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            height: 100%;
            overflow: hidden;
        }

        .mobile-content .left-section.scrollable {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
            padding: 0 !important;
            margin: 0 !important;
            height: 100%;
        }

        .mobile-content .scrollable-content {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
            padding: 15px;
            -webkit-overflow-scrolling: touch;
        }

        #mobile-productos .scrollable-content {
            padding: 0 15px 15px 15px;
            display: flex;
            flex-direction: column;
        }

        #mobile-productos .product-grid-container {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
            padding: 10px 0;
            margin-bottom: 10px;
        }

        #mobile-productos .product-grid {
            min-height: min-content;
            height: auto;
        }

        #mobile-carrito .scrollable-content {
            padding: 0 15px 15px 15px;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }

        #mobile-carrito .left-section.scrollable {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        #mobile-carrito .scrollable-content {
            flex: 1;
        }

        .mobile-content.active {
            display: flex !important;
            flex-direction: column;
            height: 100%;
        }

        #mobile-productos .left-section.scrollable,
        #mobile-carrito .left-section.scrollable {
            padding: 0 !important;
            margin: 0 !important;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        #mobile-productos .left-section.compact,
        #mobile-carrito .left-section>.d-flex {
            flex-shrink: 0;
            padding: 15px 15px 0 15px;
            margin: 0;
        }

        .mobile-tabs {
            display: flex;
            background: white;
            border-radius: 15px;
            padding: 10px;
            gap: 5px;
            flex-shrink: 0;
        }

        .search-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 0;
        }

        .search-container {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            font-size: 13px;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            height: 40px;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(39, 174, 96, 0.1);
        }

        .search-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            border-radius: 8px;
            padding: 8px 15px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-color));
            transform: translateY(-1px);
        }

        .search-clear {
            background: #6c757d;
        }

        .search-clear:hover {
            background: #5a6268;
        }

        .search-active {
            background: var(--light-green);
            border: 2px solid var(--primary-color);
        }

        .barcode-search-group {
            display: flex;
            gap: 8px;
            align-items: stretch;
            width: 100%;
        }

        .barcode-input-container {
            flex: 1;
            position: relative;
            display: flex;
        }

        .barcode-input {
            flex: 1;
            font-size: 16px;
            padding: 12px 12px 12px 12px;
            text-align: center;
            letter-spacing: 2px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s ease;
            height: 100%;
        }

        .barcode-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
        }

        .barcode-search-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
        }

        .barcode-search-btn:hover {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-color));
            transform: translateY(-1px);
        }

        @media (max-width: 768px) {
            .barcode-search-group {
                flex-direction: column;
                gap: 10px;
            }

            .barcode-search-btn {
                min-width: 100%;
                height: 50px;
            }

            .barcode-input {
                padding: 12px 12px 12px 12px;
                height: 50px;
            }

            .search-container {
                flex-direction: column;
                gap: 8px;
            }

            .search-input {
                width: 100%;
            }

            .search-btn {
                width: 100%;
                height: 40px;
            }

            .mobile-content {
                height: calc(100vh - 140px) !important;
                min-height: 0;
            }

            .mobile-content.active {
                display: flex !important;
            }

            #mobile-productos .product-grid {
                padding-bottom: 20px;
            }
        }

        .mobile-barcode-group {
            display: flex;
            gap: 8px;
            width: 100%;
        }

        .mobile-barcode-input-container {
            flex: 1;
            position: relative;
        }

        .mobile-barcode-input {
            width: 100%;
            font-size: 16px;
            padding: 12px 12px 12px 12px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            height: 50px;
        }

        .mobile-search-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            border-radius: 10px;
            padding: 12px 15px;
            min-width: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .client-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 0;
        }

        .client-select-container {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .client-select {
            flex: 1;
            font-size: 13px;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            height: 40px;
        }

        .categoria-select-container {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .categoria-select {
            flex: 1;
            font-size: 13px;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            height: 40px;
            background: white;
        }

        .categoria-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(39, 174, 96, 0.1);
        }

        .client-section.categoria-filtrada {
            background: var(--light-green);
            border: 2px solid var(--primary-color);
        }

        .totals-payment-container {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }

        .totals-table-container {
            flex: 1;
        }

        .totals-table {
            width: 100%;
            font-size: 13px;
            margin-bottom: 0;
        }

        .totals-table td {
            padding: 6px 4px;
            border-bottom: 1px solid #e9ecef;
        }

        .totals-table .label {
            font-weight: 600;
            color: #6c757d;
            font-size: 12px;
        }

        .totals-table .value {
            text-align: right;
            font-weight: bold;
            color: #2c3e50;
            font-size: 13px;
        }

        .total-grande {
            font-size: 18px;
            font-weight: 800;
            color: var(--primary-color);
        }

        .payment-button-container {
            flex-shrink: 0;
            width: 200px;
        }

        .btn-pagar-integrado {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            padding: 15px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            height: 100%;
            min-height: 80px;
        }

        .btn-pagar-integrado:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
            background: linear-gradient(135deg, var(--dark-green), var(--primary-color));
        }

        .btn-pagar-integrado:disabled {
            background: #6c757d;
            transform: none;
            box-shadow: none;
            cursor: not-allowed;
        }

        .btn-pagar-integrado .total-amount {
            font-size: 18px;
            font-weight: 800;
        }

        .btn-pagar-integrado .pay-text {
            font-size: 14px;
            font-weight: 600;
        }

        .payment-methods-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }

        .payment-btn {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 12px 15px;
            text-align: left;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 13px;
            margin: 0;
        }

        .payment-btn.active {
            border-color: var(--primary-color);
            background: var(--light-green);
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.2);
        }

        .payment-btn:hover {
            border-color: var(--primary-color);
            transform: translateY(-1px);
        }

        .payment-btn .form-check {
            margin: 0;
            display: flex;
            align-items: center;
        }

        .payment-btn .form-check-input {
            margin-right: 10px;
            margin-top: 0;
        }

        .payment-btn .form-check-label {
            display: flex;
            align-items: center;
            width: 100%;
            margin: 0;
        }

        .efectivo-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 8px;
        }

        .efectivo-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }

        .efectivo-field {
            display: flex;
            flex-direction: column;
        }

        .efectivo-label {
            font-size: 11px;
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 4px;
        }

        .efectivo-input {
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 8px 10px;
            font-weight: bold;
            font-size: 13px;
            background: white !important;
            text-align: center;
            cursor: text !important;
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
            user-select: text !important;
        }

        .efectivo-input:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 2px rgba(39, 174, 96, 0.1) !important;
            background: white !important;
            outline: none;
        }

        .efectivo-input:read-only {
            background: #f8f9fa !important;
            cursor: default !important;
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
        }

        .cambio-input {
            color: var(--primary-color);
            font-weight: 800;
        }

        .numpad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            margin-top: 10px;
        }

        .numpad-btn {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 12px 8px;
            text-align: center;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.3s ease;
            min-height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .numpad-btn:hover {
            background: var(--light-green);
            border-color: var(--primary-color);
            transform: scale(1.05);
        }

        .numpad-clear {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        .numpad-clear:hover {
            background: #c82333;
            border-color: #bd2130;
        }

        .pago-section {
            padding: 15px;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            border-radius: 10px;
            margin-top: 8px;
        }

        .btn-pagar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            padding: 16px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-pagar:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
            background: linear-gradient(135deg, var(--dark-green), var(--primary-color));
        }

        .btn-pagar:disabled {
            background: #6c757d;
            transform: none;
            box-shadow: none;
            cursor: not-allowed;
        }

        .status-badge {
            background: var(--light-green);
            color: var(--dark-green);
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 11px;
        }

        .cart-table {
            width: 100%;
            font-size: 14px;
        }

        .cart-table th {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            font-weight: 600;
            padding: 12px 8px;
            border: none;
        }

        .cart-table td {
            padding: 12px 8px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .quantity-btn {
            width: 28px;
            height: 28px;
            border: 2px solid #e9ecef;
            background: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .quantity-btn:hover {
            border-color: var(--primary-color);
            background: var(--light-green);
        }

        .quantity-input {
            width: 45px;
            text-align: center;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 4px;
            font-size: 13px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            height: auto;
            min-height: min-content;
            padding: 0;
        }

        .product-btn {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            height: fit-content;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .product-btn:hover {
            border-color: var(--primary-color);
            background: var(--light-green);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.2);
        }

        .product-image-container {
            width: 80px;
            height: 80px;
            margin-bottom: 8px;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #6c757d;
            font-size: 24px;
        }

        .product-name {
            font-weight: 700;
            margin-bottom: 6px;
            color: #2c3e50;
            font-size: 13px;
            line-height: 1.3;
            text-align: center;
            width: 100%;
        }

        .product-price {
            color: var(--primary-color);
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .product-price-descuento {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }

        .precio-original {
            text-decoration: line-through;
            color: #6c757d;
            font-size: 12px;
        }

        .precio-con-descuento {
            color: #dc3545;
            font-weight: bold;
            font-size: 14px;
        }

        .descuento-badge {
            background: #dc3545;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: bold;
            margin-top: 2px;
        }

        /* NUEVO: Badge para precio de mayoreo */
        .mayoreo-badge {
            background: #ffc107;
            color: #856404;
            font-size: 9px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: bold;
            margin-top: 2px;
        }

        .mobile-layout {
            display: none;
            flex-direction: column;
            height: 100%;
            gap: 10px;
        }

        .mobile-navbar {
            display: none;
            background: linear-gradient(135deg, var(--primary-color), var(--dark-green));
            padding: 10px 15px;
            color: white;
        }

        .mobile-navbar-brand {
            font-weight: bold;
            font-size: 16px;
        }

        .mobile-tab {
            flex: 1;
            text-align: center;
            padding: 12px 5px;
            border: none;
            background: #f8f9fa;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mobile-tab.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .mobile-content {
            flex: 1;
            display: none;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .mobile-content.active {
            display: flex;
            flex-direction: column;
        }

        .auto-scanner-active {
            border-color: #28a745 !important;
            background-color: #f8fff9 !important;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1) !important;
        }

        .scanner-status {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            margin-top: 5px;
            display: inline-block;
        }

        .scanner-active {
            background: #28a745;
            color: white;
        }

        .scanner-inactive {
            background: #6c757d;
            color: white;
        }

        @media (max-width: 992px) {
            .desktop-layout {
                display: none;
            }

            .mobile-layout {
                display: flex;
            }

            .mobile-navbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .main-navbar {
                display: none;
            }

            .main-container {
                height: calc(100vh - 60px);
                padding: 8px;
            }

            .mobile-content {
                height: calc(100vh - 140px) !important;
                min-height: 400px;
            }
        }

        .scrollable::-webkit-scrollbar {
            width: 6px;
        }

        .scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .scrollable::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 3px;
        }

        .scrollable::-webkit-scrollbar-thumb:hover {
            background: var(--dark-green);
        }

        .product-grid-container::-webkit-scrollbar {
            width: 6px;
        }

        .product-grid-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .product-grid-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 3px;
        }

        .product-grid-container::-webkit-scrollbar-thumb:hover {
            background: var(--dark-green);
        }

        .right-panel .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(39, 174, 96, 0.1);
        }

        .right-panel .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            height: 40px;
            min-width: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .right-panel .btn-primary:hover {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-color));
        }

        .compact-spacing {
            margin-bottom: 8px;
        }

        .no-margin {
            margin: 0;
        }

        .stock-bajo {
            color: #dc3545;
            font-weight: bold;
        }

        .cliente-seleccionado {
            background: var(--light-green);
            border: 2px solid var(--primary-color);
        }

        .product-added {
            background: var(--light-green) !important;
            border-color: var(--primary-color) !important;
            transform: scale(0.95);
            transition: all 0.3s ease;
        }

        .keyboard-active {
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.5) !important;
            border-color: var(--primary-color) !important;
        }

        .floating-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .tipo-venta-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            margin-left: 5px;
        }

        .tipo-unidad {
            background: #17a2b8;
            color: white;
        }

        .tipo-peso {
            background: #28a745;
            color: white;
        }

        .tipo-volumen {
            background: #6f42c1;
            color: white;
        }

        .cantidad-input {
            width: 70px;
            text-align: center;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 4px;
            font-size: 13px;
            font-weight: bold;
        }

        .unidad-medida {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: normal;
        }

        .cantidad-modal .modal-dialog {
            max-width: 400px;
        }

        .cantidad-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cantidad-preset {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 15px;
        }

        .preset-btn {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .preset-btn:hover {
            background: var(--light-green);
            border-color: var(--primary-color);
        }

        .preset-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        @media (max-width: 1400px) {
            .desktop-layout {
                gap: 8px;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
        }

        @media (max-width: 1200px) {
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 8px;
            }

            .cart-table {
                font-size: 13px;
            }

            .cart-table th,
            .cart-table td {
                padding: 10px 6px;
            }

            .totals-payment-container {
                gap: 10px;
            }

            .payment-button-container {
                width: 180px;
            }

            .btn-pagar-integrado {
                padding: 12px;
                min-height: 70px;
            }

            .btn-pagar-integrado .total-amount {
                font-size: 16px;
            }

            .btn-pagar-integrado .pay-text {
                font-size: 13px;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                height: calc(100vh - 60px);
                padding: 8px;
                gap: 8px;
            }

            .mobile-content {
                border-radius: 12px;
                height: calc(100vh - 140px) !important;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 6px;
                padding: 8px;
            }

            .product-btn {
                padding: 10px;
                font-size: 12px;
            }

            .product-image-container {
                width: 60px;
                height: 60px;
                margin-bottom: 6px;
            }

            .product-name {
                font-size: 12px;
                margin-bottom: 4px;
            }

            .product-price {
                font-size: 13px;
            }

            .section-title {
                font-size: 13px;
                margin-bottom: 10px;
            }

            .left-section,
            .right-section {
                padding: 12px;
            }

            .right-section.compact {
                padding: 10px 12px;
            }

            .mobile-tabs {
                padding: 8px;
                border-radius: 12px;
            }

            .mobile-tab {
                font-size: 13px;
                padding: 10px 4px;
            }

            .totals-table {
                font-size: 12px;
            }

            .total-grande {
                font-size: 16px;
            }

            .totals-payment-container {
                flex-direction: column;
                gap: 10px;
            }

            .payment-button-container {
                width: 100%;
            }

            .btn-pagar-integrado {
                min-height: 60px;
                padding: 12px;
                flex-direction: row;
                justify-content: space-between;
            }

            .btn-pagar-integrado .total-amount {
                font-size: 16px;
            }

            .btn-pagar-integrado .pay-text {
                font-size: 14px;
            }

            .efectivo-section {
                padding: 12px;
            }

            .numpad-btn {
                padding: 10px 6px;
                min-height: 40px;
                font-size: 13px;
            }

            .btn-pagar {
                padding: 14px;
                font-size: 15px;
            }

            .barcode-input,
            .mobile-barcode-input {
                font-size: 14px;
                padding: 10px 10px 10px 10px;
            }

            .cart-table {
                min-width: 100%;
                font-size: 12px;
            }

            .cart-table th,
            .cart-table td {
                padding: 8px 4px;
            }

            .quantity-input {
                width: 40px;
                font-size: 12px;
            }

            .quantity-btn {
                width: 25px;
                height: 25px;
                font-size: 11px;
            }

            .mobile-content.active {
                display: flex !important;
                flex-direction: column;
                height: calc(100vh - 140px) !important;
            }

            .mobile-content .scrollable-content {
                padding: 10px;
            }

            #mobile-productos .product-grid {
                padding-bottom: 30px;
            }
        }

        @media (max-width: 576px) {
            .main-container {
                padding: 6px;
                gap: 6px;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
                gap: 5px;
                padding: 6px;
            }

            .product-btn {
                padding: 8px;
            }

            .product-image-container {
                width: 50px;
                height: 50px;
                margin-bottom: 4px;
            }

            .product-name {
                font-size: 11px;
            }

            .product-price {
                font-size: 12px;
            }

            .mobile-tab {
                font-size: 12px;
                padding: 8px 3px;
            }

            .section-title {
                font-size: 12px;
            }

            .left-section,
            .right-section {
                padding: 10px;
            }

            .efectivo-fields {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .efectivo-field {
                grid-column: span 1;
            }

            .numpad {
                grid-template-columns: repeat(3, 1fr);
                gap: 5px;
            }

            .numpad-btn {
                padding: 8px 4px;
                min-height: 35px;
                font-size: 12px;
            }

            .btn-pagar {
                padding: 12px;
                font-size: 14px;
            }

            .client-select {
                font-size: 12px;
                height: 38px;
            }

            .payment-btn {
                padding: 10px 12px;
                font-size: 12px;
            }

            .cart-table {
                font-size: 11px;
            }

            .cart-table th,
            .cart-table td {
                padding: 6px 3px;
            }
        }

        @media (max-width: 400px) {
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }

            .mobile-tab {
                font-size: 11px;
                padding: 8px 2px;
            }

            .section-title {
                font-size: 11px;
            }

            .barcode-input,
            .mobile-barcode-input {
                font-size: 13px;
                padding: 8px 8px 8px 8px;
            }

            .barcode-search-btn,
            .mobile-search-btn {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 992px) and (min-width: 769px) {
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
                gap: 10px;
            }

            .mobile-content {
                border-radius: 14px;
                height: calc(100vh - 150px) !important;
            }

            .section-title {
                font-size: 15px;
            }

            #mobile-productos .product-grid {
                padding-bottom: 40px;
            }
        }

        @media (min-height: 800px) {
            .main-container {
                height: calc(100vh - 80px);
            }

            .product-grid {
                max-height: 60vh;
            }

            .scrollable {
                max-height: 50vh;
            }

            .mobile-content {
                height: calc(100vh - 160px) !important;
            }
        }

        @media (max-height: 600px) {
            .main-container {
                height: calc(100vh - 70px);
            }

            .product-grid {
                max-height: 40vh;
            }

            .scrollable {
                max-height: 35vh;
            }

            .left-section,
            .right-section {
                padding: 10px;
            }

            .section-title {
                margin-bottom: 8px;
            }

            .mobile-content {
                height: calc(100vh - 130px) !important;
            }
        }

        .cart-table-container {
            overflow-x: auto;
            width: 100%;
        }

        .cart-table {
            min-width: 600px;
        }

        @media (max-width: 768px) {
            .cart-table {
                min-width: 100%;
            }
        }

        @media (prefers-contrast: high) {
            .product-btn {
                border-width: 3px;
            }

            .payment-btn.active {
                border-width: 3px;
            }

            .btn-pagar {
                border: 2px solid white;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                transition: none !important;
                animation: none !important;
            }

            .product-btn:hover,
            .payment-btn:hover,
            .numpad-btn:hover,
            .btn-pagar:hover {
                transform: none !important;
            }
        }

        .modal-pago .modal-dialog {
            max-width: 500px;
        }

        .modal-pago .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .modal-pago .modal-header {
            border-radius: 15px 15px 0 0;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--dark-green));
            color: white;
            border-bottom: none;
        }

        .modal-pago .modal-body {
            padding: 20px !important;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-pago .modal-footer {
            border-top: 1px solid #dee2e6;
            padding: 15px 20px;
            border-radius: 0 0 15px 15px;
        }

        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .mobile-navbar-brand img {
            height: 30px;
            width: auto;
            max-width: 80px;
            object-fit: contain;
            border-radius: 3px;
        }

        .search-loading {
            position: relative;
        }

        .search-loading::after {
            content: '';
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: translateY(-50%) rotate(0deg);
            }

            100% {
                transform: translateY(-50%) rotate(360deg);
            }
        }

        .search-results-count {
            font-size: 11px;
            color: #6c757d;
            margin-left: 8px;
        }

        .real-time-active {
            border-color: #28a745 !important;
            background-color: #f8fff9 !important;
        }

        .product-image-cart {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e9ecef;
        }

        .product-image-placeholder-cart {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .product-image-cart {
                width: 40px;
                height: 40px;
            }

            .product-image-placeholder-cart {
                width: 40px;
                height: 40px;
                font-size: 14px;
            }

            .cart-table th:nth-child(1),
            .cart-table td:nth-child(1) {
                width: 15%;
            }

            .cart-table th:nth-child(2),
            .cart-table td:nth-child(2) {
                width: 30%;
            }
        }

        @media (max-width: 768px) {
            #mobile-carrito .product-image-cart {
                width: 70px !important;
                height: 70px !important;
                object-fit: cover;
                border-radius: 10px;
                border: 2px solid #e9ecef;
            }

            #mobile-carrito .product-image-placeholder-cart {
                width: 70px !important;
                height: 70px !important;
                border-radius: 10px;
                background: linear-gradient(135deg, #f8f9fa, #e9ecef);
                border: 2px solid #e9ecef;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #6c757d;
                font-size: 20px !important;
            }

            #mobile-carrito .card-body .row.align-items-center {
                align-items: flex-start !important;
            }

            #mobile-carrito .col-3 {
                width: 25% !important;
                flex: 0 0 25% !important;
                max-width: 25% !important;
            }

            #mobile-carrito .col-9 {
                width: 75% !important;
                flex: 0 0 75% !important;
                max-width: 75% !important;
            }

            #mobile-carrito .card {
                margin-bottom: 12px;
            }

            #mobile-carrito .card-body {
                padding: 15px;
            }

            #mobile-carrito .card-title {
                font-size: 14px;
                line-height: 1.3;
                margin-bottom: 8px;
            }

            #mobile-carrito .card-text {
                font-size: 12px;
            }

            #mobile-carrito .quantity-control {
                margin-top: 8px;
            }

            #mobile-carrito .cantidad-input {
                width: 80px !important;
                font-size: 13px !important;
            }

            #mobile-carrito .unidad-medida {
                font-size: 12px !important;
            }
        }

        @media (max-width: 400px) {
            #mobile-carrito .product-image-cart {
                width: 60px !important;
                height: 60px !important;
            }

            #mobile-carrito .product-image-placeholder-cart {
                width: 60px !important;
                height: 60px !important;
                font-size: 18px !important;
            }

            #mobile-carrito .col-3 {
                width: 30% !important;
                flex: 0 0 30% !important;
                max-width: 30% !important;
            }

            #mobile-carrito .col-9 {
                width: 70% !important;
                flex: 0 0 70% !important;
                max-width: 70% !important;
            }
        }

        @media (max-width: 992px) and (min-width: 769px) {
            #mobile-carrito .product-image-cart {
                width: 80px !important;
                height: 80px !important;
            }

            #mobile-carrito .product-image-placeholder-cart {
                width: 80px !important;
                height: 80px !important;
                font-size: 24px !important;
            }
        }

        #mobile-carrito .product-image-cart {
            width: 40px;
            height: 40px;
        }

        #mobile-carrito .product-image-placeholder-cart {
            width: 40px;
            height: 40px;
            font-size: 14px;
        }

        .actualizando {
            opacity: 0.7;
            pointer-events: none;
        }

        .actualizando td {
            background-color: #f8f9fa !important;
        }

        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }

        .btn-actualizar {
            transition: all 0.3s ease;
        }

        .btn-actualizar:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-eliminar:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .carrito-vacio {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .carrito-vacio i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .cart-table tbody tr,
        #mobile-carrito .card {
            transition: all 0.3s ease;
        }

        .actualizando {
            opacity: 0.7;
            background-color: #f8f9fa !important;
        }

        .actualizando td,
        .actualizando .card-body {
            background-color: #f8f9fa !important;
        }

        .descuento-info {
            font-size: 11px;
            color: #dc3545;
            font-weight: bold;
        }

        .subtotal-descuento {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .subtotal-original {
            text-decoration: line-through;
            color: #6c757d;
            font-size: 11px;
        }

        .subtotal-final {
            color: #dc3545;
            font-weight: bold;
            font-size: 13px;
        }

        .qr-section {
            margin-top: 15px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }

        .qr-container {
            background: white;
            transition: all 0.3s ease;
        }

        .qr-container img {
            transition: transform 0.3s ease;
        }

        .qr-container img:hover {
            transform: scale(1.05);
        }

        #paymentLink {
            display: inline-block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .qr-container img {
                max-width: 200px;
                max-height: 200px;
            }
        }

        @media (max-width: 576px) {
            .qr-container img {
                max-width: 180px;
                max-height: 180px;
            }
        }

        /* Estilos para el modal de edición de precio */
        .precio-edit-modal .modal-dialog {
            max-width: 400px;
        }

        .precio-edit-modal .modal-content {
            border-radius: 15px;
        }

        .precio-edit-modal .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-green));
            color: white;
            border-radius: 15px 15px 0 0;
        }

        .precio-edit-modal .btn-guardar-precio {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
        }

        .precio-edit-modal .btn-guardar-precio:hover {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-color));
        }
    </style>
</head>

<body>
    <!-- Navbar Principal (Desktop) -->
    <nav class="navbar navbar-expand-lg navbar-dark main-navbar">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="dashboard.php">
                <?php if (isset($logo_src_base64) && !empty($logo_src_base64)): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                        class="me-2"
                        style="height: 40px; width: auto; max-width: 120px; object-fit: contain; border-radius: 4px;">
                    <span>
                        <?php echo htmlspecialchars($empresa_nombre); ?>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                        class="me-2"
                        style="height: 40px; width: auto; max-width: 120px; object-fit: contain; border-radius: 4px;">
                    <span>
                        <?php echo htmlspecialchars($empresa_nombre); ?>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($empresa_nombre); ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto align-items-center">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario'); ?>
                </span>
                <span class="status-badge me-3">
                    <i class="fas fa-circle me-1"></i>Caja Abierta
                </span>
                <a href="dashboard.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <!-- Navbar Móvil -->
    <div class="mobile-navbar">
        <div class="mobile-navbar-brand d-flex align-items-center">
            <?php if (isset($logo_src_base64) && !empty($logo_src_base64)): ?>
                <img src="<?php echo $logo_src_base64; ?>"
                    alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                    class="me-2">
                <span>
                    <?php echo htmlspecialchars($empresa_nombre); ?>
                </span>
            <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                    alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                    class="me-2">
                <span>
                    <?php echo htmlspecialchars($empresa_nombre); ?>
                </span>
            <?php else: ?>
                <i class="fas fa-cash-register me-2"></i>
                <span>
                    <?php echo htmlspecialchars($empresa_nombre); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center">
            <span class="status-badge me-2">
                <i class="fas fa-circle me-1"></i>Caja Abierta
            </span>
            <a href="dashboard.php" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>
    </div>

    <!-- Mensajes de Alerta con Auto-ocultamiento -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show m-2 auto-hide-alert" role="alert" data-auto-hide="2000">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show m-2 auto-hide-alert" role="alert" data-auto-hide="2000">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Modal para cantidad de productos por peso/volumen -->
    <div class="modal fade cantidad-modal" id="cantidadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cantidadModalTitle">Seleccionar Cantidad</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="cantidadForm">
                        <input type="hidden" id="productoIdModal" name="producto_id">
                        <div class="mb-3">
                            <label class="form-label" id="cantidadLabel">Cantidad</label>
                            <div class="cantidad-input-group">
                                <input type="number" class="form-control" id="cantidadInput" name="cantidad"
                                    step="0.001" min="0.001" value="1.000" required>
                                <span class="unidad-medida" id="unidadMedidaText">kg</span>
                            </div>
                            <small class="form-text text-muted" id="cantidadHelp">Ingrese la cantidad deseada</small>
                        </div>
                        <div class="cantidad-preset" id="presetContainer">
                            <!-- Los botones de cantidad predefinida se generarán con JavaScript -->
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnAgregarConCantidad">Agregar al Carrito</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para editar descuento -->
    <div class="modal fade" id="editarDescuentoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-tag me-2"></i>Editar Descuento
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Producto:</label>
                        <p id="productoNombreEditar" class="mb-2 text-primary fw-bold"></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Precio unitario:</label>
                        <p id="precioUnitarioEditar" class="mb-2">$0.00</p>
                    </div>

                    <div class="mb-3">
                        <label for="porcentajeDescuento" class="form-label fw-bold">Porcentaje de Descuento (%)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="porcentajeDescuento"
                                step="0.01" min="0" max="100" value="0">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">Ingrese el porcentaje de descuento (0-100%)</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Vista previa:</label>
                        <div class="bg-light p-3 rounded">
                            <div class="row">
                                <div class="col-6">
                                    <span class="text-muted">Subtotal:</span>
                                </div>
                                <div class="col-6 text-end">
                                    <span id="previewSubtotal" class="fw-bold">$0.00</span>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <span class="text-muted">Descuento:</span>
                                </div>
                                <div class="col-6 text-end">
                                    <span id="previewDescuento" class="text-danger fw-bold">-$0.00</span>
                                </div>
                            </div>
                            <div class="row mt-2 border-top pt-2">
                                <div class="col-6">
                                    <span class="fw-bold">Total:</span>
                                </div>
                                <div class="col-6 text-end">
                                    <span id="previewTotal" class="fw-bold text-success">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning" id="descuentoGuardarAdvertencia" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Este descuento se guardará en la base de datos para futuras ventas.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" id="btnGuardarDescuento">
                        <i class="fas fa-save me-1"></i>Guardar Descuento
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para editar precio unitario -->
    <div class="modal fade precio-edit-modal" id="editarPrecioModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-dollar-sign me-2"></i>Editar Precio Unitario
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Producto:</label>
                        <p id="precioProductoNombre" class="mb-2 text-primary fw-bold"></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Cantidad:</label>
                        <p id="precioProductoCantidad" class="mb-2">0</p>
                    </div>

                    <div class="mb-3">
                        <label for="nuevoPrecio" class="form-label fw-bold">Nuevo Precio Unitario ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="nuevoPrecio"
                                step="0.01" min="0.01" value="0">
                        </div>
                        <small class="text-muted">Ingrese el nuevo precio unitario para este producto</small>
                    </div>

                    <div class="alert alert-info" id="precioPreviewInfo">
                        <i class="fas fa-calculator me-2"></i>
                        <strong>Vista previa:</strong><br>
                        Subtotal actual: <span id="precioSubtotalActual">$0.00</span><br>
                        Nuevo subtotal: <span id="precioNuevoSubtotal">$0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-guardar-precio" id="btnGuardarPrecio">
                        <i class="fas fa-save me-1"></i>Actualizar Precio
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Pago -->
    <div class="modal fade modal-pago" id="pagoModal" tabindex="-1" aria-labelledby="pagoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="pagoModalLabel">
                        <i class="fas fa-cash-register me-2"></i>Confirmar Pago
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <h6 class="section-title">
                            <i class="fas fa-receipt me-2"></i>Resumen de Venta
                        </h6>

                        <table class="totals-table">
                            <tr>
                                <td class="label">Subtotal:</td>
                                <td class="value" id="modal-subtotal">$<?php echo number_format($subtotal_carrito, 2); ?></td>
                            </tr>
                            <tr>
                                <td class="label">Descuento:</td>
                                <td class="value text-danger" id="modal-descuento">-$<?php echo number_format($descuento_carrito, 2); ?></td>
                            </tr>
                            <tr>
                                <td class="label">Subtotal con Descuento:</td>
                                <td class="value" id="modal-subtotal-con-descuento">$<?php echo number_format($subtotal_con_descuento_carrito, 2); ?></td>
                            </tr>
                            <tr style="display: none;">
                                <td class="label">IVA (0%):</td>
                                <td class="value">$0.00</td>
                            </tr>
                            <tr style="border-top: 2px solid #dee2e6;">
                                <td class="label"><strong>TOTAL A PAGAR:</strong></td>
                                <td class="value total-grande" id="modal-total">$<?php echo number_format($total_carrito, 2); ?></td>
                            </tr>
                        </table>
                    </div>

                    <div class="mb-4">
                        <h6 class="section-title">
                            <i class="fas fa-credit-card me-2"></i>Método de Pago
                        </h6>

                        <div class="payment-methods-grid">
                            <div class="payment-btn active" data-method="efectivo">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="modal_metodo_pago"
                                        value="efectivo" id="modal-efectivo" checked required>
                                    <label class="form-check-label" for="modal-efectivo">
                                        <i class="fas fa-money-bill-wave me-2"></i>Efectivo
                                    </label>
                                </div>
                            </div>

                            <div class="payment-btn" data-method="tarjeta">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="modal_metodo_pago"
                                        value="tarjeta" id="modal-tarjeta" required>
                                    <label class="form-check-label" for="modal-tarjeta">
                                        <i class="fas fa-credit-card me-2"></i>Tarjeta / QR
                                    </label>
                                </div>
                            </div>

                            <div class="payment-btn" data-method="transferencia">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="modal_metodo_pago"
                                        value="transferencia" id="modal-transferencia" required>
                                    <label class="form-check-label" for="modal-transferencia">
                                        <i class="fas fa-university me-2"></i>Transferencia
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="efectivo-section">
                        <h6 class="section-title">
                            <i class="fas fa-money-bill-wave me-2"></i>Pago en Efectivo
                        </h6>

                        <div class="efectivo-fields">
                            <div class="efectivo-field">
                                <span class="efectivo-label">Total a Pagar</span>
                                <input type="text" class="efectivo-input text-success fw-bold"
                                    id="modal-total-pagar"
                                    value="$<?php echo number_format($total_carrito, 2); ?>"
                                    readonly
                                    style="font-size: 13px; font-weight: bold;">
                            </div>
                            <div class="efectivo-field">
                                <span class="efectivo-label">Efectivo Recibido</span>
                                <input type="text" class="efectivo-input fw-bold"
                                    id="modal-efectivo-recibido"
                                    value=""
                                    placeholder="0.00"
                                    onfocus="this.select()"
                                    style="font-size: 13px; font-weight: bold;">
                            </div>
                        </div>
                        <div class="efectivo-fields">
                            <div class="efectivo-field" style="grid-column: span 2;">
                                <span class="efectivo-label">Cambio</span>
                                <input type="text" class="efectivo-input cambio-input fw-bold"
                                    id="modal-cambio"
                                    value="$0.00"
                                    readonly
                                    style="font-size: 13px; font-weight: bold; color: var(--primary-color);">
                            </div>
                        </div>

                        <div class="numpad">
                            <button type="button" class="numpad-btn" data-value="1">1</button>
                            <button type="button" class="numpad-btn" data-value="2">2</button>
                            <button type="button" class="numpad-btn" data-value="3">3</button>
                            <button type="button" class="numpad-btn" data-value="4">4</button>
                            <button type="button" class="numpad-btn" data-value="5">5</button>
                            <button type="button" class="numpad-btn" data-value="6">6</button>
                            <button type="button" class="numpad-btn" data-value="7">7</button>
                            <button type="button" class="numpad-btn" data-value="8">8</button>
                            <button type="button" class="numpad-btn" data-value="9">9</button>
                            <button type="button" class="numpad-btn" data-value=".">.</button>
                            <button type="button" class="numpad-btn" data-value="0">0</button>
                            <button type="button" class="numpad-btn numpad-clear" data-value="clear">
                                <i class="fas fa-backspace"></i>
                            </button>
                        </div>
                    </div>

                    <div class="qr-section" id="qrSection" style="display: none;">
                        <h6 class="section-title">
                            <i class="fas fa-qrcode me-2"></i>Pago con Lector Electrónico
                        </h6>
                        <div class="qr-container text-center p-4" style="background: white; border-radius: 10px; border: 2px dashed #e9ecef;">
                            <div id="qrReferenciaContainer" class="mb-4">
                                <h6 class="text-muted mb-2">Código de referencia:</h6>
                                <div id="qrReferenciaContent">
                                    <img id="qrImage"
                                        src=""
                                        alt="Código QR de pago"
                                        style="max-width: 250px; max-height: 250px; border: 1px solid #dee2e6; padding: 10px; border-radius: 10px; margin-bottom: 15px;">
                                    <p class="mt-3 fw-bold">
                                        <span class="badge bg-primary p-3" id="qrCodeBadge" style="font-size: 18px; letter-spacing: 2px;">
                                        </span>
                                    </p>
                                    <p class="text-muted small">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Escanea este código QR o ingresa la referencia en el lector
                                    </p>
                                </div>
                            </div>

                            <div class="mt-3">
                                <p class="mb-1 fw-bold">Total a pagar: <span id="qrTotalAmount" class="text-success">$0.00</span></p>
                                <p class="text-muted small mb-2">Escanea con el lector o ingresa la referencia manualmente</p>

                                <div class="d-flex justify-content-center gap-2 mt-3">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="refreshQrBtn">
                                        <i class="fas fa-sync-alt me-1"></i>Generar nuevo QR
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="copyReferenceBtn">
                                        <i class="fas fa-copy me-1"></i>Copiar referencia
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="qr-section" id="qrLinkSection" style="display: none;">
                        <h6 class="section-title">
                            <i class="fas fa-link me-2"></i>Link de Pago
                        </h6>
                        <div class="qr-container text-center p-4" style="background: white; border-radius: 10px; border: 2px dashed #e9ecef;">
                            <div id="qrLinkContainer" class="mb-4">
                                <h6 class="text-muted mb-2">Código QR del link de pago:</h6>
                                <div id="qrLinkContent">
                                    <img id="qrLinkImage"
                                        src=""
                                        alt="Código QR del link de pago"
                                        style="max-width: 250px; max-height: 250px; border: 1px solid #dee2e6; padding: 10px; border-radius: 10px; margin-bottom: 15px;">

                                    <div class="mt-3 p-3 bg-light rounded">
                                        <p class="fw-bold mb-2">Link de pago:</p>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <a href="" id="paymentLinkElement" target="_blank"
                                                class="text-primary text-break" style="font-size: 14px; word-break: break-all;">
                                                Cargando link...
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="copiarLinkPago(event)" title="Copiar link">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <p class="mb-1 fw-bold">Total a pagar:
                                        <span id="qrLinkTotalAmount" class="text-success">$0.00</span>
                                    </p>

                                    <p class="text-muted small mb-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Escanea el código QR o haz clic en el link para realizar el pago
                                    </p>

                                    <div class="d-flex justify-content-center gap-2 mt-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="refreshLinkQrBtn">
                                            <i class="fas fa-sync-alt me-1"></i>Generar nuevo link
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="copyLinkReferenceBtn">
                                            <i class="fas fa-copy me-1"></i>Copiar referencia
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="spei-section" id="speiSection" style="display: none;">
                        <div style="margin-top: 20px; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 15px; color: white; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                            <div style="display: flex; align-items: center; margin-bottom: 15px;">
                                <div style="background: rgba(255,255,255,0.2); padding: 10px; border-radius: 50%; margin-right: 15px;">
                                    <i class="fas fa-university" style="font-size: 24px;"></i>
                                </div>
                                <h5 style="margin: 0; font-weight: bold; font-size: 18px; color: white;">Pago por Transferencia SPEI</h5>
                            </div>

                            <div style="background: rgba(255,255,255,0.1); padding: 20px; border-radius: 12px; margin-bottom: 15px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                    <span style="font-size: 14px; opacity: 0.9; color: rgba(255,255,255,0.9);">CLABE Interbancaria:</span>
                                    <span id="clabeDisplay" style="font-size: 22px; font-weight: bold; font-family: monospace; letter-spacing: 2px; color: white; background: rgba(0,0,0,0.2); padding: 8px 15px; border-radius: 8px;">
                                        <span class="spinner-border spinner-border-sm me-2" style="width: 1rem; height: 1rem;"></span>Generando...
                                    </span>
                                </div>
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="button" class="btn" onclick="copiarCLABE(event)" style="flex: 1; background: white; color: #667eea; font-weight: bold; border: none; padding: 12px; border-radius: 8px; transition: all 0.3s ease;">
                                    <i class="fas fa-copy me-2"></i>Copiar CLABE
                                </button>
                            </div>

                            <div style="margin-top: 15px; font-size: 12px; opacity: 0.8; text-align: center;">
                                <i class="fas fa-info-circle me-1"></i>
                                La CLABE se actualiza automáticamente. El pago será verificado en línea.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="formPagoModal" class="w-100">
                        <input type="hidden" name="metodo_pago" id="modal-metodoPagoInput" value="efectivo">
                        <input type="hidden" name="efectivo_recibido" id="modal-efectivoRecibidoHidden" value="0">
                        <input type="hidden" name="cambio" id="modal-cambioHidden" value="0">
                        <input type="hidden" name="descuento_total" id="modal-descuentoTotal" value="<?php echo $descuento_carrito; ?>">
                        <button type="submit" name="procesar_pago" class="btn btn-pagar w-100" id="modal-btnPagar">
                            <i class="fas fa-check-circle me-2"></i>
                            CONFIRMAR PAGO - $<?php echo number_format($total_carrito, 2); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Cliente -->
    <div class="modal fade" id="clienteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nuevo Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="clienteForm">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="formAction" value="crear">
                        <input type="hidden" name="id" id="clienteId">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombre del Cliente *</label>
                                <input type="text" class="form-control" name="nombre" id="nombre" required
                                    placeholder="Nombre completo del cliente">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">RFC</label>
                                <input type="text" class="form-control" name="rfc" id="rfc"
                                    placeholder="RFC del cliente">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="email"
                                    placeholder="Correo electrónico">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="tel" class="form-control" name="telefono" id="telefono"
                                    placeholder="Número de teléfono">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Dirección</label>
                            <textarea class="form-control" name="direccion" id="direccion" rows="3"
                                placeholder="Dirección completa del cliente"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Guardar Cliente</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="main-container">
        <!-- Layout Desktop -->
        <div class="desktop-layout">
            <!-- Left Panel -->
            <div class="left-panel">
                <div class="left-section">
                    <div class="section-title">
                        <i class="fas fa-user me-2"></i>Cliente
                        <?php if (isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta']): ?>
                            <span class="badge bg-success ms-2">Seleccionado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta'] ? 'cliente-seleccionado' : ''; ?>">
                        <div class="client-select-container" id="clienteContainer">
                            <select name="cliente_id" class="form-select client-select" id="clienteSelect">
                                <option value="">Cliente General</option>
                                <?php
                                if ($clientes) {
                                    $clientes->data_seek(0);
                                    while ($cliente = $clientes->fetch_assoc()):
                                ?>
                                        <option value="<?php echo $cliente['id']; ?>"
                                            <?php echo ($_SESSION['cliente_venta'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                        </option>
                                <?php
                                    endwhile;
                                }
                                ?>
                            </select>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clienteModal">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="left-section scrollable-cart">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="section-title">
                            <i class="fas fa-shopping-cart me-2"></i>Detalles de Venta
                            <?php if (!empty($_SESSION['carrito'])): ?>
                                <span class="badge bg-primary ms-2"><?php echo count($_SESSION['carrito']); ?> productos</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($_SESSION['carrito'])): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnVaciarCarrito">
                                <i class="fas fa-trash me-1"></i>Vaciar Todo
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="cart-table-container">
                        <table class="cart-table">
                            <thead>
                                <tr>
                                    <th width="8%">IMAGEN</th>
                                    <th width="22%">PRODUCTO</th>
                                    <th width="12%">CANT.</th>
                                    <th width="12%">P. UNIT.</th>
                                    <th width="12%">DESCUENTO</th>
                                    <th width="12%">TOTAL</th>
                                    <th width="10%"></th>
                                </tr>
                            </thead>
                            <tbody id="carrito-body">
                                <?php if (empty($_SESSION['carrito'])): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                            <br>
                                            <span class="text-muted">Carrito vacío - Agregue productos para comenzar</span>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($_SESSION['carrito'] as $index => $item): ?>
                                        <?php
                                        $imagen_path = obtenerImagenProducto($item['id'], $conn);
                                        $imagen_src = $imagen_path ? $imagen_path : '';
                                        $descuento_producto = isset($item['descuento']) ? floatval($item['descuento']) : 0;
                                        $descuento_porcentaje = isset($item['descuento_porcentaje']) ? floatval($item['descuento_porcentaje']) : 0;
                                        $subtotal_con_descuento = isset($item['subtotal_con_descuento']) ? floatval($item['subtotal_con_descuento']) : floatval($item['subtotal']);
                                        $tiene_descuento = $descuento_producto > 0;
                                        $tiene_precio_mayoreo = isset($item['tiene_precio_mayoreo']) && $item['tiene_precio_mayoreo'] === true;
                                        $precio_base = isset($item['precio_base']) ? floatval($item['precio_base']) : floatval($item['precio']);

                                        $cantidad_raw = $item['cantidad'];
                                        $permite_decimales = $item['permite_fracciones'] == 1;

                                        if ($permite_decimales) {
                                            $cantidad_mostrar = number_format((float)$cantidad_raw, 3, '.', '');
                                            $step = '0.001';
                                            $min = '0.001';
                                            $input_class = 'cantidad-input';
                                            $input_width = '80px';
                                            $show_buttons = false;
                                            $unidad_text = isset($item['unidad_medida']) ? $item['unidad_medida'] : '';
                                        } else {
                                            $cantidad_mostrar = (int)$cantidad_raw;
                                            $step = '1';
                                            $min = '1';
                                            $input_class = 'quantity-input';
                                            $input_width = '60px';
                                            $show_buttons = true;
                                            $unidad_text = '';
                                        }
                                        ?>
                                        <tr data-index="<?php echo $index; ?>">
                                            <td width="8%">
                                                <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                    <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                        alt="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                        class="product-image-cart"
                                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="product-image-placeholder-cart" style="display: none;">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-image-placeholder-cart">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td width="22%">
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['nombre']); ?></div>
                                                <small class="text-muted">Código: <?php echo htmlspecialchars($item['codigo']); ?></small>
                                                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                                    <?php if ($item['permite_fracciones'] == 1): ?>
                                                        <div>
                                                            <span class="badge tipo-venta-badge tipo-peso">
                                                                <?php echo ucfirst($item['unidad_medida']); ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($tiene_precio_mayoreo): ?>
                                                        <div>
                                                            <span class="badge mayoreo-badge">
                                                                <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio" 
                                                            data-index="<?php echo $index; ?>"
                                                            data-producto-id="<?php echo $item['id']; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                            data-cantidad="<?php echo $cantidad_raw; ?>"
                                                            data-precio-actual="<?php echo floatval($item['precio']); ?>">
                                                        <i class="fas fa-edit me-1"></i>Editar Precio
                                                    </button>
                                                </div>
                                             </div>
                                            <td width="12%">
                                                <div class="quantity-control">
                                                    <?php if ($show_buttons): ?>
                                                        <button type="button" class="quantity-btn decrease" data-index="<?php echo $index; ?>">-</button>
                                                    <?php endif; ?>
                                                    <input type="number"
                                                        name="cantidad"
                                                        value="<?php echo $cantidad_mostrar; ?>"
                                                        min="<?php echo $min; ?>"
                                                        step="<?php echo $step; ?>"
                                                        class="<?php echo $input_class; ?>"
                                                        data-index="<?php echo $index; ?>"
                                                        style="width: <?php echo $input_width; ?>;">
                                                    <?php if ($show_buttons): ?>
                                                        <button type="button" class="quantity-btn increase" data-index="<?php echo $index; ?>">+</button>
                                                    <?php else: ?>
                                                        <span class="unidad-medida ms-1"><?php echo $item['unidad_medida']; ?></span>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar" data-index="<?php echo $index; ?>">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="fw-bold text-success precio-unitario" data-index="<?php echo $index; ?>">
                                                <?php if ($tiene_precio_mayoreo): ?>
                                                    <div class="d-flex flex-column">
                                                        <span class="text-muted small" style="text-decoration: line-through;">$<?php echo number_format($precio_base, 2); ?></span>
                                                        <span>$<?php echo number_format(floatval($item['precio']), 2); ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    $<?php echo number_format(floatval($item['precio']), 2); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td width="12%">
                                                <div class="descuento-control">
                                                    <div class="descuento-info d-flex align-items-center gap-2">
                                                        <?php if ($tiene_descuento): ?>
                                                            <span class="badge bg-danger">-<?php echo number_format($descuento_porcentaje, 0); ?>%</span>
                                                            <span class="small text-muted">-$<?php echo number_format($descuento_producto, 2); ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">0%</span>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento"
                                                            data-index="<?php echo $index; ?>"
                                                            data-producto-id="<?php echo $item['id']; ?>"
                                                            data-descuento-actual="<?php echo $descuento_porcentaje; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="subtotal-descuento" data-index="<?php echo $index; ?>">
                                                <?php if ($tiene_descuento): ?>
                                                    <span class="subtotal-original">$<?php echo number_format(floatval($item['subtotal']), 2); ?></span>
                                                    <span class="subtotal-final">$<?php echo number_format($subtotal_con_descuento, 2); ?></span>
                                                <?php else: ?>
                                                    <span class="fw-bold text-primary">$<?php echo number_format(floatval($item['subtotal']), 2); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td width="10%">
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-eliminar" data-index="<?php echo $index; ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="totals-section-fixed">
                    <div class="section-title">
                        <i class="fas fa-receipt me-2"></i>Resumen y Pago
                    </div>

                    <div class="totals-payment-container">
                        <div class="totals-table-container">
                            <table class="totals-table">
                                <tr>
                                    <td class="label">Total:</td>
                                    <td class="value" id="subtotal-display">$<?php echo number_format($subtotal_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Descuento:</td>
                                    <td class="value text-danger" id="descuento-display">-$<?php echo number_format($descuento_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Total con Descuento:</td>
                                    <td class="value" id="subtotal-con-descuento-display">$<?php echo number_format($subtotal_con_descuento_carrito, 2); ?></td>
                                </tr>
                                <tr style="display: none;">
                                    <td class="label">IVA (0%):</td>
                                    <td class="value">$0.00</span></td>
                                </tr>
                                <tr style="border-top: 2px solid #dee2e6;">
                                    <td class="label"><strong>TOTAL:</strong></td>
                                    <td class="value total-grande" id="total-display">$<?php echo number_format($total_carrito, 2); ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="payment-button-container">
                            <button type="button" class="btn btn-pagar-integrado" id="btnAbrirModalPago"
                                <?php echo empty($_SESSION['carrito']) ? 'disabled' : ''; ?>>
                                <div class="pay-text">
                                    <i class="fas fa-cash-register me-1"></i>PAGAR
                                </div>
                                <div class="total-amount" id="total-pagar-display">
                                    $<?php echo number_format($total_carrito, 2); ?>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel -->
            <div class="right-panel">
                <div class="right-section compact">
                    <div class="section-title">
                        <i class="fas fa-search me-2"></i>Buscar Producto
                        <?php if (!empty($busqueda_nombre)): ?>
                            <span class="badge bg-primary ms-2">Búsqueda activa</span>
                        <?php endif; ?>
                        <span class="badge bg-<?php echo $empresa_plan === 'premium' ? 'warning' : 'info'; ?> ms-2">
                            <?php echo strtoupper($empresa_plan); ?>
                        </span>
                    </div>
                    <div class="search-section <?php echo !empty($busqueda_nombre) ? 'search-active' : ''; ?>" id="searchSection">
                        <div class="search-container">
                            <input type="text"
                                name="busqueda_nombre"
                                class="form-control search-input"
                                placeholder="🔍 Escriba el nombre del producto..."
                                value="<?php echo htmlspecialchars($busqueda_nombre); ?>"
                                id="searchInput"
                                autocomplete="off">
                            <button type="button" class="search-btn" id="btnClearSearch" title="Limpiar búsqueda" style="display: none;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted search-results-count" id="searchResultsCount">
                                <?php if (!empty($productos)): ?>
                                    Mostrando <?php echo count($productos); ?> productos
                                <?php else: ?>
                                    Escriba para buscar productos
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <div class="right-section compact">
                    <div class="section-title">
                        <i class="fas fa-tags me-2"></i>Filtrar por Categoría
                        <?php if ($categoria_seleccionada): ?>
                            <span class="badge bg-primary ms-2">Filtrado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo $categoria_seleccionada ? 'categoria-filtrada' : ''; ?>">
                        <form method="GET" class="categoria-select-container" id="categoriaForm">
                            <select name="categoria_id" class="form-select categoria-select" id="categoriaSelect">
                                <option value="">Todas las Categorías</option>
                                <?php
                                $sql_categorias_select = "
                                    SELECT c.*, COUNT(p.id) as producto_count
                                    FROM categorias c
                                    LEFT JOIN productos p ON c.id = p.categoria_id 
                                        AND p.activo = 1
                                    LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
                                        AND (COALESCE(ps.stock, 0) > 0)
                                    WHERE c.activo = 1
                                    GROUP BY c.id
                                    ORDER BY c.nombre
                                ";

                                $stmt_cat = $conn->prepare($sql_categorias_select);
                                if ($stmt_cat) {
                                    $stmt_cat->bind_param("i", $_SESSION['sucursal_id']);
                                    $stmt_cat->execute();
                                    $categorias_select = $stmt_cat->get_result();

                                    if ($categorias_select && $categorias_select->num_rows > 0) {
                                        $categorias_select->data_seek(0);
                                        while ($categoria = $categorias_select->fetch_assoc()):
                                            $producto_count = $categoria['producto_count'];
                                ?>
                                            <option value="<?php echo $categoria['id']; ?>"
                                                <?php echo $categoria_seleccionada == $categoria['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                (<?php echo $producto_count; ?> productos)
                                            </option>
                                <?php
                                        endwhile;
                                    }
                                    $stmt_cat->close();
                                }
                                ?>
                            </select>
                            <?php if ($categoria_seleccionada): ?>
                                <a href="caja.php<?php echo !empty($busqueda_nombre) ? '?busqueda_nombre=' . urlencode($busqueda_nombre) : ''; ?>"
                                    class="btn btn-outline-danger"
                                    title="Quitar filtro">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="right-section scrollable">
                    <div class="section-title">
                        <i class="fas fa-boxes me-2"></i>Productos Disponibles en Sucursal
                        <?php if (!empty($productos)): ?>
                            <span class="badge bg-primary ms-2" id="productCount"><?php echo count($productos); ?> productos</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-2" id="productCount">0 productos</span>
                        <?php endif; ?>
                        <?php if ($categoria_seleccionada || !empty($busqueda_nombre)): ?>
                            <small class="text-muted ms-2" id="filterInfo">
                                (
                                <?php
                                $filtros = [];
                                if ($categoria_seleccionada) {
                                    if ($categorias_con_count) {
                                        $categorias_con_count->data_seek(0);
                                        while ($cat = $categorias_con_count->fetch_assoc()) {
                                            if ($cat['id'] == $categoria_seleccionada) {
                                                $filtros[] = "Categoría: " . htmlspecialchars($cat['nombre']);
                                                break;
                                            }
                                        }
                                    }
                                }
                                if (!empty($busqueda_nombre)) {
                                    $filtros[] = "Búsqueda: \"" . htmlspecialchars($busqueda_nombre) . "\"";
                                }
                                echo implode(', ', $filtros);
                                ?>
                                )
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="product-grid-container">
                        <div class="product-grid" id="productGrid">
                            <?php if (empty($productos)): ?>
                                <div class="col-12 text-center py-4" id="emptyProductsMessage">
                                    <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">
                                        <?php if ($categoria_seleccionada || !empty($busqueda_nombre)): ?>
                                            No se encontraron productos con stock que coincidan con los filtros
                                        <?php else: ?>
                                            No se encontraron productos con stock en esta sucursal
                                        <?php endif; ?>
                                    </p>
                                    <small class="text-muted">
                                        <?php if ($categoria_seleccionada): ?>
                                            Categoría ID: <?php echo $categoria_seleccionada; ?><br>
                                        <?php endif; ?>
                                        <?php if (!empty($busqueda_nombre)): ?>
                                            Búsqueda: "<?php echo htmlspecialchars($busqueda_nombre); ?>"<br>
                                        <?php endif; ?>
                                        Sucursal ID: <?php echo $_SESSION['sucursal_id'] ?? 'No definido'; ?>
                                    </small>
                                </div>
                            <?php else: ?>
                                <?php foreach ($productos as $producto): ?>
                                    <?php
                                    $imagen_path = obtenerImagenProducto($producto['id'], $conn);
                                    $imagen_src = $imagen_path ? $imagen_path : '';
                                    $tiene_descuento = $producto['descuento'] > 0;
                                    $precio_con_descuento = $producto['precio_sin_iva'] - ($producto['precio_sin_iva'] * $producto['descuento'] / 100);
                                    
                                    // Verificar si el producto tiene precios de mayoreo
                                    $sql_check_mayoreo = "SELECT COUNT(*) as tiene_mayoreo FROM producto_precios_mayoreo WHERE producto_id = ? AND activo = 1";
                                    $stmt_mayoreo_check = $conn->prepare($sql_check_mayoreo);
                                    $tiene_mayoreo = false;
                                    if ($stmt_mayoreo_check) {
                                        $stmt_mayoreo_check->bind_param("i", $producto['id']);
                                        $stmt_mayoreo_check->execute();
                                        $result_mayoreo_check = $stmt_mayoreo_check->get_result();
                                        if ($result_mayoreo_check->num_rows > 0) {
                                            $row_mayoreo = $result_mayoreo_check->fetch_assoc();
                                            $tiene_mayoreo = $row_mayoreo['tiene_mayoreo'] > 0;
                                        }
                                        $stmt_mayoreo_check->close();
                                    }
                                    ?>
                                    <div class="product-btn"
                                        onclick="agregarProducto(
                                            <?php echo $producto['id']; ?>, 
                                            '<?php echo $producto['permite_fracciones']; ?>', 
                                            '<?php echo addslashes($producto['unidad_medida']); ?>', 
                                            this)">
                                        <div class="product-image-container">
                                            <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                    alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                    class="product-image"
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="product-image-placeholder" style="display: none;">
                                                    <i class="fas fa-box"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="product-image-placeholder">
                                                    <i class="fas fa-box"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-name"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                        <div class="product-price-descuento">
                                            <?php if ($tiene_descuento): ?>
                                                <span class="precio-original">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                                <span class="precio-con-descuento">$<?php echo number_format($precio_con_descuento, 2); ?></span>
                                                <span class="descuento-badge">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                            <?php else: ?>
                                                <span class="product-price">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($tiene_mayoreo): ?>
                                            <div class="mt-1">
                                                <span class="badge mayoreo-badge">
                                                    <i class="fas fa-tags me-1"></i>Precios por Mayoreo
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($producto['permite_fracciones'] == 1): ?>
                                            <div class="unidad-medida">
                                                <span class="badge tipo-venta-badge tipo-peso">
                                                    <?php echo ucfirst($producto['unidad_medida']); ?>
                                                </span>
                                                por <?php echo $producto['unidad_medida']; ?>
                                            </div>
                                        <?php endif; ?>
                                        <small class="text-muted d-block mt-2">
                                            <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($producto['categoria_nombre']); ?>
                                        </small>
                                        <small class="text-muted d-block mt-1">
                                            <i class="fas fa-store me-1"></i>Stock Sucursal:
                                            <?php
                                            $stock_sucursal = (float)($producto['stock_sucursal'] ?? 0);
                                            $permite_fracciones = (int)($producto['permite_fracciones'] ?? 0);
                                            $unidad_medida = strtolower(trim($producto['unidad_medida'] ?? ''));

                                            $unidades_decimales = [
                                                'kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                                                'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                                                'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
                                            ];

                                            $mostrar_decimales = ($permite_fracciones == 1) || in_array($unidad_medida, $unidades_decimales);

                                            if ($mostrar_decimales) {
                                                $stock_display = number_format($stock_sucursal, 3, '.', '');
                                            } else {
                                                $stock_display = (int)$stock_sucursal;
                                            }

                                            $stock_class = ($stock_sucursal <= 5 && $stock_sucursal > 0) ? 'stock-bajo' : '';
                                            ?>
                                            <span class="<?php echo $stock_class; ?>">
                                                <?php echo $stock_display; ?>
                                            </span>
                                            <?php if ($mostrar_decimales && !empty($producto['unidad_medida'])): ?>
                                                <span class="unidad-medida" style="font-size: 10px;"><?php echo htmlspecialchars($producto['unidad_medida']); ?></span>
                                            <?php endif; ?>

                                            <?php if ($stock_sucursal <= 0): ?>
                                                <span class="badge bg-danger ms-1">Sin Stock</span>
                                            <?php elseif ($stock_sucursal <= 5): ?>
                                                <span class="badge bg-warning ms-1">Stock Bajo</span>
                                            <?php endif; ?>
                                        </small>
                                        <small class="text-muted d-block">
                                            Código: <?php echo htmlspecialchars($producto['codigo']); ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Layout Móvil -->
        <div class="mobile-layout">
            <div class="mobile-tabs">
                <button class="mobile-tab active" data-tab="productos">
                    <i class="fas fa-boxes me-1"></i>Productos
                </button>
                <button class="mobile-tab" data-tab="carrito">
                    <i class="fas fa-shopping-cart me-1"></i>Carrito
                    <?php if (!empty($_SESSION['carrito'])): ?>
                        <span class="badge bg-danger ms-1"><?php echo count($_SESSION['carrito']); ?></span>
                    <?php endif; ?>
                </button>
                <button class="mobile-tab" data-tab="pago">
                    <i class="fas fa-credit-card me-1"></i>Pago
                </button>
            </div>

            <div class="mobile-content active" id="mobile-productos">
                <div class="left-section compact">
                    <div class="section-title">
                        <i class="fas fa-search me-2"></i>Buscar Producto
                        <?php if (!empty($busqueda_nombre)): ?>
                            <span class="badge bg-primary ms-2">Búsqueda activa</span>
                        <?php endif; ?>
                        <span class="badge bg-success ms-2" id="mobileRealTimeStatus">Tiempo Real</span>
                    </div>
                    <div class="search-section <?php echo !empty($busqueda_nombre) ? 'search-active' : ''; ?>" id="mobileSearchSection">
                        <div class="search-container">
                            <input type="text"
                                name="busqueda_nombre"
                                class="form-control search-input"
                                placeholder="🔍 Escriba el nombre del producto..."
                                value="<?php echo htmlspecialchars($busqueda_nombre); ?>"
                                id="mobileSearchInput"
                                autocomplete="off">
                            <button type="button" class="search-btn" id="mobileBtnClearSearch" title="Limpiar búsqueda" style="display: none;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted search-results-count" id="mobileSearchResultsCount">
                                <?php if (!empty($productos)): ?>
                                    Mostrando <?php echo count($productos); ?> productos
                                <?php else: ?>
                                    Escriba para buscar productos
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <div class="left-section compact">
                    <div class="section-title">
                        <i class="fas fa-tags me-2"></i>Filtrar por Categoría
                        <?php if ($categoria_seleccionada): ?>
                            <span class="badge bg-primary ms-2">Filtrado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo $categoria_seleccionada ? 'categoria-filtrada' : ''; ?>">
                        <form method="GET" class="categoria-select-container" id="mobileCategoriaForm">
                            <select name="categoria_id" class="form-select categoria-select" id="mobileCategoriaSelect">
                                <option value="">Todas las Categorías</option>
                                <?php
                                $stmt_cat_mobile = $conn->prepare($sql_categorias_select);
                                if ($stmt_cat_mobile) {
                                    $stmt_cat_mobile->bind_param("i", $_SESSION['sucursal_id']);
                                    $stmt_cat_mobile->execute();
                                    $categorias_mobile = $stmt_cat_mobile->get_result();

                                    if ($categorias_mobile && $categorias_mobile->num_rows > 0) {
                                        $categorias_mobile->data_seek(0);
                                        while ($categoria = $categorias_mobile->fetch_assoc()):
                                            $producto_count = $categoria['producto_count'];
                                ?>
                                            <option value="<?php echo $categoria['id']; ?>"
                                                <?php echo $categoria_seleccionada == $categoria['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                (<?php echo $producto_count; ?>)
                                            </option>
                                <?php
                                        endwhile;
                                    }
                                    $stmt_cat_mobile->close();
                                }
                                ?>
                            </select>
                        </form>
                    </div>
                </div>

                <div class="left-section scrollable">
                    <div class="scrollable-content">
                        <div class="section-title mb-3">
                            <i class="fas fa-boxes me-2"></i>Productos Disponibles en Sucursal
                            <?php if (!empty($productos)): ?>
                                <span class="badge bg-primary ms-2" id="mobileProductCount"><?php echo count($productos); ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary ms-2" id="mobileProductCount">0</span>
                            <?php endif; ?>
                        </div>

                        <div class="product-grid-container">
                            <div class="product-grid" id="mobileProductGrid">
                                <?php if (empty($productos)): ?>
                                    <div class="col-12 text-center py-4" id="mobileEmptyProductsMessage">
                                        <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
                                        <p class="text-muted">
                                            <?php if ($categoria_seleccionada || !empty($busqueda_nombre)): ?>
                                                No se encontraron productos con stock que coincidan con los filtros
                                            <?php else: ?>
                                                No se encontraron productos con stock en esta sucursal
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($productos as $producto): ?>
                                        <?php
                                        $imagen_path = obtenerImagenProducto($producto['id'], $conn);
                                        $imagen_src = $imagen_path ? $imagen_path : '';
                                        $tiene_descuento = $producto['descuento'] > 0;
                                        $precio_con_descuento = $producto['precio_sin_iva'] - ($producto['precio_sin_iva'] * $producto['descuento'] / 100);
                                        
                                        $sql_check_mayoreo_mobile = "SELECT COUNT(*) as tiene_mayoreo FROM producto_precios_mayoreo WHERE producto_id = ? AND activo = 1";
                                        $stmt_mayoreo_check_mobile = $conn->prepare($sql_check_mayoreo_mobile);
                                        $tiene_mayoreo_mobile = false;
                                        if ($stmt_mayoreo_check_mobile) {
                                            $stmt_mayoreo_check_mobile->bind_param("i", $producto['id']);
                                            $stmt_mayoreo_check_mobile->execute();
                                            $result_mayoreo_check_mobile = $stmt_mayoreo_check_mobile->get_result();
                                            if ($result_mayoreo_check_mobile->num_rows > 0) {
                                                $row_mayoreo_mobile = $result_mayoreo_check_mobile->fetch_assoc();
                                                $tiene_mayoreo_mobile = $row_mayoreo_mobile['tiene_mayoreo'] > 0;
                                            }
                                            $stmt_mayoreo_check_mobile->close();
                                        }
                                        ?>
                                        <div class="product-btn"
                                            onclick="agregarProducto(
                                                <?php echo $producto['id']; ?>, 
                                                '<?php echo $producto['permite_fracciones']; ?>', 
                                                '<?php echo addslashes($producto['unidad_medida']); ?>', 
                                                this)">
                                            <div class="product-image-container">
                                                <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                    <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                        alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                        class="product-image"
                                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="product-image-placeholder" style="display: none;">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-image-placeholder">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="product-name"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                            <div class="product-price-descuento">
                                                <?php if ($tiene_descuento): ?>
                                                    <span class="precio-original">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                                    <span class="precio-con-descuento">$<?php echo number_format($precio_con_descuento, 2); ?></span>
                                                    <span class="descuento-badge">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                                <?php else: ?>
                                                    <span class="product-price">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($tiene_mayoreo_mobile): ?>
                                                <div class="mt-1">
                                                    <span class="badge mayoreo-badge">
                                                        <i class="fas fa-tags me-1"></i>Precios por Mayoreo
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($producto['permite_fracciones'] == 1): ?>
                                                <div class="unidad-medida">
                                                    <span class="badge tipo-venta-badge tipo-peso">
                                                        <?php echo ucfirst($producto['unidad_medida']); ?>
                                                    </span>
                                                    por <?php echo $producto['unidad_medida']; ?>
                                                </div>
                                            <?php endif; ?>
                                            <small class="text-muted d-block mt-1">
                                                <i class="fas fa-store me-1"></i>Stock:
                                                <?php
                                                $stock_sucursal = (float)($producto['stock_sucursal'] ?? 0);
                                                $permite_fracciones = (int)($producto['permite_fracciones'] ?? 0);
                                                $unidad_medida = strtolower(trim($producto['unidad_medida'] ?? ''));

                                                $unidades_decimales = [
                                                    'kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                                                    'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                                                    'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
                                                ];

                                                $mostrar_decimales = ($permite_fracciones == 1) || in_array($unidad_medida, $unidades_decimales);

                                                if ($mostrar_decimales) {
                                                    $stock_display = number_format($stock_sucursal, 3, '.', '');
                                                } else {
                                                    $stock_display = (int)$stock_sucursal;
                                                }

                                                $stock_class = ($stock_sucursal <= 5 && $stock_sucursal > 0) ? 'stock-bajo' : '';
                                                ?>
                                                <span class="<?php echo $stock_class; ?>">
                                                    <?php echo $stock_display; ?>
                                                </span>
                                                <?php if ($mostrar_decimales && !empty($producto['unidad_medida'])): ?>
                                                    <span class="unidad-medida" style="font-size: 10px;"><?php echo htmlspecialchars($producto['unidad_medida']); ?></span>
                                                <?php endif; ?>

                                                <?php if ($stock_sucursal <= 0): ?>
                                                    <span class="badge bg-danger ms-1">Sin Stock</span>
                                                <?php elseif ($stock_sucursal <= 5): ?>
                                                    <span class="badge bg-warning ms-1">Stock Bajo</span>
                                                <?php endif; ?>
                                            </small>
                                            <small class="text-muted d-block">
                                                Código: <?php echo htmlspecialchars($producto['codigo']); ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mobile-content" id="mobile-carrito">
                <div class="left-section scrollable">
                    <div class="d-flex justify-content-between align-items-center mb-3" style="flex-shrink: 0; padding: 15px 15px 0 15px;">
                        <div class="section-title">
                            <i class="fas fa-shopping-cart me-2"></i>Carrito de Compra
                            <?php if (!empty($_SESSION['carrito'])): ?>
                                <span class="badge bg-primary ms-2"><?php echo count($_SESSION['carrito']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($_SESSION['carrito'])): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="mobileBtnVaciarCarrito">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="scrollable-content" id="mobile-carrito-container" style="padding: 0 15px 15px 15px;">
                        <?php if (empty($_SESSION['carrito'])): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Carrito vacío</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($_SESSION['carrito'] as $index => $item): ?>
                                <?php
                                $imagen_path = obtenerImagenProducto($item['id'], $conn);
                                $imagen_src = $imagen_path ? $imagen_path : '';
                                $descuento_producto = isset($item['descuento']) ? floatval($item['descuento']) : 0;
                                $descuento_porcentaje = isset($item['descuento_porcentaje']) ? floatval($item['descuento_porcentaje']) : 0;
                                $subtotal_con_descuento = isset($item['subtotal_con_descuento']) ? floatval($item['subtotal_con_descuento']) : floatval($item['subtotal']);
                                $tiene_descuento = $descuento_producto > 0;
                                $tiene_precio_mayoreo = isset($item['tiene_precio_mayoreo']) && $item['tiene_precio_mayoreo'] === true;
                                $precio_base = isset($item['precio_base']) ? floatval($item['precio_base']) : floatval($item['precio']);
                                
                                $cantidad_raw = $item['cantidad'];
                                $permite_decimales = $item['permite_fracciones'] == 1;

                                if ($permite_decimales) {
                                    $cantidad_mostrar = number_format((float)$cantidad_raw, 3, '.', '');
                                    $step = '0.001';
                                    $min = '0.001';
                                    $input_class = 'cantidad-input';
                                    $input_width = '80px';
                                    $show_buttons = false;
                                    $unidad_text = isset($item['unidad_medida']) ? $item['unidad_medida'] : '';
                                } else {
                                    $cantidad_mostrar = (int)$cantidad_raw;
                                    $step = '1';
                                    $min = '1';
                                    $input_class = 'quantity-input';
                                    $input_width = '60px';
                                    $show_buttons = true;
                                    $unidad_text = '';
                                }
                                ?>
                                <div class="card mb-3" data-index="<?php echo $index; ?>">
                                    <div class="card-body">
                                        <div class="row align-items-start">
                                            <div class="col-3">
                                                <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                    <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                        alt="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                        class="product-image-cart"
                                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                                        onload="this.style.display='block'; this.nextElementSibling.style.display='none';">
                                                    <div class="product-image-placeholder-cart" style="display: none;">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-image-placeholder-cart">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-9">
                                                <div class="row align-items-center">
                                                    <div class="col-12">
                                                        <h6 class="card-title mb-1"><?php echo htmlspecialchars($item['nombre']); ?></h6>
                                                        <p class="card-text text-muted small mb-1">Código: <?php echo htmlspecialchars($item['codigo']); ?></p>
                                                        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                                            <?php if ($item['permite_fracciones'] == 1): ?>
                                                                <span class="badge tipo-venta-badge tipo-peso">
                                                                    <?php echo ucfirst($item['unidad_medida']); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if ($tiene_precio_mayoreo): ?>
                                                                <span class="badge mayoreo-badge">
                                                                    <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                                                </span>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio-mobile" 
                                                                    data-index="<?php echo $index; ?>"
                                                                    data-producto-id="<?php echo $item['id']; ?>"
                                                                    data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                                    data-cantidad="<?php echo $cantidad_raw; ?>"
                                                                    data-precio-actual="<?php echo floatval($item['precio']); ?>">
                                                                <i class="fas fa-edit me-1"></i>Editar Precio
                                                            </button>
                                                        </div>
                                                        
                                                        <div class="descuento-info mt-1">
                                                            <?php if ($tiene_descuento): ?>
                                                                <span class="badge bg-danger">-<?php echo number_format($descuento_porcentaje, 0); ?>%</span>
                                                                <span class="small text-muted">-$<?php echo number_format($descuento_producto, 2); ?></span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">0%</span>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento-mobile ms-1"
                                                                data-index="<?php echo $index; ?>"
                                                                data-producto-id="<?php echo $item['id']; ?>"
                                                                data-descuento-actual="<?php echo $descuento_porcentaje; ?>"
                                                                data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        </div>

                                                        <p class="card-text mb-0 mt-2">
                                                            <?php if ($tiene_precio_mayoreo): ?>
                                                                <div class="d-flex flex-column">
                                                                    <span class="text-muted small" style="text-decoration: line-through;">$<?php echo number_format($precio_base, 2); ?></span>
                                                                    <span class="text-success fw-bold">$<?php echo number_format(floatval($item['precio']), 2); ?></span>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="text-success fw-bold">$<?php echo number_format(floatval($item['precio']), 2); ?></span>
                                                            <?php endif; ?>
                                                            <span class="text-muted"> x </span>
                                                        </p>
                                                        <div class="quantity-control d-inline-flex align-items-center mt-1">
                                                            <?php if ($show_buttons): ?>
                                                                <button type="button" class="quantity-btn decrease" data-index="<?php echo $index; ?>">-</button>
                                                            <?php endif; ?>
                                                            <input type="number"
                                                                name="cantidad"
                                                                value="<?php echo $cantidad_mostrar; ?>"
                                                                min="<?php echo $min; ?>"
                                                                step="<?php echo $step; ?>"
                                                                class="<?php echo $input_class; ?>"
                                                                data-index="<?php echo $index; ?>"
                                                                style="width: <?php echo $input_width; ?>; font-size: 12px;">
                                                            <?php if ($show_buttons): ?>
                                                                <button type="button" class="quantity-btn increase" data-index="<?php echo $index; ?>">+</button>
                                                            <?php else: ?>
                                                                <span class="unidad-medida ms-1" style="font-size: 11px;"><?php echo $item['unidad_medida']; ?></span>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar-mobile" data-index="<?php echo $index; ?>">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </div>
                                                        <p class="card-text mt-2">
                                                            <?php if ($tiene_descuento): ?>
                                                                <span class="text-muted small" style="text-decoration: line-through;">Total: $<?php echo number_format(floatval($item['subtotal']), 2); ?></span><br>
                                                                <span class="fw-bold text-primary">Total con descuento: $<?php echo number_format($subtotal_con_descuento, 2); ?></span>
                                                            <?php else: ?>
                                                                <span class="fw-bold text-primary">Total: $<?php echo number_format(floatval($item['subtotal']), 2); ?></span>
                                                            <?php endif; ?>
                                                        </p>
                                                    </div>
                                                    <div class="col-12 text-end mt-2">
                                                        <button type="button" class="btn btn-outline-danger btn-sm btn-eliminar" data-index="<?php echo $index; ?>">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mobile-content" id="mobile-pago">
                <div class="right-section compact">
                    <div class="section-title">
                        <i class="fas fa-user me-2"></i>Cliente
                        <?php if (isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta']): ?>
                            <span class="badge bg-success ms-2">Seleccionado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta'] ? 'cliente-seleccionado' : ''; ?>">
                        <div class="client-select-container" id="mobileClienteContainer">
                            <select name="cliente_id" class="form-select client-select" id="mobileClienteSelect">
                                <option value="">Cliente General</option>
                                <?php
                                if ($clientes) {
                                    $clientes->data_seek(0);
                                    while ($cliente = $clientes->fetch_assoc()):
                                ?>
                                        <option value="<?php echo $cliente['id']; ?>"
                                            <?php echo ($_SESSION['cliente_venta'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                        </option>
                                <?php
                                    endwhile;
                                }
                                ?>
                            </select>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clienteModal">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="right-section compact scrollable">
                    <div class="section-title">
                        <i class="fas fa-receipt me-2"></i>Resumen y Pago
                    </div>

                    <div class="totals-payment-container">
                        <div class="totals-table-container">
                            <table class="totals-table">
                                <tr>
                                    <td class="label">Total:</td>
                                    <td class="value" id="mobile-subtotal-display">$<?php echo number_format($subtotal_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Descuento:</td>
                                    <td class="value text-danger" id="mobile-descuento-display">-$<?php echo number_format($descuento_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Total con Descuento:</td>
                                    <td class="value" id="mobile-subtotal-con-descuento-display">$<?php echo number_format($subtotal_con_descuento_carrito, 2); ?></td>
                                </tr>
                                <tr style="display: none;">
                                    <td class="label">IVA (0%):</span></td>
                                    <td class="value">$0.00</span></td>
                                </tr>
                                <tr style="border-top: 2px solid #dee2e6;">
                                    <td class="label"><strong>TOTAL:</strong></td>
                                    <td class="value total-grande" id="mobile-total-display">$<?php echo number_format($total_carrito, 2); ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="payment-button-container">
                            <button type="button" class="btn btn-pagar-integrado" id="mobile-btnAbrirModalPago"
                                <?php echo empty($_SESSION['carrito']) ? 'disabled' : ''; ?>>
                                <div class="pay-text">
                                    <i class="fas fa-cash-register me-1"></i>PAGAR
                                </div>
                                <div class="total-amount" id="mobile-total-pagar-display">
                                    $<?php echo number_format($total_carrito, 2); ?>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ========== VARIABLES GLOBALES ==========
        let lastScannedCode = '';
        let lastAutoScanTime = 0;
        const SCAN_DELAY = 1000;
        let barcodeBuffer = '';
        let barcodeTimeout;
        let searchTimeout;
        let currentSearchTerm = '';
        let currentCategory = '';
        let currentDescuentoProducto = null;
        let currentDescuentoIndex = null;
        let currentPrecioIndex = null;
        let currentPrecioProducto = null;

        window.currentCarrito = <?php echo json_encode($_SESSION['carrito'] ?? []); ?>;

        // ========== FUNCIONES PARA DETECTAR DISPOSITIVOS ==========
        function esDispositivoMovil() {
            const userAgent = navigator.userAgent || navigator.vendor || window.opera;
            const esMobileUA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|Tablet/i.test(userAgent);
            const esMobileSize = window.innerWidth <= 768;
            const tieneTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
            return esMobileUA || esMobileSize || tieneTouch;
        }

        // ========== FUNCIONES PARA EDITAR PRECIO UNITARIO ==========
        function setupEditarPrecio() {
            // Botones de escritorio
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-editar-precio');
                if (btn) {
                    e.preventDefault();
                    const index = btn.getAttribute('data-index');
                    const productoId = btn.getAttribute('data-producto-id');
                    const productoNombre = btn.getAttribute('data-producto-nombre');
                    const cantidad = parseFloat(btn.getAttribute('data-cantidad'));
                    const precioActual = parseFloat(btn.getAttribute('data-precio-actual'));

                    abrirModalEditarPrecio(index, productoId, productoNombre, cantidad, precioActual);
                }

                const btnMobile = e.target.closest('.btn-editar-precio-mobile');
                if (btnMobile) {
                    e.preventDefault();
                    const index = btnMobile.getAttribute('data-index');
                    const productoId = btnMobile.getAttribute('data-producto-id');
                    const productoNombre = btnMobile.getAttribute('data-producto-nombre');
                    const cantidad = parseFloat(btnMobile.getAttribute('data-cantidad'));
                    const precioActual = parseFloat(btnMobile.getAttribute('data-precio-actual'));

                    abrirModalEditarPrecio(index, productoId, productoNombre, cantidad, precioActual);
                }
            });
        }

        function abrirModalEditarPrecio(index, productoId, productoNombre, cantidad, precioActual) {
            const carrito = window.currentCarrito || [];
            const producto = carrito[index];

            if (!producto) {
                mostrarNotificacionError('Producto no encontrado en el carrito');
                return;
            }

            currentPrecioIndex = index;
            currentPrecioProducto = {
                id: productoId,
                nombre: productoNombre,
                cantidad: cantidad,
                precioActual: precioActual,
                index: index
            };

            document.getElementById('precioProductoNombre').textContent = productoNombre;
            document.getElementById('precioProductoCantidad').textContent = cantidad;
            const inputPrecio = document.getElementById('nuevoPrecio');
            inputPrecio.value = precioActual.toFixed(2);
            
            // Actualizar vista previa
            const subtotalActual = cantidad * precioActual;
            document.getElementById('precioSubtotalActual').textContent = `$${subtotalActual.toFixed(2)}`;
            document.getElementById('precioNuevoSubtotal').textContent = `$${subtotalActual.toFixed(2)}`;

            const modal = new bootstrap.Modal(document.getElementById('editarPrecioModal'));
            modal.show();

            inputPrecio.focus();
            inputPrecio.select();

            // Evento para actualizar vista previa en tiempo real
            inputPrecio.oninput = function() {
                const nuevoPrecio = parseFloat(this.value) || 0;
                const nuevoSubtotal = cantidad * nuevoPrecio;
                document.getElementById('precioNuevoSubtotal').textContent = `$${nuevoSubtotal.toFixed(2)}`;
            };
        }

        function guardarPrecioProducto() {
    if (!currentPrecioProducto) {
        console.error('No hay producto seleccionado');
        return;
    }

    const nuevoPrecio = parseFloat(document.getElementById('nuevoPrecio').value) || 0;

    if (nuevoPrecio <= 0) {
        mostrarNotificacionError('El precio debe ser mayor a 0');
        return;
    }

    mostrarCargandoPrecio(true);

    const formData = new FormData();
    formData.append('actualizar_precio_ajax', 'true');
    formData.append('index', currentPrecioProducto.index);
    formData.append('precio', nuevoPrecio.toFixed(2));

    console.log('Enviando datos:', {
        index: currentPrecioProducto.index,
        precio: nuevoPrecio
    });

    fetch(window.location.pathname, {  // Usar la ruta actual
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'  // Importante: indicar que es AJAX
            }
        })
        .then(async response => {
            const text = await response.text();
            console.log('Respuesta texto (primeros 200 chars):', text.substring(0, 200));
            
            // Intentar parsear como JSON
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Error parseando JSON:', e);
                // Mostrar parte del HTML para debug
                if (text.includes('<title>')) {
                    const titleMatch = text.match(/<title>(.*?)<\/title>/);
                    const title = titleMatch ? titleMatch[1] : 'Desconocido';
                    throw new Error(`El servidor devolvió la página "${title}". Verifica que la sesión esté activa.`);
                }
                throw new Error('El servidor devolvió una respuesta inválida');
            }
        })
        .then(data => {
            if (data.success) {
                actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                mostrarNotificacionExito(`Precio actualizado a $${nuevoPrecio.toFixed(2)}`);
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('editarPrecioModal'));
                if (modal) modal.hide();
            } else {
                throw new Error(data.message || 'Error al actualizar el precio');
            }
        })
        .catch(error => {
            console.error('❌ Error al guardar precio:', error);
            mostrarNotificacionError('Error: ' + error.message);
        })
        .finally(() => {
            mostrarCargandoPrecio(false);
        });
}

        function mostrarCargandoPrecio(mostrar) {
            const btnGuardar = document.getElementById('btnGuardarPrecio');
            if (btnGuardar) {
                if (mostrar) {
                    btnGuardar.disabled = true;
                    btnGuardar.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Guardando...';
                } else {
                    btnGuardar.disabled = false;
                    btnGuardar.innerHTML = '<i class="fas fa-save me-1"></i>Actualizar Precio';
                }
            }
        }

        // ========== FUNCIONES PARA PAGO CON LECTOR ==========
        function generarPagoConLector(total, codigoVenta) {
            return new Promise((resolve, reject) => {
                console.log('🔄 Generando pago con lector para monto:', total);

                const formData = new FormData();
                formData.append('monto', total.toFixed(2));
                formData.append('referencia', codigoVenta);
                formData.append('descripcion', `Venta caja - Folio: ${codigoVenta}`);

                fetch('generar_pago_lector.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => {
                                throw new Error(err.error || 'Error en el servidor');
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            console.log('✅ Pago generado exitosamente:', data);
                            resolve(data);
                        } else {
                            reject(new Error(data.error || 'Error al generar el pago'));
                        }
                    })
                    .catch(error => {
                        console.error('❌ Error en generarPagoConLector:', error);
                        reject(error);
                    });
            });
        }

        function generarQRPago(total) {
            const qrLoading = document.getElementById('qrLoading');
            const qrContent = document.getElementById('qrContent');
            const qrError = document.getElementById('qrError');
            const qrImage = document.getElementById('qrImage');
            const qrTotalAmount = document.getElementById('qrTotalAmount');
            const paymentLink = document.getElementById('paymentLink');
            const qrCodeBadge = document.getElementById('qrCodeBadge');

            if (qrLoading) qrLoading.style.display = 'block';
            if (qrContent) qrContent.style.display = 'none';
            if (qrError) qrError.style.display = 'none';

            if (total < 50) {
                if (qrLoading) qrLoading.style.display = 'none';
                if (qrError) {
                    document.getElementById('qrErrorMessage').textContent = 'El monto mínimo para pago con lector es $50.00';
                    qrError.style.display = 'block';
                }
                mostrarNotificacionError('El monto mínimo para pago con lector es $50.00');
                return;
            }

            const codigoVenta = generarCodigoVenta();

            if (qrTotalAmount) {
                qrTotalAmount.textContent = `$${total.toFixed(2)}`;
            }

            generarPagoConLector(total, codigoVenta)
                .then(data => {
                    console.log('✅ Pago generado:', data);

                    if (qrCodeBadge && data.codeQR) {
                        qrCodeBadge.textContent = formatearReferencia(data.codeQR);
                        qrCodeBadge.style.display = 'inline-block';
                    }

                    if (data.codeQR) {
                        const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=10&data=${encodeURIComponent(data.codeQR)}`;

                        const img = new Image();
                        img.crossOrigin = 'Anonymous';

                        img.onload = function() {
                            if (qrImage) {
                                qrImage.src = img.src;
                                qrImage.alt = `Código QR de pago - Ref: ${data.codeQR}`;
                            }

                            if (paymentLink) {
                                paymentLink.href = '#';
                                paymentLink.textContent = `Referencia: ${formatearReferencia(data.codeQR)}`;
                                paymentLink.onclick = function(e) {
                                    e.preventDefault();
                                    alert(`Código de referencia: ${data.codeQR}`);
                                };
                            }

                            if (qrLoading) qrLoading.style.display = 'none';
                            if (qrContent) qrContent.style.display = 'block';
                            if (qrError) qrError.style.display = 'none';

                            const paymentLinkHidden = document.getElementById('modal-paymentLinkHidden');
                            if (paymentLinkHidden) {
                                paymentLinkHidden.value = data.codeQR;
                            }

                            mostrarNotificacionExito('✓ Código QR generado exitosamente');
                        };

                        img.onerror = function() {
                            console.error('Error al generar QR');
                            if (qrLoading) qrLoading.style.display = 'none';
                            if (qrContent) qrContent.style.display = 'none';
                            if (qrError) {
                                document.getElementById('qrErrorMessage').textContent =
                                    'No se pudo generar el código QR. Use el código de referencia.';
                                qrError.style.display = 'block';
                            }
                        };

                        img.src = qrApiUrl;
                    } else {
                        throw new Error('No se recibió codeQR del servidor');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al generar pago:', error);

                    if (qrLoading) qrLoading.style.display = 'none';
                    if (qrContent) qrContent.style.display = 'none';
                    if (qrError) {
                        document.getElementById('qrErrorMessage').textContent =
                            error.message || 'Error al generar el pago con lector';
                        qrError.style.display = 'block';
                    }

                    mostrarNotificacionError('Error: ' + error.message);
                });
        }

        function formatearReferencia(referencia) {
            if (!referencia) return '';
            const limpio = referencia.replace(/\s/g, '');
            return limpio.match(/.{1,6}/g).join(' ') || referencia;
        }

        function generarCodigoVenta() {
            const timestamp = Date.now();
            const random = Math.floor(Math.random() * 10000);
            const fecha = new Date();
            const año = fecha.getFullYear().toString().slice(-2);
            const mes = (fecha.getMonth() + 1).toString().padStart(2, '0');
            const dia = fecha.getDate().toString().padStart(2, '0');
            const horas = fecha.getHours().toString().padStart(2, '0');
            const minutos = fecha.getMinutes().toString().padStart(2, '0');

            const base = `${timestamp}${random}`;
            return base.slice(0, 15).padStart(15, '0');
        }

        function generarLinkPago(total) {
            console.log('🔄 Iniciando generarLinkPago con total:', total);

            const qrLinkImage = document.getElementById('qrLinkImage');
            const qrLinkCodeBadge = document.getElementById('qrLinkCodeBadge');
            const qrLinkTotalAmount = document.getElementById('qrLinkTotalAmount');
            const refreshBtn = document.getElementById('refreshLinkQrBtn');
            const linkElement = document.getElementById('paymentLinkElement');

            if (qrLinkImage) {
                qrLinkImage.src = '';
                qrLinkImage.alt = 'Generando código QR...';
            }

            if (linkElement) {
                linkElement.textContent = 'Generando link de pago...';
                linkElement.href = '#';
                linkElement.classList.add('text-muted');
            }

            if (qrLinkCodeBadge) {
                qrLinkCodeBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generando...';
            }

            if (qrLinkTotalAmount) {
                qrLinkTotalAmount.textContent = `$${parseFloat(total).toFixed(2)}`;
            }

            if (refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generando...';
            }

            const montoNumerico = parseFloat(total);
            if (isNaN(montoNumerico) || montoNumerico < 10) {
                mostrarNotificacionError('El monto mínimo es $10.00');
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Intentar de nuevo';
                }
                return;
            }

            const formData = new FormData();
            formData.append('monto', montoNumerico.toString());
            formData.append('descripcion', `Pago en caja - Folio: ${generarCodigoVenta()}`);

            console.log('📤 Enviando FormData con monto:', montoNumerico);

            fetch('Service/generar_link_pago.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    console.log('📥 Respuesta del servidor - Status:', response.status);

                    const text = await response.text();
                    console.log('📥 Respuesta en texto:', text);

                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('❌ Error parseando JSON:', e);
                        throw new Error('Respuesta no válida del servidor');
                    }
                })
                .then(data => {
                    console.log('✅ Datos recibidos:', data);

                    if (data.success && data.url) {
                        if (linkElement) {
                            linkElement.textContent = data.url;
                            linkElement.href = data.url;
                            linkElement.classList.remove('text-muted');
                            linkElement.classList.add('text-primary');
                        }

                        if (data.url) {
                            const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=10&data=${encodeURIComponent(data.url)}`;

                            const img = new Image();
                            img.onload = function() {
                                if (qrLinkImage) {
                                    qrLinkImage.src = img.src;
                                    qrLinkImage.alt = 'QR de pago';
                                }
                            };
                            img.onerror = function() {
                                console.error('Error al generar QR');
                            };
                            img.src = qrApiUrl;
                        }

                        if (qrLinkCodeBadge && data.reference) {
                            qrLinkCodeBadge.textContent = formatearReferencia(data.reference);
                            qrLinkCodeBadge.style.display = 'inline-block';
                        }

                        mostrarNotificacionExito('✓ Link de pago generado');
                    } else {
                        throw new Error(data.error || 'Error al generar link');
                    }
                })
                .catch(error => {
                    console.error('❌ Error:', error);

                    if (linkElement) {
                        linkElement.textContent = 'Error: ' + error.message;
                        linkElement.href = '#';
                        linkElement.classList.add('text-danger');
                    }

                    if (qrLinkCodeBadge) {
                        qrLinkCodeBadge.innerHTML = '<span class="text-danger">Error</span>';
                    }

                    mostrarNotificacionError('Error: ' + error.message);
                })
                .finally(() => {
                    if (refreshBtn) {
                        refreshBtn.disabled = false;
                        refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Intentar de nuevo';
                    }
                });
        }

        function refreshLinkPago() {
            const totalElement = document.getElementById('modal-total');
            if (!totalElement) return;

            const totalText = totalElement.textContent.replace('$', '').replace(',', '');
            const total = parseFloat(totalText) || 0;

            if (total < 50) {
                mostrarNotificacionAdvertencia('El monto mínimo recomendado es $50.00');
            }

            generarLinkPago(total);
        }

        function copiarReferenciaLink(event) {
            const badge = document.getElementById('qrLinkCodeBadge');

            if (!badge || !badge.textContent || badge.textContent.includes('Generando') || badge.textContent.includes('Error')) {
                mostrarNotificacionError('No hay referencia disponible');
                return;
            }

            const referencia = badge.textContent.replace(/\s/g, '');

            navigator.clipboard.writeText(referencia).then(function() {
                mostrarNotificacionExito('✓ Referencia copiada al portapapeles');

                const btn = event.currentTarget;
                const originalHtml = btn.innerHTML;
                const originalClass = btn.className;

                btn.innerHTML = '<i class="fas fa-check me-1"></i>Copiado!';
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-success');

                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.className = originalClass;
                }, 2000);

            }).catch(function(err) {
                console.error('Error al copiar referencia:', err);
                mostrarNotificacionError('Error al copiar la referencia');
            });
        }

        function setupLinkPagoEvents() {
            const refreshBtn = document.getElementById('refreshLinkQrBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    refreshLinkPago();
                });
            }

            const copyRefBtn = document.getElementById('copyLinkReferenceBtn');
            if (copyRefBtn) {
                copyRefBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    copiarReferenciaLink(e);
                });
            }

            const qrLinkCodeBadge = document.getElementById('qrLinkCodeBadge');
            if (qrLinkCodeBadge) {
                qrLinkCodeBadge.addEventListener('dblclick', function() {
                    const paymentLink = document.getElementById('paymentLinkElement');
                    if (paymentLink && paymentLink.href && paymentLink.href !== '#') {
                        window.open(paymentLink.href, '_blank');
                    }
                });
            }
        }

        function generarCLABE() {
            const clabeDisplay = document.getElementById('clabeDisplay');
            if (!clabeDisplay) return;

            clabeDisplay.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generando CLABE...';

            const totalElement = document.getElementById('modal-total');
            const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0';
            const total = parseFloat(totalText) || 0;

            const referencia = '020000000000001';

            fetch('spei_endpoints.php?endpoint=generar_clabe', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        Description: `Pago venta - ${referencia}`,
                        Account: referencia,
                        CustomerEmail: '',
                        CustomerName: 'Cliente',
                        ExpirationDate: ''
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`Error HTTP: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('✅ Respuesta del servidor:', data);

                    let clabe = '';

                    if (data.clabe) {
                        clabe = data.clabe;
                    } else if (data.response && data.response.Clabe) {
                        clabe = data.response.Clabe;
                    } else if (data.Clabe) {
                        clabe = data.Clabe;
                    } else if (data.data && data.data.Clabe) {
                        clabe = data.data.Clabe;
                    }

                    if (!clabe) {
                        throw new Error('No se pudo obtener la CLABE del servidor');
                    }

                    clabe = clabe.toString().replace(/\D/g, '');

                    const clabeFormateada = clabe.match(/.{1,4}/g)?.join(' ') || clabe;

                    clabeDisplay.innerHTML = clabeFormateada;
                    clabeDisplay.style.letterSpacing = '2px';
                    clabeDisplay.style.fontSize = '22px';
                    clabeDisplay.style.fontWeight = 'bold';
                    clabeDisplay.style.fontFamily = 'monospace';

                    clabeDisplay.setAttribute('data-clabe-raw', clabe);

                    mostrarNotificacionExito('✓ CLABE generada exitosamente');

                    const paymentLinkHidden = document.getElementById('modal-paymentLinkHidden');
                    if (paymentLinkHidden) {
                        paymentLinkHidden.value = clabe;
                    }
                })
                .catch(error => {
                    console.error('❌ Error al generar CLABE:', error);
                    clabeDisplay.innerHTML = '<span style="color: #dc3545;">Error al generar CLABE</span>';
                    mostrarNotificacionError('Error: ' + error.message);
                });
        }

        function copiarCLABE() {
            const clabeDisplay = document.getElementById('clabeDisplay');
            if (!clabeDisplay) return;

            let clabe = clabeDisplay.getAttribute('data-clabe-raw') ||
                clabeDisplay.textContent.replace(/\s/g, '');

            if (!clabe || clabe === 'ErroralgenerarCLABE' || clabe.includes('Error')) {
                mostrarNotificacionError('No hay CLABE disponible para copiar');
                return;
            }

            navigator.clipboard.writeText(clabe).then(function() {
                mostrarNotificacionExito('✓ CLABE copiada al portapapeles');

                const btn = event.currentTarget;
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-2"></i>Copiada!';
                btn.style.background = '#28a745';
                btn.style.color = 'white';

                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy me-2"></i>Copiar CLABE';
                    btn.style.background = 'white';
                    btn.style.color = '#667eea';
                }, 2000);

            }).catch(function(err) {
                console.error('Error al copiar:', err);
                mostrarNotificacionError('Error al copiar la CLABE');
            });
        }

        function setupPaymentMethods() {
            document.querySelectorAll('#pagoModal .payment-btn').forEach(method => {
                method.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        document.getElementById('modal-metodoPagoInput').value = radio.value;
                    }

                    document.querySelectorAll('#pagoModal .payment-btn').forEach(m => m.classList.remove('active'));
                    this.classList.add('active');

                    const metodoSeleccionado = radio.value;
                    const efectivoSection = document.querySelector('.efectivo-section');
                    const qrSection = document.getElementById('qrSection');
                    const speiSection = document.getElementById('speiSection');
                    const qrLinkSection = document.getElementById('qrLinkSection');

                    if (efectivoSection) efectivoSection.style.display = 'none';
                    if (qrSection) qrSection.style.display = 'none';
                    if (speiSection) speiSection.style.display = 'none';
                    if (qrLinkSection) qrLinkSection.style.display = 'none';

                    if (metodoSeleccionado === 'efectivo') {
                        if (efectivoSection) efectivoSection.style.display = 'block';
                        setTimeout(() => {
                            const efectivoInput = document.getElementById('modal-efectivo-recibido');
                            if (efectivoInput) {
                                efectivoInput.focus();
                                efectivoInput.select();
                            }
                        }, 300);

                    } else if (metodoSeleccionado === 'tarjeta') {
                        const totalElement = document.getElementById('modal-total');
                        const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0';
                        const total = parseFloat(totalText) || 0;

                        if (total < 50) {
                            mostrarNotificacionAdvertencia('El monto mínimo para pagos electrónicos es $50.00. Seleccione otro método de pago.');

                            const efectivoRadio = document.getElementById('modal-efectivo');
                            if (efectivoRadio) {
                                efectivoRadio.checked = true;
                                document.getElementById('modal-metodoPagoInput').value = 'efectivo';
                                document.querySelector('#pagoModal .payment-btn[data-method="efectivo"]').classList.add('active');
                                this.classList.remove('active');

                                if (efectivoSection) efectivoSection.style.display = 'block';
                            }
                            return;
                        }

                        if (qrSection) {
                            qrSection.style.display = 'block';
                            const qrImage = document.getElementById('qrImage');
                            const qrCodeBadge = document.getElementById('qrCodeBadge');
                            const lectorPaymentLink = document.getElementById('paymentLink');

                            if (qrImage) qrImage.src = '';
                            if (qrCodeBadge) qrCodeBadge.innerHTML = '';
                            if (lectorPaymentLink) lectorPaymentLink.textContent = 'Cargando...';

                            generarQRPago(total);
                        }

                        if (qrLinkSection) {
                            qrLinkSection.style.display = 'block';
                            const qrLinkImage = document.getElementById('qrLinkImage');
                            const qrLinkCodeBadge = document.getElementById('qrLinkCodeBadge');
                            const linkElement = document.getElementById('paymentLinkElement');

                            if (qrLinkImage) qrLinkImage.src = '';
                            if (qrLinkCodeBadge) qrLinkCodeBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generando...';
                            if (linkElement) linkElement.textContent = 'Cargando link de pago...';

                            generarLinkPago(total);
                        }

                        if (qrSection && qrLinkSection) {
                            qrSection.style.marginBottom = '20px';
                            qrLinkSection.style.marginTop = '10px';
                            qrLinkSection.style.borderTop = '2px solid #e9ecef';
                            qrLinkSection.style.paddingTop = '20px';
                        }

                    } else if (metodoSeleccionado === 'transferencia') {
                        const totalElement = document.getElementById('modal-total');
                        const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0';
                        const total = parseFloat(totalText) || 0;

                        if (total < 50) {
                            mostrarNotificacionAdvertencia('El monto mínimo para transferencias es $50.00. Seleccione otro método de pago.');

                            const efectivoRadio = document.getElementById('modal-efectivo');
                            if (efectivoRadio) {
                                efectivoRadio.checked = true;
                                document.getElementById('modal-metodoPagoInput').value = 'efectivo';
                                document.querySelector('#pagoModal .payment-btn[data-method="efectivo"]').classList.add('active');
                                this.classList.remove('active');

                                if (efectivoSection) efectivoSection.style.display = 'block';
                            }
                            return;
                        }

                        if (speiSection) {
                            speiSection.style.display = 'block';
                            generarCLABE();
                        }
                    }
                });
            });
        }

        function setupEfectivoInput() {
            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            if (!efectivoInput) return;

            efectivoInput.addEventListener('input', function(e) {
                updatePaymentValues(this.value);
            });

            efectivoInput.addEventListener('blur', function() {
                let value = this.value.trim();
                if (value === '') value = '0';
                const numericValue = parseFloat(value.replace(/[^\d.]/g, '')) || 0;
                this.value = numericValue.toFixed(2);
                updatePaymentValues(numericValue);
            });

            efectivoInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('modal-btnPagar').click();
                }
            });
        }

        function updatePaymentValues(inputValue) {
            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            const cambioInput = document.getElementById('modal-cambio');
            const efectivoHidden = document.getElementById('modal-efectivoRecibidoHidden');
            const cambioHidden = document.getElementById('modal-cambioHidden');
            const totalPagarInput = document.getElementById('modal-total-pagar');
            const totalText = totalPagarInput ? totalPagarInput.value.replace('$', '') : '0.00';
            const total = parseFloat(totalText) || 0;

            let numericValue = 0;
            if (inputValue === '' || inputValue === null || inputValue === undefined) {
                numericValue = 0;
                if (efectivoInput) efectivoInput.value = '';
            } else {
                const cleanValue = inputValue.toString().replace(/[^\d.]/g, '');
                const parts = cleanValue.split('.');
                let finalValue = parts[0];
                if (parts.length > 1) {
                    finalValue += '.' + parts[1].substring(0, 2);
                }
                numericValue = parseFloat(finalValue) || 0;
                if (finalValue !== '' && finalValue !== '0' && efectivoInput) {
                    efectivoInput.value = finalValue;
                }
            }

            if (efectivoHidden) efectivoHidden.value = numericValue.toFixed(2);
            const cambio = numericValue - total;
            if (cambioInput) {
                cambioInput.value = cambio >= 0 ? '$' + cambio.toFixed(2) : '$0.00';
            }
            if (cambioHidden) {
                cambioHidden.value = cambio >= 0 ? cambio.toFixed(2) : '0.00';
            }
        }

        function setupNumpad() {
            document.querySelectorAll('#pagoModal .numpad .numpad-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const value = this.getAttribute('data-value');
                    addNumberModal(value);
                });
            });
        }

        function addNumberModal(num) {
            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            if (!efectivoInput) return;

            let currentValue = efectivoInput.value;

            if (num === 'clear') {
                currentValue = '';
            } else if (num === '.') {
                if (!currentValue.includes('.')) {
                    currentValue = currentValue === '' ? '0.' : currentValue + '.';
                }
            } else {
                if (currentValue === '0' || currentValue === '') {
                    currentValue = num;
                } else {
                    currentValue += num;
                }
            }

            efectivoInput.value = currentValue;
            updatePaymentValues(currentValue);
            efectivoInput.focus();
        }

        function setupQRActions() {
            const refreshBtn = document.getElementById('refreshQrBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const totalElement = document.getElementById('modal-total');
                    const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0';
                    const total = parseFloat(totalText) || 0;
                    if (total < 50) {
                        mostrarNotificacionError('El monto mínimo para pago con lector es $50.00');
                        return;
                    }
                    mostrarNotificacionExito('Generando nuevo código QR...');
                    generarQRPago(total);
                });
            }

            const copyBtn = document.getElementById('copyReferenceBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const badge = document.getElementById('qrCodeBadge');
                    if (badge && badge.textContent && badge.textContent.trim() !== '') {
                        const referencia = badge.textContent.replace(/\s/g, '');
                        navigator.clipboard.writeText(referencia).then(function() {
                            mostrarNotificacionExito('✓ Referencia copiada al portapapeles');
                            const originalHtml = copyBtn.innerHTML;
                            copyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Copiado!';
                            setTimeout(() => {
                                copyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copiar referencia';
                            }, 2000);
                        }).catch(function(err) {
                            console.error('Error al copiar:', err);
                            mostrarNotificacionError('Error al copiar la referencia');
                        });
                    } else {
                        mostrarNotificacionError('No hay referencia disponible');
                    }
                });
            }
        }

        function abrirModalPago() {
            if (!window.currentCarrito || window.currentCarrito.length === 0) {
                mostrarNotificacionError('El carrito está vacío');
                return;
            }

            let subtotal = 0;
            let descuento = 0;
            let subtotalConDescuento = 0;

            if (window.currentCarrito && window.currentCarrito.length > 0) {
                window.currentCarrito.forEach(item => {
                    subtotal += item.subtotal || 0;
                    descuento += item.descuento || 0;
                    subtotalConDescuento += item.subtotal_con_descuento || item.subtotal || 0;
                });
            }

            const total = subtotalConDescuento;
            const modalElement = document.getElementById('pagoModal');
            if (!modalElement) {
                console.error('❌ No se encontró el elemento del modal');
                return;
            }

            const modal = new bootstrap.Modal(modalElement);

            const modalSubtotal = document.getElementById('modal-subtotal');
            if (modalSubtotal) {
                modalSubtotal.textContent = '$' + subtotal.toFixed(2);
            }

            const modalDescuento = document.getElementById('modal-descuento');
            if (modalDescuento) {
                modalDescuento.textContent = '-$' + descuento.toFixed(2);
            }

            const modalSubtotalConDescuento = document.getElementById('modal-subtotal-con-descuento');
            if (modalSubtotalConDescuento) {
                modalSubtotalConDescuento.textContent = '$' + subtotalConDescuento.toFixed(2);
            }

            const modalTotal = document.getElementById('modal-total');
            if (modalTotal) {
                modalTotal.textContent = '$' + total.toFixed(2);
            }

            const modalTotalPagar = document.getElementById('modal-total-pagar');
            if (modalTotalPagar) {
                modalTotalPagar.value = '$' + total.toFixed(2);
                modalTotalPagar.setAttribute('value', '$' + total.toFixed(2));
            }

            const btnPagar = document.getElementById('modal-btnPagar');
            if (btnPagar) {
                btnPagar.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        CONFIRMAR PAGO - $${total.toFixed(2)}
    `;
            }

            const efectivoSection = document.querySelector('.efectivo-section');
            const qrSection = document.getElementById('qrSection');
            const speiSection = document.getElementById('speiSection');
            const qrLinkSection = document.getElementById('qrLinkSection');

            if (efectivoSection) efectivoSection.style.display = 'block';
            if (qrSection) qrSection.style.display = 'none';
            if (speiSection) speiSection.style.display = 'none';
            if (qrLinkSection) qrLinkSection.style.display = 'none';

            document.querySelectorAll('#pagoModal .payment-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            const efectivoBtn = document.querySelector('#pagoModal .payment-btn[data-method="efectivo"]');
            if (efectivoBtn) efectivoBtn.classList.add('active');

            const efectivoRadio = document.getElementById('modal-efectivo');
            if (efectivoRadio) {
                efectivoRadio.checked = true;
            }

            const metodoPagoInput = document.getElementById('modal-metodoPagoInput');
            if (metodoPagoInput) {
                metodoPagoInput.value = 'efectivo';
            }

            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            if (efectivoInput) {
                efectivoInput.value = '';
            }

            const cambioInput = document.getElementById('modal-cambio');
            if (cambioInput) {
                cambioInput.value = '$0.00';
            }

            const efectivoHidden = document.getElementById('modal-efectivoRecibidoHidden');
            if (efectivoHidden) {
                efectivoHidden.value = '0';
            }

            const cambioHidden = document.getElementById('modal-cambioHidden');
            if (cambioHidden) {
                cambioHidden.value = '0';
            }

            const descuentoTotal = document.getElementById('modal-descuentoTotal');
            if (descuentoTotal) {
                descuentoTotal.value = descuento.toFixed(2);
            }

            const paymentLinkHidden = document.getElementById('modal-paymentLinkHidden');
            if (paymentLinkHidden) {
                paymentLinkHidden.value = '';
            }

            modal.show();

            setTimeout(() => {
                const efectivoInputFocus = document.getElementById('modal-efectivo-recibido');
                if (efectivoInputFocus) {
                    efectivoInputFocus.focus();
                    efectivoInputFocus.select();
                    if (total > 0) {
                        efectivoInputFocus.value = total.toFixed(2);
                        updatePaymentValues(total.toFixed(2));
                    }
                }
            }, 500);
        }

        // ========== FUNCIONES PARA ACTUALIZACIÓN DINÁMICA DE CANTIDADES ==========
        function setupDynamicQuantityUpdates() {
            document.querySelectorAll('.quantity-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`input[name="cantidad"][data-index="${index}"]`);
                    let value = parseFloat(input.value);
                    if (this.classList.contains('increase')) {
                        value++;
                    } else if (this.classList.contains('decrease') && value > 1) {
                        value--;
                    }
                    input.value = value;
                    actualizarCantidadProducto(index, value);
                });
            });

            document.querySelectorAll('#mobile-carrito .quantity-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`#mobile-carrito input[name="cantidad"][data-index="${index}"]`);
                    let value = parseFloat(input.value);
                    if (this.classList.contains('increase')) {
                        value++;
                    } else if (this.classList.contains('decrease') && value > 1) {
                        value--;
                    }
                    input.value = value;
                    actualizarCantidadProducto(index, value);
                });
            });

            document.querySelectorAll('.quantity-input, .cantidad-input').forEach(input => {
                input.addEventListener('change', function(e) {
                    const index = this.dataset.index;
                    const value = this.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const index = this.dataset.index;
                        const value = this.value;
                        if (value && parseFloat(value) > 0) {
                            actualizarCantidadProducto(index, value);
                        }
                    }
                });
            });

            document.querySelectorAll('#mobile-carrito .quantity-input, #mobile-carrito .cantidad-input').forEach(input => {
                input.addEventListener('change', function(e) {
                    const index = this.dataset.index;
                    const value = this.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const index = this.dataset.index;
                        const value = this.value;
                        if (value && parseFloat(value) > 0) {
                            actualizarCantidadProducto(index, value);
                        }
                    }
                });
            });

            document.querySelectorAll('.btn-actualizar').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`input[name="cantidad"][data-index="${index}"]`);
                    const value = input.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
            });

            document.querySelectorAll('.btn-actualizar-mobile').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`#mobile-carrito input[name="cantidad"][data-index="${index}"]`);
                    const value = input.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
            });
        }

        function actualizarCantidadProducto(index, cantidad) {
            mostrarCargandoActualizacion(index, true);

            const formData = new FormData();
            formData.append('actualizar_cantidad_ajax', 'true');
            formData.append('index', index);
            formData.append('cantidad', cantidad);

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.carrito_actualizado) {
                            data.carrito_actualizado = data.carrito_actualizado.map(item => {
                                item.cantidad = parseFloat(item.cantidad);
                                return item;
                            });
                        }
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(data.message);
                    } else {
                        throw new Error(data.message || 'Error al actualizar la cantidad');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al actualizar cantidad:', error);
                    mostrarNotificacionError('Error: ' + error.message);
                    setTimeout(() => window.location.reload(), 2000);
                })
                .finally(() => {
                    mostrarCargandoActualizacion(index, false);
                });
        }

        function mostrarCargandoActualizacion(index, mostrar) {
            const filaDesktop = document.querySelector(`.cart-table tbody tr[data-index="${index}"]`);
            const cardMobile = document.querySelector(`#mobile-carrito .card[data-index="${index}"]`);
            const btnActualizarDesktop = document.querySelector(`.btn-actualizar[data-index="${index}"]`);
            const btnActualizarMobile = document.querySelector(`.btn-actualizar-mobile[data-index="${index}"]`);

            if (mostrar) {
                if (filaDesktop) filaDesktop.classList.add('actualizando');
                if (cardMobile) cardMobile.classList.add('actualizando');
                if (btnActualizarDesktop) {
                    btnActualizarDesktop.disabled = true;
                    btnActualizarDesktop.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
                if (btnActualizarMobile) {
                    btnActualizarMobile.disabled = true;
                    btnActualizarMobile.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
            } else {
                if (filaDesktop) filaDesktop.classList.remove('actualizando');
                if (cardMobile) cardMobile.classList.remove('actualizando');
                if (btnActualizarDesktop && document.contains(btnActualizarDesktop)) {
                    btnActualizarDesktop.disabled = false;
                    btnActualizarDesktop.innerHTML = '<i class="fas fa-check"></i>';
                }
                if (btnActualizarMobile && document.contains(btnActualizarMobile)) {
                    btnActualizarMobile.disabled = false;
                    btnActualizarMobile.innerHTML = '<i class="fas fa-check"></i>';
                }
            }
        }

        // ========== FUNCIONES PARA ELIMINAR Y VACIAR CARRITO ==========
        function setupEliminarProducto() {
            document.addEventListener('click', function(e) {
                if (e.target.closest('.btn-eliminar')) {
                    const btn = e.target.closest('.btn-eliminar');
                    const index = btn.getAttribute('data-index');
                    eliminarProductoCarrito(index);
                }
            });
        }

        function eliminarProductoCarrito(index) {
            mostrarCargandoEliminacion(index, true);

            const formData = new FormData();
            formData.append('eliminar_producto_ajax', 'true');
            formData.append('index', index);

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(data.message);
                    } else {
                        actualizarInterfazCarrito(
                            data.carrito_actualizado || [],
                            data.totales || {
                                subtotal: 0,
                                descuento: 0,
                                subtotal_con_descuento: 0,
                                iva: 0,
                                total: 0
                            }
                        );
                    }
                })
                .catch(error => {
                    console.error('❌ Error de conexión:', error);
                    mostrarNotificacionError('Error de conexión al servidor');
                })
                .finally(() => {
                    mostrarCargandoEliminacion(index, false);
                });
        }

        function setupVaciarCarrito() {
            const btnVaciarCarrito = document.getElementById('btnVaciarCarrito');
            const mobileBtnVaciarCarrito = document.getElementById('mobileBtnVaciarCarrito');

            if (btnVaciarCarrito) {
                btnVaciarCarrito.addEventListener('click', function(e) {
                    e.preventDefault();
                    vaciarCarritoCompleto();
                });
            }
            if (mobileBtnVaciarCarrito) {
                mobileBtnVaciarCarrito.addEventListener('click', function(e) {
                    e.preventDefault();
                    vaciarCarritoCompleto();
                });
            }
        }

        function vaciarCarritoCompleto() {
            if (!confirm('¿Está seguro de vaciar todo el carrito?')) return;

            mostrarCargandoGlobal(true);

            const formData = new FormData();
            formData.append('vaciar_carrito_ajax', 'true');

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(data.message);
                    } else {
                        throw new Error(data.message || 'Error al vaciar el carrito');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al vaciar carrito:', error);
                    mostrarNotificacionError('Error: ' + error.message);
                })
                .finally(() => {
                    mostrarCargandoGlobal(false);
                });
        }

        function mostrarCargandoEliminacion(index, mostrar) {
            const filaDesktop = document.querySelector(`.cart-table tbody tr[data-index="${index}"]`);
            const cardMobile = document.querySelector(`#mobile-carrito .card[data-index="${index}"]`);
            const btnEliminarDesktop = document.querySelector(`.btn-eliminar[data-index="${index}"]`);
            const btnEliminarMobile = document.querySelector(`#mobile-carrito .btn-eliminar[data-index="${index}"]`);

            if (mostrar) {
                if (filaDesktop) filaDesktop.classList.add('actualizando');
                if (cardMobile) cardMobile.classList.add('actualizando');
                if (btnEliminarDesktop) {
                    btnEliminarDesktop.disabled = true;
                    btnEliminarDesktop.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
                if (btnEliminarMobile) {
                    btnEliminarMobile.disabled = true;
                    btnEliminarMobile.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
            } else {
                if (filaDesktop) filaDesktop.classList.remove('actualizando');
                if (cardMobile) cardMobile.classList.remove('actualizando');
                if (btnEliminarDesktop && document.contains(btnEliminarDesktop)) {
                    btnEliminarDesktop.disabled = false;
                    btnEliminarDesktop.innerHTML = '<i class="fas fa-times"></i>';
                }
                if (btnEliminarMobile && document.contains(btnEliminarMobile)) {
                    btnEliminarMobile.disabled = false;
                    btnEliminarMobile.innerHTML = '<i class="fas fa-times"></i>';
                }
            }
        }

        function mostrarCargandoGlobal(mostrar) {
            const btnVaciarDesktop = document.getElementById('btnVaciarCarrito');
            const btnVaciarMobile = document.getElementById('mobileBtnVaciarCarrito');

            if (mostrar) {
                if (btnVaciarDesktop) {
                    btnVaciarDesktop.disabled = true;
                    btnVaciarDesktop.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div>Vaciando...';
                }
                if (btnVaciarMobile) {
                    btnVaciarMobile.disabled = true;
                    btnVaciarMobile.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
                document.querySelectorAll('.cart-table tbody tr').forEach(tr => tr.classList.add('actualizando'));
                document.querySelectorAll('#mobile-carrito .card').forEach(card => card.classList.add('actualizando'));
            } else {
                if (btnVaciarDesktop) {
                    btnVaciarDesktop.disabled = false;
                    btnVaciarDesktop.innerHTML = '<i class="fas fa-trash me-1"></i>Vaciar Todo';
                }
                if (btnVaciarMobile) {
                    btnVaciarMobile.disabled = false;
                    btnVaciarMobile.innerHTML = '<i class="fas fa-trash"></i>';
                }
                document.querySelectorAll('.cart-table tbody tr').forEach(tr => tr.classList.remove('actualizando'));
                document.querySelectorAll('#mobile-carrito .card').forEach(card => card.classList.remove('actualizando'));
            }
        }

        function agregarProductoConCantidad(productoId, cantidad, callback) {
            console.log('🔄 Agregando producto al carrito via AJAX:', {
                productoId,
                cantidad
            });

            const cantidadFloat = parseFloat(cantidad);
            if (isNaN(cantidadFloat) || cantidadFloat <= 0) {
                if (callback) callback(false, 'Cantidad no válida');
                return;
            }

            mostrarCargandoProducto(productoId, true);

            const formData = new FormData();
            formData.append('agregar_producto_ajax', 'true');
            formData.append('producto_id', productoId);
            formData.append('cantidad', cantidadFloat.toString());

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.carrito_actualizado) {
                            data.carrito_actualizado = data.carrito_actualizado.map(item => {
                                item.cantidad = parseFloat(item.cantidad);
                                return item;
                            });
                        }
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        if (callback && typeof callback === 'function') {
                            callback(true, data.message);
                        }
                    } else {
                        throw new Error(data.message || 'Error al agregar el producto');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al agregar producto:', error);
                    if (callback && typeof callback === 'function') {
                        callback(false, error.message);
                    }
                    mostrarNotificacionError('Error: ' + error.message);
                })
                .finally(() => {
                    mostrarCargandoProducto(productoId, false);
                });
        }

        function agregarProducto(productoId, permiteFracciones, unidadMedida, element) {
            console.log('🔄 Agregando producto:', {
                id: productoId,
                permiteFracciones: permiteFracciones,
                unidadMedida: unidadMedida
            });

            const unidad = String(unidadMedida).toLowerCase().trim();

            const unidadesDecimales = ['kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
            ];

            const unidadesEnteras = ['pieza', 'piezas', 'unidad', 'unidades', 'pza', 'pzas'];

            let permiteDecimales = false;

            if (permiteFracciones == 1) {
                permiteDecimales = true;
            }
            if (unidadesDecimales.includes(unidad)) {
                permiteDecimales = true;
            }
            if (unidadesEnteras.includes(unidad)) {
                permiteDecimales = false;
            }

            console.log('¿Permite decimales?', permiteDecimales);

            const callback = (success, message) => {
                if (success) {
                    mostrarFeedbackExitoAgregar(element);
                }
            };

            if (permiteDecimales) {
                abrirModalCantidad(productoId, permiteFracciones, unidadMedida, element, callback);
            } else {
                agregarProductoConCantidad(productoId, 1, callback);
            }
        }

        function abrirModalCantidad(productoId, permiteFracciones, unidadMedida, element, callback) {
            const modal = new bootstrap.Modal(document.getElementById('cantidadModal'));
            const input = document.getElementById('cantidadInput');
            const unidadText = document.getElementById('unidadMedidaText');
            const unidad = String(unidadMedida).toLowerCase().trim();

            if (unidad === 'kg' || unidad === 'kilo' || unidad === 'kilogramo' || unidad === 'kilogramos') {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = 'kg';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad (Kilogramos)';
            } else if (unidad === 'ton' || unidad === 'tonelada' || unidad === 'toneladas') {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = 'ton';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad (Toneladas)';
            } else if (unidad === 'l' || unidad === 'litro' || unidad === 'litros') {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = 'L';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad (Litros)';
            } else {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = unidadMedida || 'unidades';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad';
            }

            document.getElementById('productoIdModal').value = productoId;

            const btnAgregar = document.getElementById('btnAgregarConCantidad');
            btnAgregar.onclick = function() {
                const cantidad = parseFloat(input.value);
                if (cantidad && cantidad > 0) {
                    agregarProductoConCantidad(productoId, cantidad, (success, message) => {
                        if (success) {
                            if (element) mostrarFeedbackExitoAgregar(element);
                            modal.hide();
                        } else {
                            mostrarNotificacionError(message);
                        }
                    });
                } else {
                    mostrarNotificacionError('Ingrese una cantidad válida');
                }
            };

            modal.show();
            input.focus();
            input.select();
        }

        function mostrarCargandoProducto(productoId, mostrar) {
            const productButtons = document.querySelectorAll(`.product-btn[onclick*="${productoId}"]`);

            productButtons.forEach(btn => {
                if (mostrar) {
                    btn.classList.add('actualizando');
                    btn.style.pointerEvents = 'none';
                    btn.style.opacity = '0.7';
                    if (!btn.querySelector('.spinner-border')) {
                        const spinner = document.createElement('div');
                        spinner.className = 'spinner-border spinner-border-sm position-absolute';
                        spinner.style.top = '50%';
                        spinner.style.left = '50%';
                        spinner.style.transform = 'translate(-50%, -50%)';
                        spinner.style.zIndex = '100';
                        spinner.style.color = 'var(--primary-color)';
                        btn.appendChild(spinner);
                    }
                } else {
                    btn.classList.remove('actualizando');
                    btn.style.pointerEvents = 'auto';
                    btn.style.opacity = '1';
                    const spinner = btn.querySelector('.spinner-border');
                    if (spinner) spinner.remove();
                }
            });
        }

        function mostrarFeedbackExitoAgregar(element) {
            if (element) {
                const originalBackground = element.style.backgroundColor;
                const originalBorder = element.style.borderColor;
                element.classList.add('product-added');
                element.style.backgroundColor = 'var(--light-green)';
                element.style.borderColor = 'var(--primary-color)';
                element.style.boxShadow = '0 0 15px rgba(39, 174, 96, 0.3)';
                setTimeout(() => {
                    element.classList.remove('product-added');
                    element.style.backgroundColor = originalBackground;
                    element.style.borderColor = originalBorder;
                    element.style.boxShadow = '';
                }, 600);
            }
        }

        // ========== FUNCIONES PARA ACTUALIZACIÓN DE CLIENTE ==========
        function setupClienteAjax() {
            const clienteSelect = document.getElementById('clienteSelect');
            const mobileClienteSelect = document.getElementById('mobileClienteSelect');

            if (clienteSelect) {
                clienteSelect.addEventListener('change', function(e) {
                    e.preventDefault();
                    actualizarClienteSeleccionado(this.value);
                });
            }
            if (mobileClienteSelect) {
                mobileClienteSelect.addEventListener('change', function(e) {
                    e.preventDefault();
                    actualizarClienteSeleccionado(this.value);
                });
            }
        }

        function actualizarClienteSeleccionado(clienteId) {
            mostrarCargandoCliente(true);

            const formData = new FormData();
            formData.append('actualizar_cliente_ajax', 'true');
            formData.append('cliente_id', clienteId);

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCliente(data.cliente_id, data.cliente_nombre);
                        mostrarNotificacionExito(data.message);
                    } else {
                        throw new Error(data.message || 'Error al actualizar el cliente');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al actualizar cliente:', error);
                    mostrarNotificacionError('Error: ' + error.message);
                    revertirSelectCliente();
                })
                .finally(() => {
                    mostrarCargandoCliente(false);
                });
        }

        function actualizarInterfazCliente(clienteId, clienteNombre) {
            const clientSections = document.querySelectorAll('.client-section');
            const clienteSelects = document.querySelectorAll('select[name="cliente_id"]');

            clientSections.forEach(section => {
                if (clienteId) {
                    section.classList.add('cliente-seleccionado');
                } else {
                    section.classList.remove('cliente-seleccionado');
                }
            });

            const badges = document.querySelectorAll('.section-title .badge.bg-success');
            badges.forEach(badge => {
                if (clienteId) {
                    badge.textContent = 'Seleccionado';
                    badge.style.display = 'inline';
                } else {
                    badge.style.display = 'none';
                }
            });

            clienteSelects.forEach(select => {
                if (select.value !== clienteId) {
                    select.value = clienteId || '';
                }
            });
        }

        function mostrarCargandoCliente(mostrar) {
            const clienteSelects = document.querySelectorAll('select[name="cliente_id"]');
            const clientSections = document.querySelectorAll('.client-section');

            if (mostrar) {
                clienteSelects.forEach(select => {
                    select.disabled = true;
                    select.style.opacity = '0.7';
                });
                clientSections.forEach(section => section.classList.add('actualizando'));
            } else {
                clienteSelects.forEach(select => {
                    select.disabled = false;
                    select.style.opacity = '1';
                });
                clientSections.forEach(section => section.classList.remove('actualizando'));
            }
        }

        function revertirSelectCliente() {
            const clienteActual = '<?php echo $_SESSION['cliente_venta'] ?? ''; ?>';
            const clienteSelects = document.querySelectorAll('select[name="cliente_id"]');

            clienteSelects.forEach(select => {
                select.value = clienteActual || '';
            });
        }

        // ========== FUNCIONES PARA ACTUALIZACIÓN DE LA INTERFAZ DEL CARRITO ==========
        function actualizarInterfazCarrito(carrito, totales) {
            window.currentCarrito = carrito.map(item => {
                return {
                    ...item,
                    cantidad: parseFloat(item.cantidad),
                    precio: parseFloat(item.precio),
                    subtotal: parseFloat(item.subtotal),
                    descuento: parseFloat(item.descuento || 0),
                    subtotal_con_descuento: parseFloat(item.subtotal_con_descuento || item.subtotal)
                };
            });

            if (window.currentCarrito.length === 0) {
                actualizarCarritoVacio();
                actualizarTotales(totales);
                actualizarContadores(0);
                actualizarBotonPago(0);
                return;
            }

            actualizarCarritoDesktop(window.currentCarrito);
            actualizarCarritoMobile(window.currentCarrito);
            actualizarTotales(totales);
            actualizarContadores(window.currentCarrito.length);
            actualizarBotonPago(totales.total);

            setTimeout(() => {
                setupDynamicQuantityUpdates();
                setupEliminarProducto();
                setupEditarDescuento();
                setupEditarPrecio();
            }, 100);
        }

        function actualizarCarritoDesktop(carrito) {
            const tbody = document.getElementById('carrito-body');
            let html = '';

            if (carrito.length === 0) {
                html = `
        <tr>
            <td colspan="7" class="text-center py-5">
                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                <br>
                <span class="text-muted">Carrito vacío - Agregue productos para comenzar</span>
            </td>
        </tr>
        `;
            } else {
                carrito.forEach((item, index) => {
                    const tiene_descuento = item.descuento > 0;
                    const subtotal_con_descuento = item.subtotal_con_descuento || item.subtotal;
                    const descuento_porcentaje = item.descuento_porcentaje || 0;
                    const tiene_precio_mayoreo = item.tiene_precio_mayoreo || false;
                    const precio_base = item.precio_base || item.precio;

                    let cantidadDisplay;
                    let cantidadValue;
                    let stepValue;
                    let minValue;

                    if (item.permite_fracciones == 1) {
                        cantidadDisplay = item.cantidad.toFixed(3);
                        cantidadValue = item.cantidad.toFixed(3);
                        stepValue = "0.001";
                        minValue = "0.001";
                    } else {
                        cantidadDisplay = Math.floor(item.cantidad);
                        cantidadValue = Math.floor(item.cantidad);
                        stepValue = "1";
                        minValue = "1";
                    }

                    let imagenUrl = item.imagen_ruta || '';

                    if (!imagenUrl && item.imagen) {
                        if (item.imagen.startsWith('http')) {
                            imagenUrl = item.imagen;
                        } else {
                            imagenUrl = `img/productos/${item.imagen}`;
                        }
                    }

                    html += `
            <tr data-index="${index}">
                <td width="8%">
                    ${imagenUrl ? `
                        <img src="${imagenUrl}"
                            alt="${escapeHtml(item.nombre)}"
                            class="product-image-cart"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            onload="this.style.display='block'; this.nextElementSibling.style.display='none';">
                        <div class="product-image-placeholder-cart" style="display: none;">
                            <i class="fas fa-box"></i>
                        </div>
                    ` : `
                        <div class="product-image-placeholder-cart">
                            <i class="fas fa-box"></i>
                        </div>
                    `}
                </td>
                <td width="22%">
                    <div class="fw-bold text-dark">${escapeHtml(item.nombre)}</div>
                    <small class="text-muted">Código: ${escapeHtml(item.codigo)}</small>
                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                        ${item.permite_fracciones == 1 ? `
                            <div>
                                <span class="badge tipo-venta-badge tipo-peso">
                                    ${item.unidad_medida ? item.unidad_medida.charAt(0).toUpperCase() + item.unidad_medida.slice(1) : 'Peso'}
                                </span>
                            </div>
                        ` : ''}
                        ${tiene_precio_mayoreo ? `
                            <div>
                                <span class="badge mayoreo-badge">
                                    <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                </span>
                            </div>
                        ` : ''}
                        <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio" 
                                data-index="${index}"
                                data-producto-id="${item.id}"
                                data-producto-nombre="${escapeHtml(item.nombre)}"
                                data-cantidad="${item.cantidad}"
                                data-precio-actual="${item.precio.toFixed(2)}">
                            <i class="fas fa-edit me-1"></i>Editar Precio
                        </button>
                    </div>
                </td>
                <td width="12%">
                    <div class="quantity-control">
                        ${item.permite_fracciones == 0 ? `
                            <button type="button" class="quantity-btn decrease" data-index="${index}">-</button>
                            <input type="number" name="cantidad" value="${cantidadValue}"
                                min="${minValue}" step="${stepValue}" class="quantity-input" data-index="${index}">
                            <button type="button" class="quantity-btn increase" data-index="${index}">+</button>
                        ` : `
                            <input type="number" name="cantidad" value="${cantidadValue}"
                                step="${stepValue}" min="${minValue}" class="cantidad-input" data-index="${index}" style="width: 80px;">
                            <span class="unidad-medida ms-1">${item.unidad_medida || 'kg'}</span>
                        `}
                        <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar" data-index="${index}">
                            <i class="fas fa-check"></i>
                        </button>
                    </div>
                </td>
                <td width="12%" class="fw-bold text-success precio-unitario" data-index="${index}">
                    ${tiene_precio_mayoreo ? `
                        <div class="d-flex flex-column">
                            <span class="text-muted small" style="text-decoration: line-through;">$${precio_base.toFixed(2)}</span>
                            <span>$${item.precio.toFixed(2)}</span>
                        </div>
                    ` : `
                        $${item.precio.toFixed(2)}
                    `}
                </td>
                <td width="12%">
                    <div class="descuento-control">
                        <div class="descuento-info d-flex align-items-center gap-2">
                            ${tiene_descuento ? `
                                <span class="badge bg-danger">-${descuento_porcentaje.toFixed(0)}%</span>
                                <span class="small text-muted">-$${item.descuento.toFixed(2)}</span>
                            ` : `
                                <span class="badge bg-secondary">0%</span>
                            `}
                            <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento" 
                                    data-index="${index}"
                                    data-producto-id="${item.id}"
                                    data-descuento-actual="${descuento_porcentaje}"
                                    data-producto-nombre="${escapeHtml(item.nombre)}">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                    </div>
                </td>
                <td width="12%" class="subtotal-descuento" data-index="${index}">
                    ${tiene_descuento ? `
                        <div class="subtotal-descuento">
                            <span class="subtotal-original">$${item.subtotal.toFixed(2)}</span>
                            <span class="subtotal-final">$${subtotal_con_descuento.toFixed(2)}</span>
                        </div>
                    ` : `
                        <span class="fw-bold text-primary">$${item.subtotal.toFixed(2)}</span>
                    `}
                </td>
                <td width="10%">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-eliminar" data-index="${index}">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
            `;
                });
            }

            tbody.innerHTML = html;
        }

        function actualizarCarritoMobile(carrito) {
            const container = document.getElementById('mobile-carrito-container');
            let html = '';

            if (carrito.length === 0) {
                html = `
        <div class="text-center py-5">
            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
            <p class="text-muted">Carrito vacío</p>
        </div>
        `;
            } else {
                carrito.forEach((item, index) => {
                    const tiene_descuento = item.descuento > 0;
                    const subtotal_con_descuento = item.subtotal_con_descuento || item.subtotal;
                    const descuento_porcentaje = item.descuento_porcentaje || 0;
                    const tiene_precio_mayoreo = item.tiene_precio_mayoreo || false;
                    const precio_base = item.precio_base || item.precio;

                    let cantidadValue;
                    let stepValue;
                    let minValue;
                    let inputWidth;

                    if (item.permite_fracciones == 1) {
                        cantidadValue = item.cantidad.toFixed(3);
                        stepValue = "0.001";
                        minValue = "0.001";
                        inputWidth = "80px";
                    } else {
                        cantidadValue = Math.floor(item.cantidad);
                        stepValue = "1";
                        minValue = "1";
                        inputWidth = "60px";
                    }

                    let imagenUrl = item.imagen_ruta || '';

                    if (!imagenUrl && item.imagen) {
                        if (item.imagen.startsWith('http')) {
                            imagenUrl = item.imagen;
                        } else {
                            imagenUrl = `img/productos/${item.imagen}`;
                        }
                    }

                    html += `
            <div class="card mb-3" data-index="${index}">
                <div class="card-body">
                    <div class="row align-items-start">
                        <div class="col-3">
                            ${imagenUrl ? `
                                <img src="${imagenUrl}"
                                    alt="${escapeHtml(item.nombre)}"
                                    class="product-image-cart"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                    onload="this.style.display='block'; this.nextElementSibling.style.display='none';">
                                <div class="product-image-placeholder-cart" style="display: none;">
                                    <i class="fas fa-box"></i>
                                </div>
                            ` : `
                                <div class="product-image-placeholder-cart">
                                    <i class="fas fa-box"></i>
                                </div>
                            `}
                        </div>
                        <div class="col-9">
                            <div class="row align-items-center">
                                <div class="col-12">
                                    <h6 class="card-title mb-1">${escapeHtml(item.nombre)}</h6>
                                    <p class="card-text text-muted small mb-1">Código: ${escapeHtml(item.codigo)}</p>
                                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                        ${item.permite_fracciones == 1 ? `
                                            <span class="badge tipo-venta-badge tipo-peso">
                                                ${item.unidad_medida ? item.unidad_medida.charAt(0).toUpperCase() + item.unidad_medida.slice(1) : 'Peso'}
                                            </span>
                                        ` : ''}
                                        ${tiene_precio_mayoreo ? `
                                            <span class="badge mayoreo-badge">
                                                <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                            </span>
                                        ` : ''}
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio-mobile" 
                                                data-index="${index}"
                                                data-producto-id="${item.id}"
                                                data-producto-nombre="${escapeHtml(item.nombre)}"
                                                data-cantidad="${item.cantidad}"
                                                data-precio-actual="${item.precio.toFixed(2)}">
                                            <i class="fas fa-edit me-1"></i>Editar Precio
                                        </button>
                                    </div>
                                    
                                    <div class="descuento-info mt-1">
                                        ${tiene_descuento ? `
                                            <span class="badge bg-danger">-${descuento_porcentaje.toFixed(0)}%</span>
                                            <span class="small text-muted">-$${item.descuento.toFixed(2)}</span>
                                        ` : `
                                            <span class="badge bg-secondary">0%</span>
                                        `}
                                        <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento-mobile ms-1" 
                                                data-index="${index}"
                                                data-producto-id="${item.id}"
                                                data-descuento-actual="${descuento_porcentaje}"
                                                data-producto-nombre="${escapeHtml(item.nombre)}">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </div>
                                    
                                    <p class="card-text mb-0 mt-2">
                                        ${tiene_precio_mayoreo ? `
                                            <div class="d-flex flex-column">
                                                <span class="text-muted small" style="text-decoration: line-through;">$${precio_base.toFixed(2)}</span>
                                                <span class="text-success fw-bold">$${item.precio.toFixed(2)}</span>
                                            </div>
                                        ` : `
                                            <span class="text-success fw-bold">$${item.precio.toFixed(2)}</span>
                                        `}
                                        <span class="text-muted"> x </span>
                                    </p>
                                    <div class="quantity-control d-inline-flex align-items-center mt-1">
                                        ${item.permite_fracciones == 0 ? `
                                            <button type="button" class="quantity-btn decrease" data-index="${index}">-</button>
                                            <input type="number" name="cantidad" value="${cantidadValue}"
                                                min="${minValue}" step="${stepValue}" class="quantity-input" data-index="${index}" style="width: ${inputWidth}; font-size: 12px;">
                                            <button type="button" class="quantity-btn increase" data-index="${index}">+</button>
                                        ` : `
                                            <input type="number" name="cantidad" value="${cantidadValue}"
                                                step="${stepValue}" min="${minValue}" class="cantidad-input" data-index="${index}" style="width: ${inputWidth}; font-size: 12px;">
                                            <span class="unidad-medida ms-1" style="font-size: 11px;">${item.unidad_medida || 'kg'}</span>
                                        `}
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar-mobile" data-index="${index}">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </div>
                                    <p class="card-text mt-2">
                                        ${tiene_descuento ? `
                                            <span class="text-muted small" style="text-decoration: line-through;">Total: $${item.subtotal.toFixed(2)}</span><br>
                                            <span class="fw-bold text-primary">Total con descuento: $${subtotal_con_descuento.toFixed(2)}</span>
                                        ` : `
                                            <span class="fw-bold text-primary">Total: $${item.subtotal.toFixed(2)}</span>
                                        `}
                                    </p>
                                </div>
                                <div class="col-12 text-end mt-2">
                                    <button type="button" class="btn btn-outline-danger btn-sm btn-eliminar" data-index="${index}">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            `;
                });
            }

            container.innerHTML = html;
        }

        function setupEditarDescuento() {
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-editar-descuento');
                if (btn) {
                    e.preventDefault();
                    const index = btn.getAttribute('data-index');
                    const productoId = btn.getAttribute('data-producto-id');
                    const descuentoActual = parseFloat(btn.getAttribute('data-descuento-actual')) || 0;
                    const productoNombre = btn.getAttribute('data-producto-nombre');

                    abrirModalEditarDescuento(index, productoId, descuentoActual, productoNombre);
                }

                const btnMobile = e.target.closest('.btn-editar-descuento-mobile');
                if (btnMobile) {
                    e.preventDefault();
                    const index = btnMobile.getAttribute('data-index');
                    const productoId = btnMobile.getAttribute('data-producto-id');
                    const descuentoActual = parseFloat(btnMobile.getAttribute('data-descuento-actual')) || 0;
                    const productoNombre = btnMobile.getAttribute('data-producto-nombre');

                    abrirModalEditarDescuento(index, productoId, descuentoActual, productoNombre);
                }
            });
        }

        function abrirModalEditarDescuento(index, productoId, descuentoActual, productoNombre) {
            const carrito = window.currentCarrito || [];
            const producto = carrito[index];

            if (!producto) {
                mostrarNotificacionError('Producto no encontrado en el carrito');
                return;
            }

            currentDescuentoIndex = index;
            currentDescuentoProducto = {
                id: productoId,
                nombre: productoNombre,
                precio: producto.precio,
                cantidad: producto.cantidad,
                index: index
            };

            document.getElementById('productoNombreEditar').textContent = productoNombre;
            document.getElementById('precioUnitarioEditar').textContent = `$${parseFloat(producto.precio).toFixed(2)}`;
            const inputDescuento = document.getElementById('porcentajeDescuento');
            inputDescuento.value = descuentoActual;

            actualizarVistaPrevia(descuentoActual);

            const modal = new bootstrap.Modal(document.getElementById('editarDescuentoModal'));
            modal.show();

            inputDescuento.focus();
            inputDescuento.select();
        }

        function actualizarVistaPrevia(porcentaje) {
            if (!currentDescuentoProducto) return;

            const subtotal = currentDescuentoProducto.precio * currentDescuentoProducto.cantidad;
            const descuento = subtotal * (porcentaje / 100);
            const total = subtotal - descuento;

            document.getElementById('previewSubtotal').textContent = `$${subtotal.toFixed(2)}`;
            document.getElementById('previewDescuento').textContent = `-$${descuento.toFixed(2)}`;
            document.getElementById('previewTotal').textContent = `$${total.toFixed(2)}`;
        }

        function setupPreviewDescuento() {
            const inputDescuento = document.getElementById('porcentajeDescuento');
            if (inputDescuento) {
                inputDescuento.addEventListener('input', function() {
                    const porcentaje = parseFloat(this.value) || 0;
                    actualizarVistaPrevia(Math.min(100, Math.max(0, porcentaje)));
                });
            }
        }

        function guardarDescuentoProducto() {
            if (!currentDescuentoProducto) {
                console.error('No hay producto seleccionado');
                return;
            }

            const porcentaje = parseFloat(document.getElementById('porcentajeDescuento').value) || 0;
            const porcentajeValidado = Math.min(100, Math.max(0, porcentaje));

            if (porcentajeValidado !== porcentaje) {
                document.getElementById('porcentajeDescuento').value = porcentajeValidado;
            }

            mostrarCargandoDescuento(true);

            const formData = new FormData();
            formData.append('actualizar_descuento_ajax', 'true');
            formData.append('producto_id', currentDescuentoProducto.id);
            formData.append('descuento_porcentaje', porcentajeValidado);
            formData.append('index', currentDescuentoProducto.index);

            console.log('Enviando datos:', {
                producto_id: currentDescuentoProducto.id,
                descuento_porcentaje: porcentajeValidado,
                index: currentDescuentoProducto.index
            });

            fetch('caja.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(async response => {
                    console.log('Respuesta status:', response.status);
                    console.log('Content-Type:', response.headers.get('content-type'));

                    const text = await response.text();
                    console.log('Respuesta texto:', text.substring(0, 500));

                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Error parseando JSON:', e);
                        throw new Error('El servidor devolvió HTML en lugar de JSON. Esto suele indicar un error en el servidor.');
                    }
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(`Descuento actualizado a ${porcentajeValidado}%`);

                        const modal = bootstrap.Modal.getInstance(document.getElementById('editarDescuentoModal'));
                        if (modal) modal.hide();
                    } else {
                        throw new Error(data.message || 'Error al actualizar el descuento');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al guardar descuento:', error);
                    mostrarNotificacionError('Error: ' + error.message);

                    if (error.message.includes('HTML')) {
                        mostrarNotificacionError('Error de servidor. Por favor revise los logs.');
                    }
                })
                .finally(() => {
                    mostrarCargandoDescuento(false);
                });
        }

        function mostrarCargandoDescuento(mostrar) {
            const btnGuardar = document.getElementById('btnGuardarDescuento');
            if (btnGuardar) {
                if (mostrar) {
                    btnGuardar.disabled = true;
                    btnGuardar.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Guardando...';
                } else {
                    btnGuardar.disabled = false;
                    btnGuardar.innerHTML = '<i class="fas fa-save me-1"></i>Guardar Descuento';
                }
            }
        }

        function manejarErrorImagen(imgElement, nombreImagen) {
            const rutasPosibles = [
                `img/productos/${nombreImagen}`,
                `uploads/productos/${nombreImagen}`,
                `producto_imagenes/${nombreImagen}`,
                `../img/productos/${nombreImagen}`,
                `../uploads/productos/${nombreImagen}`,
                `admin/img/productos/${nombreImagen}`,
                `assets/img/productos/${nombreImagen}`,
                `images/productos/${nombreImagen}`
            ];

            let intento = parseInt(imgElement.getAttribute('data-intento') || '0');

            if (intento < rutasPosibles.length) {
                imgElement.src = rutasPosibles[intento];
                imgElement.setAttribute('data-intento', intento + 1);
                console.log(`Intentando con ruta: ${rutasPosibles[intento]}`);
            } else {
                imgElement.style.display = 'none';
                const placeholder = imgElement.parentElement.querySelector('.product-image-placeholder-cart');
                if (placeholder) {
                    placeholder.style.display = 'flex';
                }
            }
        }

        function actualizarCarritoVacio() {
            const tbody = document.getElementById('carrito-body');
            const container = document.getElementById('mobile-carrito-container');

            tbody.innerHTML = `
    <tr>
        <td colspan="7" class="text-center py-5">
            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
            <br>
            <span class="text-muted">Carrito vacío - Agregue productos para comenzar</span>
        </td>
    </tr>
`;

            container.innerHTML = `
    <div class="text-center py-5">
        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
        <p class="text-muted">Carrito vacío</p>
    </div>
`;
        }

        function actualizarTotales(totales) {
            const subtotalDisplay = document.getElementById('subtotal-display');
            const descuentoDisplay = document.getElementById('descuento-display');
            const subtotalConDescuentoDisplay = document.getElementById('subtotal-con-descuento-display');
            const totalDisplay = document.getElementById('total-display');
            const totalPagarDisplay = document.getElementById('total-pagar-display');
            const modalSubtotal = document.getElementById('modal-subtotal');
            const modalDescuento = document.getElementById('modal-descuento');
            const modalSubtotalConDescuento = document.getElementById('modal-subtotal-con-descuento');
            const modalTotal = document.getElementById('modal-total');
            const modalTotalPagar = document.getElementById('modal-total-pagar');
            const modalDescuentoTotal = document.getElementById('modal-descuentoTotal');
            const modalBtnPagar = document.getElementById('modal-btnPagar');
            const mobileSubtotalDisplay = document.getElementById('mobile-subtotal-display');
            const mobileDescuentoDisplay = document.getElementById('mobile-descuento-display');
            const mobileSubtotalConDescuentoDisplay = document.getElementById('mobile-subtotal-con-descuento-display');
            const mobileTotalDisplay = document.getElementById('mobile-total-display');
            const mobileTotalPagarDisplay = document.getElementById('mobile-total-pagar-display');

            if (subtotalDisplay) subtotalDisplay.textContent = '$' + parseFloat(totales.subtotal).toFixed(2);
            if (descuentoDisplay) descuentoDisplay.textContent = '-$' + parseFloat(totales.descuento).toFixed(2);
            if (subtotalConDescuentoDisplay) subtotalConDescuentoDisplay.textContent = '$' + parseFloat(totales.subtotal_con_descuento).toFixed(2);
            if (totalDisplay) totalDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);
            if (totalPagarDisplay) totalPagarDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);

            if (modalSubtotal) modalSubtotal.textContent = '$' + parseFloat(totales.subtotal).toFixed(2);
            if (modalDescuento) modalDescuento.textContent = '-$' + parseFloat(totales.descuento).toFixed(2);
            if (modalSubtotalConDescuento) modalSubtotalConDescuento.textContent = '$' + parseFloat(totales.subtotal_con_descuento).toFixed(2);
            if (modalTotal) modalTotal.textContent = '$' + parseFloat(totales.total).toFixed(2);
            if (modalTotalPagar) {
                modalTotalPagar.value = '$' + parseFloat(totales.total).toFixed(2);
                modalTotalPagar.setAttribute('value', '$' + parseFloat(totales.total).toFixed(2));
            }
            if (modalDescuentoTotal) modalDescuentoTotal.value = parseFloat(totales.descuento).toFixed(2);
            if (modalBtnPagar) {
                modalBtnPagar.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        CONFIRMAR PAGO - $${parseFloat(totales.total).toFixed(2)}
    `;
            }

            if (mobileSubtotalDisplay) mobileSubtotalDisplay.textContent = '$' + parseFloat(totales.subtotal).toFixed(2);
            if (mobileDescuentoDisplay) mobileDescuentoDisplay.textContent = '-$' + parseFloat(totales.descuento).toFixed(2);
            if (mobileSubtotalConDescuentoDisplay) mobileSubtotalConDescuentoDisplay.textContent = '$' + parseFloat(totales.subtotal_con_descuento).toFixed(2);
            if (mobileTotalDisplay) mobileTotalDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);
            if (mobileTotalPagarDisplay) mobileTotalPagarDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);
        }

        function actualizarContadores(cantidadProductos) {
            const badgeCarrito = document.querySelector('.mobile-tab[data-tab="carrito"] .badge');
            if (badgeCarrito) {
                if (cantidadProductos > 0) {
                    badgeCarrito.textContent = cantidadProductos;
                    badgeCarrito.style.display = 'inline';
                } else {
                    badgeCarrito.style.display = 'none';
                }
            }

            const contadorDesktop = document.querySelector('.left-section .section-title .badge');
            if (contadorDesktop) {
                if (cantidadProductos > 0) {
                    contadorDesktop.textContent = cantidadProductos + ' productos';
                    contadorDesktop.style.display = 'inline';
                } else {
                    contadorDesktop.style.display = 'none';
                }
            }

            const btnVaciarDesktop = document.getElementById('btnVaciarCarrito');
            const btnVaciarMobile = document.getElementById('mobileBtnVaciarCarrito');
            if (btnVaciarDesktop) btnVaciarDesktop.style.display = cantidadProductos > 0 ? 'block' : 'none';
            if (btnVaciarMobile) btnVaciarMobile.style.display = cantidadProductos > 0 ? 'block' : 'none';
        }

        function actualizarBotonPago(total) {
            const btnPagarDesktop = document.getElementById('btnAbrirModalPago');
            const btnPagarMobile = document.getElementById('mobile-btnAbrirModalPago');
            const carritoVacio = !window.currentCarrito || window.currentCarrito.length === 0;

            if (btnPagarDesktop) btnPagarDesktop.disabled = (total <= 0 || carritoVacio);
            if (btnPagarMobile) btnPagarMobile.disabled = (total <= 0 || carritoVacio);
        }

        // ========== FUNCIONES PARA MODAL DE CLIENTE ==========
        function setupClienteModal() {
            const clienteModal = document.getElementById('clienteModal');
            if (clienteModal) {
                clienteModal.addEventListener('show.bs.modal', function() {
                    document.getElementById('modalTitle').textContent = 'Nuevo Cliente';
                    document.getElementById('formAction').value = 'crear';
                    document.getElementById('clienteId').value = '';
                    document.getElementById('clienteForm').reset();
                });
            }
        }

        // ========== FUNCIONES PARA BÚSQUEDA EN TIEMPO REAL ==========
        function initializeRealTimeSearch() {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');
            const categoriaSelect = document.getElementById('categoriaSelect');
            const mobileCategoriaSelect = document.getElementById('mobileCategoriaSelect');

            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    currentSearchTerm = this.value.trim();
                    clearTimeout(searchTimeout);
                    if (currentSearchTerm.length >= 2 || currentSearchTerm.length === 0) {
                        searchTimeout = setTimeout(() => {
                            performRealTimeSearch(currentSearchTerm, currentCategory);
                        }, 300);
                    }
                    updateClearButton(this.value, 'btnClearSearch');
                    updateMobileClearButton(this.value, 'mobileBtnClearSearch');
                });

                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        currentSearchTerm = this.value.trim();
                        performRealTimeSearch(currentSearchTerm, currentCategory);
                    }
                });
            }

            if (mobileSearchInput) {
                mobileSearchInput.addEventListener('input', function(e) {
                    currentSearchTerm = this.value.trim();
                    clearTimeout(searchTimeout);
                    if (currentSearchTerm.length >= 2 || currentSearchTerm.length === 0) {
                        searchTimeout = setTimeout(() => {
                            performRealTimeSearch(currentSearchTerm, currentCategory);
                        }, 300);
                    }
                    updateClearButton(this.value, 'mobileBtnClearSearch');
                    updateMobileClearButton(this.value, 'btnClearSearch');
                });

                mobileSearchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        currentSearchTerm = this.value.trim();
                        performRealTimeSearch(currentSearchTerm, currentCategory);
                    }
                });
            }

            if (categoriaSelect) {
                categoriaSelect.addEventListener('change', function() {
                    currentCategory = this.value;
                    performRealTimeSearch(currentSearchTerm, currentCategory);
                });
            }
            if (mobileCategoriaSelect) {
                mobileCategoriaSelect.addEventListener('change', function() {
                    currentCategory = this.value;
                    performRealTimeSearch(currentSearchTerm, currentCategory);
                });
            }

            setupClearButtons();
        }

        function performRealTimeSearch(searchTerm, categoryId) {
            showSearchLoading(true);

            const formData = new FormData();
            if (searchTerm && searchTerm.length > 0) {
                formData.append('busqueda', searchTerm);
            }
            if (categoryId && categoryId !== '') {
                formData.append('categoria_id', categoryId);
            }
            formData.append('sucursal_id', <?php echo $_SESSION['sucursal_id']; ?>);
            formData.append('real_time', 'true');

            fetch('buscar_productos_tiempo_real.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    if (data.success) {
                        updateProductGrid(data.productos);
                        updateSearchResultsCount(data.productos.length, searchTerm);
                    } else {
                        throw new Error('Respuesta del servidor no exitosa');
                    }
                    showSearchLoading(false);
                })
                .catch(error => {
                    console.error('Error en búsqueda en tiempo real:', error);
                    showSearchLoading(false);
                    showSearchError('Error al buscar productos: ' + error.message);
                    fallbackSearch(searchTerm, categoryId);
                });
        }

        function fallbackSearch(searchTerm, categoryId) {
            const params = new URLSearchParams();
            if (searchTerm && searchTerm.length > 0) params.append('busqueda_nombre', searchTerm);
            if (categoryId && categoryId !== '') params.append('categoria_id', categoryId);
            window.location.href = 'caja.php?' + params.toString();
        }

        function updateProductGrid(productos) {
            const productGrid = document.getElementById('productGrid');
            const mobileProductGrid = document.getElementById('mobileProductGrid');
            const emptyProductsMessage = document.getElementById('emptyProductsMessage');
            const mobileEmptyProductsMessage = document.getElementById('mobileEmptyProductsMessage');
            const productCount = document.getElementById('productCount');
            const mobileProductCount = document.getElementById('mobileProductCount');

            const count = productos ? productos.length : 0;
            if (productCount) {
                productCount.textContent = count + ' productos';
                productCount.className = count > 0 ? 'badge bg-primary ms-2' : 'badge bg-secondary ms-2';
            }
            if (mobileProductCount) {
                mobileProductCount.textContent = count;
                mobileProductCount.className = count > 0 ? 'badge bg-primary ms-2' : 'badge bg-secondary ms-2';
            }

            let productsHTML = '';
            if (productos && productos.length > 0) {
                productos.forEach(producto => {
                    let imagenHTML = '';
                    if (producto.imagen) {
                        imagenHTML = `
            <img src="${escapeHtml(producto.imagen)}" 
                 alt="${escapeHtml(producto.nombre)}"
                 class="product-image"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="product-image-placeholder" style="display: none;">
                <i class="fas fa-box"></i>
            </div>
        `;
                    } else {
                        imagenHTML = `
            <div class="product-image-placeholder">
                <i class="fas fa-box"></i>
            </div>
        `;
                    }

                    const tiene_descuento = producto.descuento > 0;
                    const precio_con_descuento = producto.precio_sin_iva - (producto.precio_sin_iva * producto.descuento / 100);
                    
                    let tiene_mayoreo = false;
                    if (producto.precios_mayoreo && producto.precios_mayoreo.length > 0) {
                        tiene_mayoreo = true;
                    }

                    let stockDisplay = '';
                    const stockValue = parseFloat(producto.stock_sucursal) || 0;
                    const permiteFracciones = producto.permite_fracciones == 1;
                    const unidadMedida = (producto.unidad_medida || '').toLowerCase();

                    const unidadesDecimales = ['kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                        'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                        'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
                    ];

                    const mostrarDecimales = permiteFracciones || unidadesDecimales.includes(unidadMedida);

                    if (mostrarDecimales) {
                        stockDisplay = stockValue.toFixed(3);
                    } else {
                        stockDisplay = Math.floor(stockValue);
                    }

                    let stockClass = '';
                    let stockBadge = '';
                    if (stockValue <= 0) {
                        stockClass = 'stock-bajo';
                        stockBadge = '<span class="badge bg-danger mt-1">Sin Stock</span>';
                    } else if (stockValue <= 5) {
                        stockClass = 'stock-bajo';
                        stockBadge = '<span class="badge bg-warning mt-1">Stock Bajo</span>';
                    } else {
                        stockBadge = '';
                    }

                    productsHTML += `
        <div class="product-btn"
            onclick="agregarProducto(
                ${producto.id}, 
                '${producto.permite_fracciones}', 
                '${producto.unidad_medida.replace(/'/g, "\\'")}', 
                this)">
            <div class="product-image-container">
                ${imagenHTML}
            </div>
            <div class="product-name">${escapeHtml(producto.nombre)}</div>
            <div class="product-price-descuento">
                ${tiene_descuento ? `
                    <span class="precio-original">$${parseFloat(producto.precio_sin_iva).toFixed(2)}</span>
                    <span class="precio-con-descuento">$${parseFloat(precio_con_descuento).toFixed(2)}</span>
                    <span class="descuento-badge">-${parseFloat(producto.descuento).toFixed(0)}%</span>
                ` : `
                    <span class="product-price">$${parseFloat(producto.precio_sin_iva).toFixed(2)}</span>
                `}
            </div>
            ${tiene_mayoreo ? `
                <div class="mt-1">
                    <span class="badge mayoreo-badge">
                        <i class="fas fa-tags me-1"></i>Precios por Mayoreo
                    </span>
                </div>
            ` : ''}
            ${producto.permite_fracciones == 1 ? `
                <div class="unidad-medida">
                    <span class="badge tipo-venta-badge tipo-peso">
                        ${producto.unidad_medida.charAt(0).toUpperCase() + producto.unidad_medida.slice(1)}
                    </span>
                    por ${producto.unidad_medida}
                </div>
            ` : ''}
            <small class="text-muted d-block mt-2">
                <i class="fas fa-tag me-1"></i>${escapeHtml(producto.categoria_nombre)}
            </small>
            <small class="text-muted d-block mt-1">
                <i class="fas fa-store me-1"></i>Stock Sucursal:
                <span class="${stockClass}">${stockDisplay}</span>
                ${mostrarDecimales ? ` <span class="unidad-medida" style="font-size: 10px;">${producto.unidad_medida || ''}</span>` : ''}
            </small>
            <small class="text-muted d-block">
                Código: ${escapeHtml(producto.codigo)}
            </small>
            ${stockBadge}
        </div>
    `;
                });
            } else {
                productsHTML = `
        <div class="col-12 text-center py-4">
            <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
            <p class="text-muted">
                ${currentSearchTerm || currentCategory ? 
                    'No se encontraron productos con stock que coincidan con los filtros' : 
                    'No se encontraron productos con stock en esta sucursal'}
            </p>
        </div>
    `;
            }

            if (productGrid) productGrid.innerHTML = productsHTML;
            if (mobileProductGrid) mobileProductGrid.innerHTML = productsHTML;
            if (emptyProductsMessage) emptyProductsMessage.style.display = count > 0 ? 'none' : 'block';
            if (mobileEmptyProductsMessage) mobileEmptyProductsMessage.style.display = count > 0 ? 'none' : 'block';
        }

        function updateSearchResultsCount(count, searchTerm) {
            const resultsCount = document.getElementById('searchResultsCount');
            const mobileResultsCount = document.getElementById('mobileSearchResultsCount');
            let message = '';

            if (searchTerm && searchTerm.length > 0) {
                message = count === 0 ?
                    'No se encontraron productos' :
                    `Mostrando ${count} producto${count !== 1 ? 's' : ''} para "${searchTerm}"`;
            } else {
                message = count === 0 ?
                    'No hay productos con stock' :
                    `Mostrando ${count} producto${count !== 1 ? 's' : ''}`;
            }

            if (resultsCount) resultsCount.textContent = message;
            if (mobileResultsCount) mobileResultsCount.textContent = message;
        }

        function showSearchLoading(show) {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            if (show) {
                if (searchInput) searchInput.classList.add('search-loading');
                if (mobileSearchInput) mobileSearchInput.classList.add('search-loading');
            } else {
                if (searchInput) searchInput.classList.remove('search-loading');
                if (mobileSearchInput) mobileSearchInput.classList.remove('search-loading');
            }
        }

        function setupClearButtons() {
            const btnClearSearch = document.getElementById('btnClearSearch');
            const mobileBtnClearSearch = document.getElementById('mobileBtnClearSearch');

            if (btnClearSearch) {
                btnClearSearch.addEventListener('click', function() {
                    clearSearch();
                });
            }
            if (mobileBtnClearSearch) {
                mobileBtnClearSearch.addEventListener('click', function() {
                    clearSearch();
                });
            }
        }

        function clearSearch() {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            if (mobileSearchInput) {
                mobileSearchInput.value = '';
                mobileSearchInput.focus();
            }

            currentSearchTerm = '';
            updateClearButton('', 'btnClearSearch');
            updateClearButton('', 'mobileBtnClearSearch');
            performRealTimeSearch('', currentCategory);
        }

        function updateClearButton(value, buttonId) {
            const button = document.getElementById(buttonId);
            if (button) {
                button.style.display = value && value.length > 0 ? 'flex' : 'none';
            }
        }

        function updateMobileClearButton(value, buttonId) {
            updateClearButton(value, buttonId);
        }

        // ========== FUNCIONES AUXILIARES ==========
        function isFormField(element) {
            if (!element) return false;
            const tagName = element.tagName;
            const type = element.type || '';
            if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT') {
                return true;
            }
            if (element.isContentEditable) {
                return true;
            }
            if (element.hasAttribute('data-form-field')) {
                return true;
            }
            return false;
        }

        function isInModal(element) {
            if (!element) return false;
            let currentElement = element;
            while (currentElement) {
                if (currentElement.classList &&
                    (currentElement.classList.contains('modal') ||
                        currentElement.classList.contains('modal-dialog') ||
                        currentElement.classList.contains('modal-content'))) {
                    return true;
                }
                currentElement = currentElement.parentElement;
            }
            return false;
        }

        // ========== ESCÁNER GLOBAL DE CÓDIGO DE BARRAS ==========
        function setupGlobalBarcodeScanner() {
            document.addEventListener('keydown', function(e) {
                const specialKeys = [
                    'Shift', 'Control', 'Alt', 'Meta', 'CapsLock',
                    'Tab', 'Escape', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
                    'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
                    'ContextMenu', 'PrintScreen', 'ScrollLock', 'Pause', 'Insert', 'Home',
                    'PageUp', 'PageDown', 'Delete', 'End', 'NumLock'
                ];

                if (specialKeys.includes(e.key)) {
                    return;
                }

                const activeElement = document.activeElement;
                if (isFormField(activeElement) || isInModal(activeElement)) {
                    if (e.key === 'Enter') {
                        return;
                    }
                    return;
                }

                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (barcodeBuffer.length >= 3) {
                        processBarcodeAutomatically(barcodeBuffer);
                    }
                    barcodeBuffer = '';
                    clearTimeout(barcodeTimeout);
                    return;
                }

                if (!isFormField(activeElement) && !isInModal(activeElement)) {
                    barcodeBuffer += e.key;
                    clearTimeout(barcodeTimeout);
                    barcodeTimeout = setTimeout(() => {
                        if (barcodeBuffer.length >= 3) {
                            processBarcodeAutomatically(barcodeBuffer);
                        }
                        barcodeBuffer = '';
                    }, 100);
                }
            });

            document.addEventListener('input', function(e) {
                const target = e.target;
                if (isFormField(target)) {
                    return;
                }
                if (target.value && target.value.length >= 6) {
                    const currentTime = Date.now();
                    if (currentTime - lastAutoScanTime > 200) {
                        processBarcodeAutomatically(target.value);
                        target.value = '';
                    }
                }
            });

            document.addEventListener('shown.bs.modal', function() {
                barcodeBuffer = '';
                lastScannedCode = '';
            });

            document.addEventListener('hidden.bs.modal', function() {
                setTimeout(() => {
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput && !isInModal(searchInput)) {
                        searchInput.focus();
                    }
                }, 300);
            });
        }

        function processBarcodeAutomatically(code) {
            const currentTime = Date.now();
            const modalOpen = document.querySelector('.modal.show');
            if (modalOpen) {
                return;
            }

            const activeElement = document.activeElement;
            if (isFormField(activeElement)) {
                return;
            }

            if (code === lastScannedCode && currentTime - lastAutoScanTime < SCAN_DELAY) {
                return;
            }

            lastScannedCode = code;
            lastAutoScanTime = currentTime;

            showScanFeedback();
            buscarYAgregarProducto(code);
        }

        function buscarYAgregarProducto(codigo) {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');
            if (searchInput) searchInput.classList.add('search-loading');
            if (mobileSearchInput) mobileSearchInput.classList.add('search-loading');

            fetch('buscar_producto.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'codigo_barras=' + encodeURIComponent(codigo) + '&sucursal_id=' + <?php echo $_SESSION['sucursal_id'] ?? 0; ?>
                })
                .then(response => {
                    if (!response.ok) throw new Error('Error en la respuesta del servidor');
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.producto) {
                        const producto = data.producto;
                        const unidadMedida = String(producto.unidad_medida).toLowerCase().trim();
                        const permiteFracciones = producto.permite_fracciones == 1;
                        const stockSucursal = producto.stock_sucursal || 0;

                        if (stockSucursal <= 0) {
                            mostrarErrorBusqueda('Producto sin stock: ' + producto.nombre);
                            return;
                        }

                        if (permiteFracciones && (unidadMedida === 'kg' || unidadMedida === 'litro' || unidadMedida === 'litros')) {
                            mostrarInfoBusqueda('Producto encontrado: ' + producto.nombre);
                            abrirModalCantidad(
                                producto.id,
                                producto.permite_fracciones,
                                producto.unidad_medida,
                                null
                            );
                        } else {
                            agregarProductoConCantidad(producto.id, 1, function(success, message) {
                                if (success) {
                                    mostrarExitoBusqueda(message);
                                } else {
                                    mostrarErrorBusqueda(message);
                                }
                            });
                        }
                    } else {
                        mostrarErrorBusqueda(data.message || 'Producto no encontrado');
                    }
                })
                .catch(error => {
                    console.error('❌ Error en la búsqueda:', error);
                    mostrarErrorBusqueda('Error al buscar el producto: ' + error.message);
                })
                .finally(() => {
                    if (searchInput) searchInput.classList.remove('search-loading');
                    if (mobileSearchInput) mobileSearchInput.classList.remove('search-loading');
                });
        }

        function showScanFeedback() {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            [searchInput, mobileSearchInput].forEach(input => {
                if (input) {
                    input.classList.add('auto-scanner-active');
                    setTimeout(() => input.classList.remove('auto-scanner-active'), 1000);
                }
            });

            const notification = document.createElement('div');
            notification.className = 'alert alert-info floating-notification';
            notification.innerHTML = '<i class="fas fa-barcode me-2"></i>Código detectado, buscando producto...';
            notification.style.zIndex = '9999';
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 2000);
        }

        // ========== FUNCIONES DE NOTIFICACIÓN ==========
        function mostrarExitoBusqueda(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-success floating-notification';
            notification.innerHTML = `<i class="fas fa-check-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }

        function mostrarErrorBusqueda(mensaje) {
            console.error('Error en búsqueda:', mensaje);
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function mostrarInfoBusqueda(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-info floating-notification';
            notification.innerHTML = `<i class="fas fa-info-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }

        function mostrarNotificacionExito(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-success floating-notification';
            notification.innerHTML = `<i class="fas fa-check-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function mostrarNotificacionError(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function mostrarNotificacionAdvertencia(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-warning floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function showSearchError(message) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i>${message}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }

        // ========== AUTO-OCULTAMIENTO DE ALERTAS ==========
        function setupAutoHideAlerts() {
            const alerts = document.querySelectorAll('.auto-hide-alert');
            alerts.forEach(alert => {
                const hideTime = alert.getAttribute('data-auto-hide') || 2000;
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, parseInt(hideTime));
            });
        }

        function setupAlertClickToClose() {
            document.addEventListener('click', function() {
                const alerts = document.querySelectorAll('.auto-hide-alert');
                alerts.forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            });
        }

        // ========== FUNCIONES AUXILIARES ==========
        function obtenerRutaImagen(imagen_producto) {
            if (!imagen_producto) return null;
            const rutas_posibles = [
                imagen_producto,
                '../' + imagen_producto,
                'img/productos/' + imagen_producto,
                'images/productos/' + imagen_producto,
                'uploads/productos/' + imagen_producto
            ];
            return rutas_posibles[0];
        }

        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // ========== MANEJO POST VENTA ==========
        function manejarPostVenta() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('venta_exitosa') === 'true') {
                mostrarNotificacionExito('¡Venta realizada exitosamente! Generando ticket...');
                const newUrl = window.location.pathname;
                window.history.replaceState({}, document.title, newUrl);
                setTimeout(function() {
                    manejarTicketPostVenta();
                }, 1000);
            }
        }

        function manejarTicketPostVenta() {
            const ventaData = <?php echo isset($_SESSION['venta_realizada']) ? json_encode($_SESSION['venta_realizada']) : 'null'; ?>;
            if (!ventaData) {
                console.error('No hay datos de venta');
                return;
            }
            if (esDispositivoMovil()) {
                abrirPDFEnMovil();
            } else {
                abrirTicketParaImpresion();
            }
        }

        function abrirTicketParaImpresion() {
            const ticketWindow = window.open('imprimir_ticket.php', 'ticket_venta',
                'width=400,height=700,left=100,top=100,toolbar=no,menubar=no,scrollbars=yes');
            if (ticketWindow) {
                const checkWindow = setInterval(function() {
                    if (ticketWindow.closed) {
                        clearInterval(checkWindow);
                    }
                }, 1000);
            } else {
                console.error('No se pudo abrir la ventana de ticket');
                mostrarNotificacionError('Error: No se pudo abrir el ticket. Por favor, active las ventanas emergentes.');
            }
        }

        function abrirPDFEnMovil() {
            const ventaId = <?php echo isset($_SESSION['venta_realizada']['venta_id']) ? $_SESSION['venta_realizada']['venta_id'] : '0'; ?>;
            const url = 'generar_pdf_ticket.php?venta_id=' + ventaId + '&t=' + new Date().getTime();
            const pdfWindow = window.open(url, '_blank');
            if (!pdfWindow || pdfWindow.closed || typeof pdfWindow.closed == 'undefined') {
                mostrarPDFEnIframe(url);
            }
        }

        function mostrarPDFEnIframe(pdfUrl) {
            const overlay = document.createElement('div');
            overlay.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
`;

            const container = document.createElement('div');
            container.style.cssText = `
    background: white;
    border-radius: 10px;
    width: 95%;
    height: 90%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
`;

            const header = document.createElement('div');
            header.style.cssText = `
    padding: 15px;
    background: var(--primary-color);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
`;
            header.innerHTML = `
    <h4 style="margin: 0;">Ticket de Venta</h4>
    <button id="cerrarPdf" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">×</button>
`;

            const iframe = document.createElement('iframe');
            iframe.style.cssText = `
    width: 100%;
    height: 100%;
    border: none;
    flex: 1;
`;
            iframe.src = pdfUrl;

            container.appendChild(header);
            container.appendChild(iframe);
            overlay.appendChild(container);
            document.body.appendChild(overlay);

            document.getElementById('cerrarPdf').onclick = function() {
                document.body.removeChild(overlay);
            };

            overlay.onclick = function(e) {
                if (e.target === overlay) {
                    document.body.removeChild(overlay);
                }
            };
        }

        // ========== INICIALIZACIÓN PRINCIPAL ==========
        document.addEventListener('DOMContentLoaded', function() {
            setupAutoHideAlerts();
            setupAlertClickToClose();
            setupEditarDescuento();
            setupPreviewDescuento();
            setupEditarPrecio();

            const alertObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) {
                        setupAutoHideAlerts();
                    }
                });
            });
            alertObserver.observe(document.body, {
                childList: true,
                subtree: true
            });

            const carritoInicial = <?php echo json_encode($_SESSION['carrito'] ?? []); ?>;
            const totalInicial = <?php echo $total_carrito; ?>;
            const subtotalInicial = <?php echo $subtotal_carrito; ?>;
            const descuentoInicial = <?php echo $descuento_carrito; ?>;
            const subtotalConDescuentoInicial = <?php echo $subtotal_con_descuento_carrito; ?>;
            const carritoCountInicial = <?php echo count($_SESSION['carrito'] ?? []); ?>;

            const btnPagarModal = document.getElementById('modal-btnPagar');
            if (btnPagarModal && totalInicial > 0) {
                btnPagarModal.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        CONFIRMAR PAGO - $${totalInicial.toFixed(2)}
    `;
            }

            initializeRealTimeSearch();
            setupGlobalBarcodeScanner();
            setupPaymentMethods();
            setupEfectivoInput();
            setupNumpad();
            setupQRActions();
            setupLinkPagoEvents();
            setupClienteModal();
            setupDynamicQuantityUpdates();
            setupEliminarProducto();
            setupVaciarCarrito();
            setupClienteAjax();

            const mobileTabs = document.querySelectorAll('.mobile-tab');
            const mobileContents = document.querySelectorAll('.mobile-content');

            mobileTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetTab = this.getAttribute('data-tab');
                    mobileTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    mobileContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === 'mobile-' + targetTab) {
                            content.classList.add('active');
                        }
                    });
                });
            });

            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');
            if (searchInput) searchInput.addEventListener('focus', function() {
                this.select();
            });
            if (mobileSearchInput) mobileSearchInput.addEventListener('focus', function() {
                this.select();
            });

            document.querySelectorAll('tr').forEach(row => {
                if (row.textContent.includes('IVA')) row.style.display = 'none';
            });

            const formPagoModal = document.getElementById('formPagoModal');
            if (formPagoModal) {
                formPagoModal.addEventListener('submit', function(e) {
                    const carritoActual = window.currentCarrito || [];
                    const totalElement = document.getElementById('total-display');
                    const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0.00';
                    const total = parseFloat(totalText) || 0;
                    const metodoPago = document.getElementById('modal-metodoPagoInput').value;
                    const efectivoRecibido = parseFloat(document.getElementById('modal-efectivoRecibidoHidden').value);

                    if (carritoActual.length === 0 || total <= 0) {
                        e.preventDefault();
                        mostrarNotificacionError('El carrito está vacío');
                        return false;
                    }

                    if (metodoPago === 'efectivo' && efectivoRecibido < total) {
                        e.preventDefault();
                        alert('El efectivo recibido es menor al total a pagar');
                        return false;
                    }

                    if (!confirm('¿Está seguro de procesar la venta?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            const btnAbrirModalPago = document.getElementById('btnAbrirModalPago');
            const mobileBtnAbrirModalPago = document.getElementById('mobile-btnAbrirModalPago');

            if (btnAbrirModalPago) {
                btnAbrirModalPago.addEventListener('click', function(e) {
                    e.preventDefault();
                    abrirModalPago();
                });
            }
            if (mobileBtnAbrirModalPago) {
                mobileBtnAbrirModalPago.addEventListener('click', function(e) {
                    e.preventDefault();
                    abrirModalPago();
                });
            }

            const btnAgregarConCantidad = document.getElementById('btnAgregarConCantidad');
            if (btnAgregarConCantidad) {
                const newBtn = btnAgregarConCantidad.cloneNode(true);
                btnAgregarConCantidad.parentNode.replaceChild(newBtn, btnAgregarConCantidad);

                newBtn.addEventListener('click', function() {
                    const productoId = document.getElementById('productoIdModal').value;
                    const cantidad = document.getElementById('cantidadInput').value;
                    const modal = bootstrap.Modal.getInstance(document.getElementById('cantidadModal'));

                    if (!productoId) {
                        console.error('No hay ID de producto');
                        return;
                    }

                    if (!cantidad || parseFloat(cantidad) <= 0) {
                        mostrarNotificacionError('Por favor ingrese una cantidad válida');
                        return;
                    }

                    const element = this.dataset.element === 'true' ?
                        document.querySelector(`.product-btn[onclick*="${productoId}"]`) : null;

                    const callback = (success, message) => {
                        if (success) {
                            if (element) {
                                mostrarFeedbackExitoAgregar(element);
                            }
                            modal.hide();
                            mostrarNotificacionExito(message || 'Producto agregado al carrito');
                        } else {
                            mostrarNotificacionError(message || 'Error al agregar el producto');
                        }
                    };

                    agregarProductoConCantidad(productoId, cantidad, callback);
                });
            }

            manejarPostVenta();

            updateClearButton('<?php echo $busqueda_nombre; ?>', 'btnClearSearch');
            updateClearButton('<?php echo $busqueda_nombre; ?>', 'mobileBtnClearSearch');

            document.querySelectorAll('#clienteModal input, #clienteModal textarea, #clienteModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });
            document.querySelectorAll('#cantidadModal input, #cantidadModal textarea, #cantidadModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });
            document.querySelectorAll('#pagoModal input, #pagoModal textarea, #pagoModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });
            document.querySelectorAll('#editarPrecioModal input, #editarPrecioModal textarea, #editarPrecioModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });

            const qrSection = document.getElementById('qrSection');
            if (qrSection) qrSection.style.display = 'none';

            const efectivoSection = document.querySelector('.efectivo-section');
            if (efectivoSection) efectivoSection.style.display = 'block';

            const speiSection = document.getElementById('speiSection');
            if (speiSection) speiSection.style.display = 'none';

            const qrLinkSection = document.getElementById('qrLinkSection');
            if (qrLinkSection) qrLinkSection.style.display = 'none';

            const btnGuardarDescuento = document.getElementById('btnGuardarDescuento');
            if (btnGuardarDescuento) {
                btnGuardarDescuento.addEventListener('click', function(e) {
                    e.preventDefault();
                    guardarDescuentoProducto();
                });
            }

            const inputDescuento = document.getElementById('porcentajeDescuento');
            if (inputDescuento) {
                inputDescuento.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        guardarDescuentoProducto();
                    }
                });
            }

            const btnGuardarPrecio = document.getElementById('btnGuardarPrecio');
            if (btnGuardarPrecio) {
                btnGuardarPrecio.addEventListener('click', function(e) {
                    e.preventDefault();
                    guardarPrecioProducto();
                });
            }

            const inputNuevoPrecio = document.getElementById('nuevoPrecio');
            if (inputNuevoPrecio) {
                inputNuevoPrecio.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        guardarPrecioProducto();
                    }
                });
            }
        });
    </script>
</body>

</html>