<?php

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
        if (isset($_POST['actualizar_descuento_ajax']) || isset($_POST['agregar_producto_ajax']) || isset($_POST['actualizar_cantidad_ajax']) || isset($_POST['actualizar_precio_ajax']) || isset($_POST['actualizar_comisiones_carrito_ajax'])) {
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

// ========== CARGAR CONFIGURACIÓN CENTRALIZADA ==========
require_once __DIR__ . '/config/database.php';
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
 * @param PDO $conn Conexión a la base de datos
 * @return float Precio calculado
 */
function obtenerPrecioConMayoreo($producto_id, $cantidad, $conn) {
    try {
        // Primero obtener el precio normal del producto
        $sql_precio_normal = "SELECT subprecio as precio FROM productos WHERE id = ? AND activo = 1";
        $stmt = $conn->prepare($sql_precio_normal);
        $stmt->execute([$producto_id]);
        $producto = $stmt->fetch();
        
        if ($producto) {
            $precio_normal = floatval($producto['precio']);
            
            // Buscar precio de mayoreo que aplique para esta cantidad
            $sql_mayoreo = "SELECT cantidad_minima, precio_especial 
                            FROM producto_precios_mayoreo 
                            WHERE producto_id = ? 
                            AND activo = 1 
                            AND cantidad_minima <= ?
                            ORDER BY cantidad_minima DESC 
                            LIMIT 1";
            
            $stmt_mayoreo = $conn->prepare($sql_mayoreo);
            $stmt_mayoreo->execute([$producto_id, $cantidad]);
            $precio_mayoreo = $stmt_mayoreo->fetch();
            
            if ($precio_mayoreo) {
                error_log("🎯 Precio de mayoreo aplicado - Producto ID: $producto_id, Cantidad: $cantidad, Precio especial: {$precio_mayoreo['precio_especial']}");
                return floatval($precio_mayoreo['precio_especial']);
            }
        }
    } catch (PDOException $e) {
        error_log("Error en obtenerPrecioConMayoreo: " . $e->getMessage());
    }
    
    return $precio_normal ?? 0;
}

// ========== FUNCIÓN PARA OBTENER INFORMACIÓN COMPLETA DEL PRODUCTO CON PRECIO SEGÚN CANTIDAD ==========
function obtenerProductoConPrecio($producto_id, $cantidad, $conn, $sucursal_id) {
    try {
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
        $stmt->execute([$sucursal_id, $producto_id]);
        $producto = $stmt->fetch();
        
        if ($producto) {
            // Calcular precio según mayoreo
            $precio_final = obtenerPrecioConMayoreo($producto_id, $cantidad, $conn);
            $producto['precio_calculado'] = $precio_final;
            $producto['precio_original'] = floatval($producto['precio_base']);
            $producto['tiene_precio_mayoreo'] = ($precio_final < floatval($producto['precio_base']));
            return $producto;
        }
    } catch (PDOException $e) {
        error_log("Error en obtenerProductoConPrecio: " . $e->getMessage());
    }
    
    return null;
}

// ========== FUNCIÓN PARA OBTENER LA RUTA DE LA IMAGEN DEL PRODUCTO ==========
function obtenerImagenProducto($producto_id, $conn) {
    if (empty($producto_id)) {
        return null;
    }

    try {
        $sql_imagen = "SELECT ruta_imagen FROM producto_imagenes 
                       WHERE producto_id = ? 
                       ORDER BY es_principal DESC, orden ASC 
                       LIMIT 1";

        $stmt = $conn->prepare($sql_imagen);
        $stmt->execute([$producto_id]);
        $imagen_data = $stmt->fetch();

        if ($imagen_data) {
            $imagen_producto = $imagen_data['ruta_imagen'];
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
    } catch (PDOException $e) {
        error_log("Error en obtenerImagenProducto: " . $e->getMessage());
    }

    // Si no se encuentra en producto_imagenes, buscar en productos
    try {
        $sql_producto = "SELECT imagen FROM productos WHERE id = ?";
        $stmt_producto = $conn->prepare($sql_producto);
        $stmt_producto->execute([$producto_id]);
        $producto_data = $stmt_producto->fetch();

        if ($producto_data && !empty($producto_data['imagen'])) {
            $imagen_producto = $producto_data['imagen'];
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
    } catch (PDOException $e) {
        error_log("Error en obtenerImagenProducto (productos): " . $e->getMessage());
    }

    return null;
}

// ========== CONEXIÓN A LA BASE DE DATOS PRINCIPAL (PDO) ==========
try {
    $conn_main = getDBConnection();
} catch (Exception $e) {
    error_log("ERROR al conectar a BD principal: " . $e->getMessage());
    $_SESSION['error_message'] = "Error de conexión a la base de datos. Contacte al administrador.";
    header("Location: dashboard.php");
    exit();
}

// Obtener el plan de la empresa
$empresa_plan = "prueba"; // Valor por defecto
$timbres_totales = 0;
$timbres_disponibles = 0;
$empresa_id = $_SESSION['empresa_id'] ?? 0;

// ========== CONEXIÓN A LA BASE DE DATOS PRINCIPAL (PDO) ==========
try {
    $conn_main = getDBConnection();
} catch (Exception $e) {
    error_log("ERROR al conectar a BD principal: " . $e->getMessage());
    $_SESSION['error_message'] = "Error de conexión a la base de datos. Contacte al administrador.";
    header("Location: dashboard.php");
    exit();
}

// ========== OBTENER DATOS DE LA EMPRESA Y FACTURAPI ==========
$empresa_plan = "prueba";
$timbres_totales = 0;
$timbres_disponibles = 0;
$empresa_id = $_SESSION['empresa_id'] ?? 0;
$organization_id = null;
$test_api_key_working = null;

// Obtener datos de la empresa (PDO)
try {
    $sql_empresa = "SELECT plan, facturapi_organization_id, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([$empresa_id]);
    $empresa_data = $stmt_empresa->fetch();
    
    if ($empresa_data) {
        $empresa_plan = $empresa_data['plan'];
        $organization_id = $empresa_data['facturapi_organization_id'] ?? null;
        $timbres_totales = $empresa_data['timbres_totales'] ?? 0;
        $timbres_disponibles = $empresa_data['timbres_disponibles'] ?? 0;
        error_log("📋 Datos empresa - Plan: $empresa_plan, Org ID: $organization_id, Timbres: $timbres_disponibles");
    }
} catch (PDOException $e) {
    error_log("❌ Error al obtener datos de empresa: " . $e->getMessage());
}

// ========== OBTENER API KEY DE PRUEBA ==========
$api_key = "sk_user_MD3D8JvfsNHvtiR65bGokbH34FQyXo7GU65w85z1qA"; // API Key maestra

if (!empty($api_key) && !empty($organization_id) && $empresa_plan === 'premium') {
    try {
        error_log("🔑 Obteniendo API Key de prueba para organización: $organization_id");
        $facturapi_master = new Facturapi($api_key);
        
        // OBTENER API KEY DE PRUEBA DINÁMICAMENTE
        try {
            $test_api_key = $facturapi_master->Organizations->getTestApiKey($organization_id);
            $_SESSION['test_api_key'] = $test_api_key;
            $test_api_key_working = $test_api_key;
            error_log("✅ API Key de prueba obtenida correctamente");
            
            // Guardar en la base de datos para futuras ocasiones
            try {
                $conn_empresa = getEmpresaDBConnection($_SESSION['empresa_db'] ?? '');
                if ($conn_empresa) {
                    // Verificar si existe la columna
                    try {
                        $sql_check = "SHOW COLUMNS FROM sistema_config LIKE 'facturapi_test_api_key'";
                        $stmt_check = $conn_empresa->query($sql_check);
                        if ($stmt_check->rowCount() == 0) {
                            $sql_add = "ALTER TABLE sistema_config ADD COLUMN facturapi_test_api_key VARCHAR(255) DEFAULT NULL";
                            $conn_empresa->exec($sql_add);
                        }
                    } catch (PDOException $e) {
                        error_log("⚠️ Error al verificar/crear columna: " . $e->getMessage());
                    }
                    
                    $sql_update = "UPDATE sistema_config SET facturapi_test_api_key = ? WHERE id = 1";
                    $stmt_update = $conn_empresa->prepare($sql_update);
                    $stmt_update->execute([$test_api_key_working]);
                    error_log("💾 API Key de prueba guardada en sistema_config");
                }
            } catch (Exception $e) {
                error_log("⚠️ No se pudo guardar API Key en BD: " . $e->getMessage());
            }
        } catch (Exception $e) {
            $test_api_key_error = $e->getMessage();
            error_log("❌ Error al obtener API Key de prueba: " . $test_api_key_error);
            
            // Intentar obtener desde la base de datos como fallback
            try {
                $conn_empresa = getEmpresaDBConnection($_SESSION['empresa_db'] ?? '');
                if ($conn_empresa) {
                    $sql_test = "SELECT facturapi_test_api_key FROM sistema_config WHERE id = 1";
                    $stmt_test = $conn_empresa->query($sql_test);
                    $test_data = $stmt_test->fetch();
                    if ($test_data && !empty($test_data['facturapi_test_api_key'])) {
                        $test_api_key_working = $test_data['facturapi_test_api_key'];
                        $_SESSION['test_api_key'] = $test_api_key_working;
                        error_log("✅ API Key de prueba obtenida desde BD (fallback)");
                    }
                }
            } catch (Exception $e2) {
                error_log("❌ Error al obtener API Key desde BD: " . $e2->getMessage());
            }
            
            // Si todo falla, usar la API key fija
            if (empty($test_api_key_working)) {
                $test_api_key_working = "sk_test_3NGWy62UprCyUHgvXmJmmqwt3xmvHeALdjyotVP8U1";
                $_SESSION['test_api_key'] = $test_api_key_working;
                error_log("⚠️ Usando API key de prueba fija (fallback)");
            }
        }
    } catch (Exception $e) {
        error_log("❌ Error al inicializar Facturapi: " . $e->getMessage());
        $test_api_key_working = "sk_test_3NGWy62UprCyUHgvXmJmmqwt3xmvHeALdjyotVP8U1";
        $_SESSION['test_api_key'] = $test_api_key_working;
    }
} else {
    // Fallback para planes no premium
    if ($empresa_plan === 'premium') {
        error_log("⚠️ No se puede obtener API Key - Org ID: $organization_id");
        $test_api_key_working = "sk_test_3NGWy62UprCyUHgvXmJmmqwt3xmvHeALdjyotVP8U1";
        $_SESSION['test_api_key'] = $test_api_key_working;
    } else {
        error_log("ℹ️ Plan $empresa_plan - No se requiere API Key de Facturapi");
    }
}

// Guardar en sesión
$_SESSION['empresa_plan'] = $empresa_plan;
$_SESSION['organization_id'] = $organization_id;

error_log("✅ Configuración de Facturapi cargada - Plan: $empresa_plan, API Key disponible: " . (!empty($test_api_key_working) ? "SÍ" : "NO"));

try {
    $sql_plan = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_plan = $conn_main->prepare($sql_plan);
    $stmt_plan->execute([$empresa_id]);
    $plan_data = $stmt_plan->fetch();
    
    if ($plan_data) {
        $empresa_plan = $plan_data['plan'];
        $timbres_totales = $plan_data['timbres_totales'] ?? 0;
        $timbres_disponibles = $plan_data['timbres_disponibles'] ?? 0;
    }
} catch (PDOException $e) {
    error_log("Error al obtener plan de empresa: " . $e->getMessage());
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// ========== CONEXIÓN A LA BASE DE DATOS DE LA EMPRESA (PDO) ==========
$dbname = $_SESSION['empresa_db'] ?? '';

if (empty($dbname)) {
    error_log("ERROR: No se ha especificado la base de datos");
    $_SESSION['error_message'] = "Error de configuración. Contacte al administrador.";
    header("Location: dashboard.php");
    exit();
}

try {
    $conn = getEmpresaDBConnection($dbname);
} catch (Exception $e) {
    error_log("ERROR al conectar a BD de empresa: " . $e->getMessage());
    $_SESSION['error_message'] = "Error de conexión a la base de datos de la empresa. Contacte al administrador.";
    header("Location: dashboard.php");
    exit();
}

// ========== OBTENER CONFIGURACIÓN DEL SISTEMA - PERO NO APLICAR IVA ==========
try {
    $sql_config = "SELECT iva, moneda, color_primario, color_secundario FROM sistema_config WHERE id = 1";
    $stmt_config = $conn->query($sql_config);
    $config = $stmt_config->fetch();

    $iva_porcentaje = 0; // FORZAR IVA CERO
    $moneda = $config['moneda'] ?? 'MXN';

    // Obtener colores personalizados o usar valores por defecto
    $color_primario = $config['color_primario'] ?? '#27ae60';
    $color_secundario = $config['color_secundario'] ?? '#2ecc71';
} catch (PDOException $e) {
    error_log("Error al obtener configuración: " . $e->getMessage());
    $iva_porcentaje = 0;
    $moneda = 'MXN';
    $color_primario = '#27ae60';
    $color_secundario = '#2ecc71';
}

// ========== OBTENER LOGO DE LA EMPRESA ==========
$logo_empresa = null;
$logo_path = null;
$empresa_nombre = $_SESSION['empresa_nombre'] ?? 'Sistema';

try {
    $sql_logo_config = "SELECT nombre_empresa, direccion, telefono, rfc, logo as logo_empresa FROM sistema_config LIMIT 1";
    $stmt_logo = $conn->query($sql_logo_config);
    $config_data = $stmt_logo->fetch();

    if ($config_data) {
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
} catch (PDOException $e) {
    error_log("Error al obtener logo: " . $e->getMessage());
}

// ========== VERIFICACIÓN DE CAJA ABIERTA ==========
$caja_actual = null;
$usuario_id = $_SESSION['usuario_id'] ?? 0;
$sucursal_id = $_SESSION['sucursal_id'] ?? 0;

// PRIMERO: Intentar usar la caja de la sesión
if (isset($_SESSION['caja_actual_id']) && !empty($_SESSION['caja_actual_id'])) {
    $caja_id_sesion = $_SESSION['caja_actual_id'];

    try {
        $sql_caja_sesion = "SELECT * FROM caja WHERE id = ? AND estado = 'abierta'";
        $stmt_sesion = $conn->prepare($sql_caja_sesion);
        $stmt_sesion->execute([$caja_id_sesion]);
        $caja_actual = $stmt_sesion->fetch();

        if ($caja_actual) {
            error_log("✅ Caja encontrada por ID de sesión - ID: " . $caja_actual['id']);
        } else {
            error_log("❌ Caja NO encontrada por ID de sesión - ID: $caja_id_sesion");
            unset($_SESSION['caja_actual_id']);
        }
    } catch (PDOException $e) {
        error_log("❌ Error en consulta por sesión: " . $e->getMessage());
    }
}

// SEGUNDO: Si no se encontró por ID de sesión, buscar por usuario/sucursal
if (!$caja_actual) {
    error_log("Buscando caja por usuario/sucursal...");

    try {
        $sql_caja = "SELECT * FROM caja WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql_caja);
        $stmt->execute([$usuario_id, $sucursal_id]);
        $caja_actual = $stmt->fetch();

        if ($caja_actual) {
            error_log("✅ Caja encontrada por usuario/sucursal - ID: " . $caja_actual['id']);
        } else {
            error_log("❌ Caja NO encontrada por usuario/sucursal");
        }
    } catch (PDOException $e) {
        error_log("❌ Error en ejecución de consulta: " . $e->getMessage());
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

// ========== OBTENER CATEGORÍAS CON CONTEOS ==========
$categorias_con_count = [];
try {
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
    $stmt->execute([$_SESSION['sucursal_id']]);
    $categorias_con_count = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error al obtener categorías: " . $e->getMessage());
}

// ========== OBTENER PRODUCTOS ==========
$productos = [];
$categoria_seleccionada = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : null;
$busqueda_nombre = isset($_GET['busqueda_nombre']) ? trim($_GET['busqueda_nombre']) : '';

$columnas_productos = ",
    p.unidad_medida,
    p.peso_kg,
    p.permite_fracciones,
    p.imagen,
    p.descuento";

try {
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
        $stmt->execute([$_SESSION['sucursal_id']]);
        $productos = $stmt->fetchAll();
        
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
        $stmt->execute([$_SESSION['sucursal_id'], $categoria_seleccionada]);
        $productos = $stmt->fetchAll();
        
    } elseif (!empty($busqueda_nombre)) {
        $search_term = "%" . $busqueda_nombre . "%";
        
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
            $stmt->execute([$_SESSION['sucursal_id'], $categoria_seleccionada, $search_term, $search_term]);
            $productos = $stmt->fetchAll();
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
            $stmt->execute([$_SESSION['sucursal_id'], $search_term, $search_term]);
            $productos = $stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    error_log("Error al obtener productos: " . $e->getMessage());
}

// ========== OBTENER CLIENTES ==========
$clientes = [];
try {
    $sql_clientes = "SELECT * FROM clientes WHERE activo = 1 ORDER BY nombre";
    $stmt = $conn->query($sql_clientes);
    $clientes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error al obtener clientes: " . $e->getMessage());
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

// ========== GUARDAR COMISIONES PENDIENTES ASIGNADAS A UN PRODUCTO DEL CARRITO ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_comisiones_carrito_ajax'])) {
    header('Content-Type: application/json');
    $index = intval($_POST['index'] ?? -1);
    $comisiones = json_decode($_POST['comisiones'] ?? '[]', true);
    if ($index >= 0 && isset($_SESSION['carrito'][$index])) {
        $_SESSION['carrito'][$index]['comisiones'] = is_array($comisiones) ? $comisiones : [];
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Producto no encontrado en el carrito']);
    }
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
            $stmt->execute([$_SESSION['sucursal_id'], $producto_id]);
            $stock_result = $stmt->fetch();

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
            $precio_actual = floatval($_SESSION['carrito'][$index]['precio']);
            $precio_mayoreo = obtenerPrecioConMayoreo($producto_id, $cantidad, $conn);
            
            $precio_base_original = floatval($_SESSION['carrito'][$index]['precio_original'] ?? $precio_base);
            
            if ($precio_actual == $precio_base_original || $precio_actual == $precio_base) {
                $precio_unitario = $precio_mayoreo;
                $_SESSION['carrito'][$index]['tiene_precio_mayoreo'] = ($precio_unitario < $precio_base);
                $_SESSION['carrito'][$index]['precio_base'] = $precio_base;
                $_SESSION['carrito'][$index]['precio_original'] = $precio_base;
            } else {
                $precio_unitario = $precio_actual;
            }
            
            $_SESSION['carrito'][$index]['precio'] = $precio_unitario;
            $_SESSION['carrito'][$index]['precio_sin_iva'] = $precio_unitario;
            
            // Recalcular subtotal
            $subtotal = (float)$cantidad * $precio_unitario;
            $_SESSION['carrito'][$index]['subtotal'] = $subtotal;

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

        // Verificar si la columna descuento existe
        try {
            $sql_check = "SELECT descuento FROM productos LIMIT 1";
            $conn->query($sql_check);
        } catch (PDOException $e) {
            // Si no existe, crearla
            $sql_add = "ALTER TABLE productos ADD COLUMN descuento DECIMAL(5,2) DEFAULT 0";
            $conn->exec($sql_add);
            error_log("Columna descuento creada en productos");
        }

        $sql_update = "UPDATE productos SET descuento = ? WHERE id = ?";
        $stmt = $conn->prepare($sql_update);
        $stmt->execute([$descuento_porcentaje, $producto_id]);

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
            $_SESSION['carrito'][$encontrado_index]['costo'] = (float)($producto['costo'] ?? 0);
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
                'costo' => (float)($producto['costo'] ?? 0),
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
            $stmt->execute([$cliente_id]);
            $cliente = $stmt->fetch();

            if ($cliente) {
                $_SESSION['cliente_venta'] = $cliente_id;
                $response['success'] = true;
                $response['message'] = "Cliente seleccionado: " . htmlspecialchars($cliente['nombre']);
                $response['cliente_nombre'] = htmlspecialchars($cliente['nombre']);
            } else {
                $response['message'] = "Cliente no encontrado";
            }
        }
    } catch (Exception $e) {
        $response['message'] = "Error al actualizar cliente: " . $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

if (isset($_POST['procesar_pago'])) {
    error_log("=== INICIO PROCESAR PAGO ===");
    error_log("Plan de empresa: " . $empresa_plan);
    error_log("Timbres disponibles: " . $timbres_disponibles);

    if (empty($_SESSION['carrito'])) {
        $_SESSION['error_message'] = "El carrito está vacío";
        header("Location: caja.php");
        exit();
    }

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

    $descripcion_venta = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
    if ($descripcion_venta === '') {
        $descripcion_venta = null;
    } else {
        $descripcion_venta = mb_substr($descripcion_venta, 0, 500);
    }

    // Calcular totales
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

    // Validar stock...
    // [Mantén tu código de validación de stock aquí]

    try {
        $conn->beginTransaction();

        $codigo_venta = date('YmdHis');
        $cliente_id = $_SESSION['cliente_venta'] ?? null;
        if (empty($cliente_id)) {
            $cliente_id = null;
        }

        // Insertar venta
        $sql_venta = "
            INSERT INTO ventas (codigo_venta, cliente_id, usuario_id, sucursal_id, caja_id, subtotal, descuento, iva, total, metodo_pago, estado, efectivo_recibido, cambio, descripcion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completada', ?, ?, ?)
        ";
        $stmt = $conn->prepare($sql_venta);
        $caja_id = $_SESSION['caja_actual_id'] ?? $caja_actual['id'];
        
        $stmt->execute([
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
            $cambio,
            $descripcion_venta
        ]);
        
        $venta_id = $conn->lastInsertId();
        error_log("✅ Venta insertada - ID: $venta_id, Código: $codigo_venta");

        // Insertar detalles y actualizar stock
        $costo_total_venta = 0;
        foreach ($_SESSION['carrito'] as $item) {
            $precio_unitario_sin_iva = $item['precio'];
            $subtotal_producto = $item['subtotal'];
            $descuento_producto = $item['descuento'] ?? 0;
            $total_producto = $item['subtotal_con_descuento'] ?? $subtotal_producto;

            $permite_decimales = $item['permite_fracciones'] == 1;
            $unidad_medida = $item['unidad_medida'] ?? 'unidad';

            $costo_unitario_item = (float)($item['costo'] ?? 0);
            $costo_total_venta += $costo_unitario_item * (float)$item['cantidad'];

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
            $stmt->execute([
                $venta_id,
                $item['id'],
                $cantidad,
                $precio_unitario_sin_iva,
                $subtotal_producto,
                $descuento_producto,
                $total_producto,
                $unidad_medida
            ]);
            $venta_detalle_id = $conn->lastInsertId();

            // Guardar comisiones pendientes...
            // [Mantén tu código de comisiones aquí]

            // Actualizar stock
            $sql_update_stock = "
                UPDATE producto_sucursal 
                SET stock = stock - ? 
                WHERE producto_id = ? AND sucursal_id = ?
            ";
            $stmt = $conn->prepare($sql_update_stock);
            $cantidad_a_descontar = $permite_decimales ? (float)$item['cantidad'] : (int)$item['cantidad'];
            $stmt->execute([$cantidad_a_descontar, $item['id'], $_SESSION['sucursal_id']]);
            error_log("✅ STOCK ACTUALIZADO - Producto: {$item['nombre']}, Cantidad descontada: {$cantidad_a_descontar}");
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

        $stmt = $conn->prepare($sql_update_caja);
        $stmt->execute([$total, $ventas_efectivo_inc, $ventas_tarjeta_inc, $ventas_transferencia_inc, $caja_id]);
        error_log("✅ Caja actualizada correctamente");

        // Registrar gasto automático por el costo de la mercancía vendida
        if ($costo_total_venta > 0) {
            $sql_gasto = "
                INSERT INTO gastos (concepto, categoria, monto, tipo, origen, venta_id, usuario_id, sucursal_id, metodo_pago, fecha, descripcion)
                VALUES (?, 'Costo de venta', ?, 'automatico', 'venta', ?, ?, ?, ?, NOW(), ?)
            ";
            $stmt = $conn->prepare($sql_gasto);
            $concepto_gasto = "Costo de mercancía - Venta #" . $codigo_venta;
            $descripcion_gasto = "Costo generado automáticamente al concretar la venta " . $codigo_venta;
            $stmt->execute([
                $concepto_gasto,
                $costo_total_venta,
                $venta_id,
                $_SESSION['usuario_id'],
                $_SESSION['sucursal_id'],
                $metodo_pago,
                $descripcion_gasto
            ]);
            error_log("✅ Gasto automático registrado para la venta $venta_id - Monto: $costo_total_venta");
        }

        // ========== GENERAR RECIBO FACTURAPI ==========
        $facturapi_receipt_id = null;
        $facturapi_invoice_url = null;
        $facturapi_success = false;

        if ($empresa_plan === 'premium') {
            error_log("🎯 Plan Premium detectado - Generando recibo Facturapi");

            try {
                // Obtener la API Key de la sesión
                $facturapi_api_key = $_SESSION['test_api_key'] ?? $test_api_key_working;
                if (empty($facturapi_api_key)) {
                    throw new Exception("No se encontró API Key de Facturapi");
                }

                error_log("🔑 Usando API Key: " . substr($facturapi_api_key, 0, 10) . "...");
                $facturapi = new Facturapi($facturapi_api_key);

                // Preparar items para Facturapi
                $facturapi_items = [];
                foreach ($_SESSION['carrito'] as $item) {
                    // Obtener el facturapi_producto_id del producto
                    $sql_producto_facturapi = "SELECT facturapi_producto_id FROM productos WHERE id = ?";
                    $stmt_producto = $conn->prepare($sql_producto_facturapi);
                    if ($stmt_producto) {
                        $stmt_producto->execute([$item['id']]);
                        $producto_data = $stmt_producto->fetch();
                        if ($producto_data && !empty($producto_data['facturapi_producto_id'])) {
                            $facturapi_items[] = [
                                "quantity" => (float)$item['cantidad'],
                                "product" => $producto_data['facturapi_producto_id']
                            ];
                            error_log("📦 Producto agregado a Facturapi: ID {$item['id']}, Producto Facturapi: {$producto_data['facturapi_producto_id']}");
                        } else {
                            error_log("⚠️ Producto ID {$item['id']} no tiene facturapi_producto_id");
                        }
                    }
                }

                if (empty($facturapi_items)) {
                    throw new Exception("No se encontraron productos válidos para Facturapi. Verifica que los productos tengan facturapi_producto_id.");
                }

                // Mapear métodos de pago
                $payment_form_map = [
                    'efectivo' => '01',
                    'tarjeta' => '04',
                    'transferencia' => '03'
                ];
                $payment_form = $payment_form_map[$metodo_pago] ?? '01';

                // Usar $codigo_venta como folio
                $folio_number = preg_replace('/[^0-9]/', '', $codigo_venta);
                if (empty($folio_number)) {
                    $folio_number = time();
                }

                // Datos del cliente si existe
                $customer_data = [];
                if (!empty($cliente_id)) {
                    $sql_cliente = "SELECT rfc, nombre, email, telefono, direccion FROM clientes WHERE id = ?";
                    $stmt_cliente = $conn->prepare($sql_cliente);
                    $stmt_cliente->execute([$cliente_id]);
                    $cliente_info = $stmt_cliente->fetch();
                    
                    if ($cliente_info) {
                        $customer_data = [
                            "legal_name" => $cliente_info['nombre'],
                            "tax_id" => $cliente_info['rfc'] ?? '',
                            "email" => $cliente_info['email'] ?? '',
                            "phone" => $cliente_info['telefono'] ?? ''
                        ];
                        error_log("👤 Cliente encontrado para Facturapi: " . $cliente_info['nombre']);
                    }
                }

                // Crear recibo en Facturapi
                $receipt_data = [
                    "folio_number" => intval($folio_number),
                    "payment_form" => $payment_form,
                    "items" => $facturapi_items
                ];

                // Agregar customer si existe
                if (!empty($customer_data)) {
                    $receipt_data["customer"] = $customer_data;
                }

                error_log("📝 Creando recibo Facturapi con datos: " . json_encode($receipt_data, JSON_PRETTY_PRINT));
                
                $receipt = $facturapi->Receipts->create($receipt_data);
                $facturapi_receipt_id = $receipt->id;
                $facturapi_invoice_url = $receipt->self_invoice_url ?? $receipt->url ?? null;
                $facturapi_success = true;

                error_log("✅ Recibo Facturapi creado: " . $facturapi_receipt_id);
                error_log("🔗 URL de facturación: " . $facturapi_invoice_url);

                // Actualizar la venta con el ID del recibo de Facturapi Y LA URL
                $sql_update_venta_facturapi = "UPDATE ventas SET facturapi_receipt_id = ?, urlfacturacion = ? WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update_venta_facturapi);
                if ($stmt_update) {
                    $stmt_update->execute([$facturapi_receipt_id, $facturapi_invoice_url, $venta_id]);
                    error_log("✅ Venta actualizada con facturapi_receipt_id y urlfacturacion");
                }

            } catch (Exception $e) {
                error_log("❌ Error al crear recibo Facturapi: " . $e->getMessage());
                error_log("❌ Stack trace: " . $e->getTraceAsString());
                
                // No cancelamos la venta, solo mostramos advertencia
                $_SESSION['warning_message'] = "Venta realizada, pero no se pudo generar el recibo electrónico: " . $e->getMessage();
                $facturapi_success = false;
            }
        } else {
            error_log("ℹ️ Plan $empresa_plan - No se genera recibo Facturapi");
        }
        // ========== FIN FACTURAPI ==========

        $conn->commit();

        // Preparar datos de venta para la sesión
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
            'timbres_disponibles' => $timbres_disponibles,
            'facturapi_receipt_id' => $facturapi_receipt_id,
            'facturapi_invoice_url' => $facturapi_invoice_url
            ];

            $_SESSION['carrito'] = [];
            unset($_SESSION['cliente_venta']);

            header("Location: caja.php?venta_exitosa=true");
            exit();

        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error_message'] = "Error al procesar la venta: " . $e->getMessage();
            error_log("❌ Error en venta: " . $e->getMessage());
        }
    }



// ========== CALCULAR TOTALES DEL CARRITO ==========
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
    <link rel="stylesheet" href="css/cajas.css">
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars($color_primario); ?>;
            --secondary-color: <?php echo htmlspecialchars($color_secundario); ?>;
            --dark-green: <?php echo htmlspecialchars($color_primario); ?>;
            --light-green: <?php echo htmlspecialchars($color_primario); ?>20;
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

    <!-- Modal para asignar comisión a un producto del carrito -->
    <div class="modal fade" id="asignarComisionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-tag me-2"></i>Asignar Comisión</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Producto: <strong id="comisionProductoNombre"></strong></p>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Área</label>
                            <select class="form-select form-select-sm" id="comisionArea"></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Concepto / Rol</label>
                            <select class="form-select form-select-sm" id="comisionRegla"></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Colaborador</label>
                            <select class="form-select form-select-sm" id="comisionColaborador"></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">% de reparto (si el rol se divide entre varios)</label>
                            <select class="form-select form-select-sm" id="comisionPorcentajeReparto">
                                <option value="100">100% (una sola persona)</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="button" class="btn btn-success btn-sm w-100" id="btnAgregarComisionLinea">
                                <i class="fas fa-plus me-1"></i>Agregar a la lista
                            </button>
                        </div>
                    </div>

                    <table class="table table-sm">
                        <thead><tr><th>Área</th><th>Concepto</th><th>Colaborador</th><th>% reparto</th><th></th></tr></thead>
                        <tbody id="comisionesListaTbody"></tbody>
                    </table>
                    <small class="text-muted">Estas comisiones se guardarán al confirmar el pago de la venta.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
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
                                        <i class="fas fa-credit-card me-2"></i>Tarjeta
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

                    <div class="mb-4">
                        <h6 class="section-title">
                            <i class="fas fa-comment-alt me-2"></i>Descripción (Opcional)
                        </h6>
                        <textarea class="form-control" id="modal-descripcion" rows="2" maxlength="500"
                            placeholder="Agregar nota o descripción para esta venta (opcional)..."
                            data-form-field="true"></textarea>
                        <small class="text-muted">Máximo 500 caracteres</small>
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
                        <input type="hidden" name="descripcion" id="modal-descripcionHidden" value="">
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
                                <?php if ($clientes): ?>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?php echo $cliente['id']; ?>"
                                            <?php echo ($_SESSION['cliente_venta'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
                                                <?php if (!empty($item['costo'])): ?>
                                                    <br><small class="text-muted" title="Costo del producto, solo informativo, no afecta el precio de venta">
                                                        <i class="fas fa-tag me-1"></i>Costo: $<?php echo number_format((float)$item['costo'], 2); ?>
                                                    </small>
                                                <?php endif; ?>
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
                                                    <button type="button" class="btn btn-sm btn-outline-success btn-asignar-comision"
                                                            data-index="<?php echo $index; ?>"
                                                            data-producto-id="<?php echo $item['id']; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                        <i class="fas fa-user-tag me-1"></i>Comisión
                                                        <?php if (!empty($item['comisiones'])): ?>
                                                            <span class="badge bg-success ms-1"><?php echo count($item['comisiones']); ?></span>
                                                        <?php endif; ?>
                                                    </button>
                                                </div>
                                            </td>
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
                                try {
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
                                    $stmt_cat->execute([$_SESSION['sucursal_id']]);
                                    $categorias_select = $stmt_cat->fetchAll();

                                    if ($categorias_select) {
                                        foreach ($categorias_select as $categoria):
                                            $producto_count = $categoria['producto_count'];
                                ?>
                                            <option value="<?php echo $categoria['id']; ?>"
                                                <?php echo $categoria_seleccionada == $categoria['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                (<?php echo $producto_count; ?> productos)
                                            </option>
                                <?php
                                        endforeach;
                                    }
                                } catch (PDOException $e) {
                                    error_log("Error al obtener categorías para select: " . $e->getMessage());
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
                                        foreach ($categorias_con_count as $cat) {
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
                                    try {
                                        $sql_check_mayoreo = "SELECT COUNT(*) as tiene_mayoreo FROM producto_precios_mayoreo WHERE producto_id = ? AND activo = 1";
                                        $stmt_mayoreo_check = $conn->prepare($sql_check_mayoreo);
                                        $stmt_mayoreo_check->execute([$producto['id']]);
                                        $row_mayoreo = $stmt_mayoreo_check->fetch();
                                        $tiene_mayoreo = $row_mayoreo['tiene_mayoreo'] > 0;
                                    } catch (PDOException $e) {
                                        $tiene_mayoreo = false;
                                        error_log("Error al verificar mayoreo: " . $e->getMessage());
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
                                try {
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

                                    $stmt_cat_mobile = $conn->prepare($sql_categorias_select);
                                    $stmt_cat_mobile->execute([$_SESSION['sucursal_id']]);
                                    $categorias_mobile = $stmt_cat_mobile->fetchAll();

                                    if ($categorias_mobile) {
                                        foreach ($categorias_mobile as $categoria):
                                            $producto_count = $categoria['producto_count'];
                                ?>
                                            <option value="<?php echo $categoria['id']; ?>"
                                                <?php echo $categoria_seleccionada == $categoria['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                (<?php echo $producto_count; ?>)
                                            </option>
                                <?php
                                        endforeach;
                                    }
                                } catch (PDOException $e) {
                                    error_log("Error al obtener categorías para móvil: " . $e->getMessage());
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
                                        
                                        try {
                                            $sql_check_mayoreo_mobile = "SELECT COUNT(*) as tiene_mayoreo FROM producto_precios_mayoreo WHERE producto_id = ? AND activo = 1";
                                            $stmt_mayoreo_check_mobile = $conn->prepare($sql_check_mayoreo_mobile);
                                            $stmt_mayoreo_check_mobile->execute([$producto['id']]);
                                            $row_mayoreo_mobile = $stmt_mayoreo_check_mobile->fetch();
                                            $tiene_mayoreo_mobile = $row_mayoreo_mobile['tiene_mayoreo'] > 0;
                                        } catch (PDOException $e) {
                                            $tiene_mayoreo_mobile = false;
                                            error_log("Error al verificar mayoreo móvil: " . $e->getMessage());
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
                                                        <?php if (!empty($item['costo'])): ?>
                                                            <p class="card-text text-muted small mb-1" title="Costo del producto, solo informativo, no afecta el precio de venta">
                                                                <i class="fas fa-tag me-1"></i>Costo: $<?php echo number_format((float)$item['costo'], 2); ?>
                                                            </p>
                                                        <?php endif; ?>
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
                                                            <button type="button" class="btn btn-sm btn-outline-success btn-asignar-comision"
                                                                    data-index="<?php echo $index; ?>"
                                                                    data-producto-id="<?php echo $item['id']; ?>"
                                                                    data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                                <i class="fas fa-user-tag me-1"></i>Comisión
                                                                <?php if (!empty($item['comisiones'])): ?>
                                                                    <span class="badge bg-success ms-1"><?php echo count($item['comisiones']); ?></span>
                                                                <?php endif; ?>
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
                                <?php if ($clientes): ?>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?php echo $cliente['id']; ?>"
                                            <?php echo ($_SESSION['cliente_venta'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
                                    <td class="label">IVA (0%):</td>
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
    window.CajaConfig = {
        carrito: <?php echo json_encode($_SESSION['carrito'] ?? []); ?>,
        sucursalId: <?php echo $_SESSION['sucursal_id'] ?? 0; ?>,
        clienteActual: '<?php echo $_SESSION['cliente_venta'] ?? ''; ?>',
        busquedaNombre: '<?php echo addslashes($busqueda_nombre ?? ''); ?>',
        
        // Datos de la venta realizada
        ventaRealizada: <?php echo isset($_SESSION['venta_realizada']) ? json_encode($_SESSION['venta_realizada']) : 'null'; ?>,
        ventaId: <?php echo isset($_SESSION['venta_realizada']['venta_id']) ? $_SESSION['venta_realizada']['venta_id'] : '0'; ?>,
        
        // Totales iniciales
        totalInicial: <?php echo $total_carrito ?? 0; ?>,
        subtotalInicial: <?php echo $subtotal_carrito ?? 0; ?>,
        descuentoInicial: <?php echo $descuento_carrito ?? 0; ?>,
        subtotalConDescuentoInicial: <?php echo $subtotal_con_descuento_carrito ?? 0; ?>,
        carritoCountInicial: <?php echo count($_SESSION['carrito'] ?? []); ?>
    };
</script>

<script src="js/cajas.js"></script>
</body>  

</html>