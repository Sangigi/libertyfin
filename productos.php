<?php
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_secure', 1);   // cambiar a 1, tu sitio es HTTPS
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();
require 'vendor/autoload.php';

use Facturapi\Facturapi;

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Login");
    exit();
}

// Variables para Facturapi
$organizacion = null;
$mensaje = '';
$tipo_mensaje = ''; // success, danger, warning
$api_key = ''; // API Key de Facturapi (sk_user)
$organization_id = ''; // ID de organización de Facturapi
$test_api_key = null; // Nueva variable para API Key de prueba (sk_test)

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Obtener colores personalizados de la configuración
$color_primario = '#27ae60';
$color_secundario = '#2ecc71';

// OBTENER EL PLAN DE LA EMPRESA Y DATOS DE TIMBRES DESDE LA BASE DE DATOS PRINCIPAL
$empresa_plan = 'prueba'; // Valor por defecto
$timbres_totales = 0;
$timbres_disponibles = 0;
$servername_main = "libertyfin.com.mx";
$username_main = "juanc141_alexis";
$password_main = "Alexis1997";
$dbname_main = "juanc141_ventas";

$conn_main = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

$mostrar_precio_compra = true;
$mostrar_unidad_medida = true;
$mostrar_proveedor = true;
$mostrar_fecha_caducidad = true;
$mostrar_categoria = true;
$mostrar_tipo_producto = true;
$mostrar_merma = true;
$tipos_unidad_permitidos = ['pieza', 'kilo', 'litro'];
$tipos_producto_permitidos = ['Estandar', 'Premium', 'Económico'];
$config_merma = [
    'porcentaje_danado' => 0,
    'porcentaje_deshidratacion' => 0,
    'aplicar_merma_venta' => 0,
    'aplicar_merma_compra' => 0
];

// Conectar a la base de datos principal para obtener características
$conn_main_caract = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

if (!$conn_main_caract->connect_error) {
    // Verificar si la tabla existe
    $check_table = "SHOW TABLES LIKE 'empresa_caracteristicas'";
    $table_exists = $conn_main_caract->query($check_table);

    if ($table_exists && $table_exists->num_rows > 0) {
        $sql_caract = "SELECT caracteristica, habilitado, configuracion_extra 
                       FROM empresa_caracteristicas 
                       WHERE empresa_id = ?";
        $stmt_caract = $conn_main_caract->prepare($sql_caract);

        if ($stmt_caract) {
            $stmt_caract->bind_param("i", $_SESSION['empresa_id']);
            $stmt_caract->execute();
            $result_caract = $stmt_caract->get_result();

            while ($row = $result_caract->fetch_assoc()) {
                switch ($row['caracteristica']) {
                    case 'precio_compra':
                        $mostrar_precio_compra = (bool)$row['habilitado'];
                        break;
                    case 'unidad_medida':
                        $mostrar_unidad_medida = (bool)$row['habilitado'];
                        if (!empty($row['configuracion_extra'])) {
                            $tipos = json_decode($row['configuracion_extra'], true);
                            if (is_array($tipos) && !empty($tipos)) {
                                $tipos_unidad_permitidos = $tipos;
                            }
                        }
                        break;
                    case 'proveedor':
                        $mostrar_proveedor = (bool)$row['habilitado'];
                        break;
                    case 'fecha_caducidad':
                        $mostrar_fecha_caducidad = (bool)$row['habilitado'];
                        break;
                    case 'categoria':
                        $mostrar_categoria = (bool)$row['habilitado'];
                        break;
                    case 'tipo_producto':
                        $mostrar_tipo_producto = (bool)$row['habilitado'];
                        if (!empty($row['configuracion_extra'])) {
                            $tipos = json_decode($row['configuracion_extra'], true);
                            if (is_array($tipos) && !empty($tipos)) {
                                $tipos_producto_permitidos = $tipos;
                            }
                        }
                        break;
                    case 'merma':
                        $mostrar_merma = (bool)$row['habilitado'];
                        if (!empty($row['configuracion_extra'])) {
                            $config_temp = json_decode($row['configuracion_extra'], true);
                            if (is_array($config_temp)) {
                                $config_merma = array_merge($config_merma, $config_temp);
                            }
                        }
                        break;
                }
            }
            $stmt_caract->close();
        }
    }
}
$conn_main_caract->close();

// Si la unidad de medida está deshabilitada, forzar valores por defecto
if (!$mostrar_unidad_medida) {
    $tipos_unidad_permitidos = ['pieza'];
}

// Si tipo producto está deshabilitado, valores por defecto
if (!$mostrar_tipo_producto) {
    $tipos_producto_permitidos = ['Estandar'];
}

// Variables para CSS (ocultar/mostrar secciones completas)
$hide_precio_compra_style = $mostrar_precio_compra ? '' : 'style="display: none;"';
$hide_unidad_medida_style = $mostrar_unidad_medida ? '' : 'style="display: none;"';
$hide_proveedor_style = $mostrar_proveedor ? '' : 'style="display: none;"';
$hide_fecha_caducidad_style = $mostrar_fecha_caducidad ? '' : 'style="display: none;"';
$hide_categoria_style = $mostrar_categoria ? '' : 'style="display: none;"';
$hide_tipo_producto_style = $mostrar_tipo_producto ? '' : 'style="display: none;"';
$hide_merma_style = $mostrar_merma ? '' : 'style="display: none;"';

// API Key de Facturapi - FIJA
$api_key = "sk_user_LV9Sw1JcA15AUyxSfD53ntQH6sCMiYmRRMP6tpJCi2";

if (!$conn_main->connect_error) {
    $sql_empresa = "SELECT plan, facturapi_organization_id, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->bind_param("i", $_SESSION['empresa_id']);
    $stmt_empresa->execute();
    $result_empresa = $stmt_empresa->get_result();

    if ($result_empresa && $result_empresa->num_rows > 0) {
        $empresa_data = $result_empresa->fetch_assoc();
        $empresa_plan = $empresa_data['plan'];
        $organization_id = $empresa_data['facturapi_organization_id'] ?? null;
        $timbres_totales = $empresa_data['timbres_totales'] ?? 0;
        $timbres_disponibles = $empresa_data['timbres_disponibles'] ?? 0;
    }
    $stmt_empresa->close();
    $conn_main->close();
}

// CARGAR DATOS DE LA ORGANIZACIÓN SI TENEMOS CREDENCIALES
if (!empty($api_key) && !empty($organization_id)) {
    try {
        $facturapi = new Facturapi($api_key);
        $organizacion = $facturapi->Organizations->retrieve($organization_id);

        // OBTENER API KEY DE PRUEBA DINÁMICAMENTE
        try {
            $test_api_key = $facturapi->Organizations->getTestApiKey($organization_id);
            $_SESSION['test_api_key'] = $test_api_key;
            $test_api_key_working = $test_api_key;
        } catch (Exception $e) {
            $test_api_key_error = $e->getMessage();
            error_log("Error al obtener API Key de prueba: " . $test_api_key_error);
            $test_api_key_working = null;
        }
    } catch (Exception $e) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $mensaje = 'Error al cargar datos: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
        $test_api_key_working = null;
    }
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if (!$conn->connect_error) {
        $sql_config = "SELECT color_primario, color_secundario, stock_minimo_global FROM sistema_config LIMIT 1";
        $result_config = $conn->query($sql_config);
        if ($result_config && $result_config->num_rows > 0) {
            $config_colores = $result_config->fetch_assoc();
            $color_primario = $config_colores['color_primario'] ?? $color_primario;
            $color_secundario = $config_colores['color_secundario'] ?? $color_secundario;
            $stock_minimo_global = $config_colores['stock_minimo_global'] ?? 5;

            $_SESSION['color_primario'] = $color_primario;
            $_SESSION['color_secundario'] = $color_secundario;
        }
    }
} catch (Exception $e) {
    $stock_minimo_global = 5;
}

// =============================================
// FUNCIONES PARA MÚLTIPLES IMÁGENES
// =============================================

/**
 * Función para subir múltiples imágenes
 * @param array $files Archivos subidos ($_FILES)
 * @param int $producto_id ID del producto
 * @return array Rutas de las imágenes subidas
 */
function subirMultiplesImagenes($files, $producto_id)
{
    $imagenes_subidas = [];

    if (!isset($files['imagenes']) || empty($files['imagenes']['tmp_name'][0])) {
        error_log("No hay archivos para subir");
        return $imagenes_subidas;
    }

    // Crear directorio si no existe - Usar ruta absoluta
    $directorio = $_SERVER['DOCUMENT_ROOT'] . "/uploads/productos/";
    $directorio_relativo = "/uploads/productos/";

    // También intentar con ruta relativa desde el script actual
    if (!is_dir($directorio)) {
        $directorio = dirname(__FILE__) . "/uploads/productos/";
        $directorio_relativo = "uploads/productos/";
    }

    // Crear directorio si no existe
    if (!is_dir($directorio)) {
        if (mkdir($directorio, 0777, true)) {
            error_log("Directorio creado: " . $directorio);
        } else {
            error_log("ERROR: No se pudo crear el directorio: " . $directorio);
            return $imagenes_subidas;
        }
    }

    // Verificar permisos
    if (!is_writable($directorio)) {
        error_log("ERROR: El directorio no tiene permisos de escritura: " . $directorio);
        return $imagenes_subidas;
    }

    // Validar tipos de archivo
    $tiposPermitidos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_imagenes = 5; // Máximo 5 imágenes

    // Procesar cada imagen (limitar a 5)
    $total_imagenes = count($files['imagenes']['tmp_name']);
    for ($key = 0; $key < min($total_imagenes, $max_imagenes); $key++) {
        // Verificar si hubo error en la subida
        if ($files['imagenes']['error'][$key] !== UPLOAD_ERR_OK) {
            error_log("Error al subir imagen {$key}: Código " . $files['imagenes']['error'][$key]);
            continue;
        }

        // Validar tipo MIME
        $tipoArchivo = mime_content_type($files['imagenes']['tmp_name'][$key]);
        if (!in_array($tipoArchivo, $tiposPermitidos)) {
            error_log("Tipo de archivo no permitido: " . $tipoArchivo . " para archivo: " . $files['imagenes']['name'][$key]);
            continue;
        }

        // Validar tamaño (4MB máximo por imagen)
        if ($files['imagenes']['size'][$key] > 4 * 1024 * 1024) {
            error_log("Imagen demasiado grande: " . $files['imagenes']['size'][$key] . " bytes - " . $files['imagenes']['name'][$key]);
            continue;
        }

        // Generar nombre único
        $extension = strtolower(pathinfo($files['imagenes']['name'][$key], PATHINFO_EXTENSION));
        $nombreArchivo = "producto_{$producto_id}_" . time() . "_" . uniqid() . "." . $extension;

        $rutaCompleta = $directorio . $nombreArchivo;
        $rutaRelativa = $directorio_relativo . $nombreArchivo;

        // Mover archivo
        if (move_uploaded_file($files['imagenes']['tmp_name'][$key], $rutaCompleta)) {
            $imagenes_subidas[] = $rutaRelativa;
            error_log("✅ Imagen subida exitosamente: " . $rutaRelativa);
            error_log("   Ruta física: " . $rutaCompleta);
        } else {
            error_log("❌ Error al mover el archivo desde: " . $files['imagenes']['tmp_name'][$key] . " a: " . $rutaCompleta);
        }
    }

    error_log("Total imágenes subidas: " . count($imagenes_subidas));
    return $imagenes_subidas;
}

/**
 * Función para guardar imágenes en la base de datos
 * @param mysqli $conn Conexión a la base de datos
 * @param int $producto_id ID del producto
 * @param array $imagenes Array con rutas de imágenes
 * @param int $principal_index Índice de la imagen principal
 */
function guardarImagenesProducto($conn, $producto_id, $imagenes, $principal_index = 0)
{
    error_log("=== INICIANDO guardarImagenesProducto ===");
    error_log("Producto ID: " . $producto_id);
    error_log("Imágenes a guardar: " . print_r($imagenes, true));
    error_log("Índice principal: " . $principal_index);

    // Verificar conexión
    if (!$conn || $conn->connect_error) {
        error_log("ERROR: Conexión a BD inválida");
        return false;
    }

    // Primero, eliminar imágenes existentes
    $sql_delete = "DELETE FROM producto_imagenes WHERE producto_id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    if (!$stmt_delete) {
        error_log("ERROR preparando DELETE: " . $conn->error);
        return false;
    }

    $stmt_delete->bind_param("i", $producto_id);
    if (!$stmt_delete->execute()) {
        error_log("ERROR ejecutando DELETE: " . $stmt_delete->error);
        $stmt_delete->close();
        return false;
    }
    $stmt_delete->close();
    error_log("✓ Imágenes existentes eliminadas");

    // Insertar nuevas imágenes
    $insertados = 0;
    foreach ($imagenes as $index => $ruta_imagen) {
        // Determinar si es la imagen principal
        $es_principal = ($index == $principal_index) ? 1 : 0;
        $orden = $index;

        // Asegurar que la ruta no tenga duplicados de /
        $ruta_imagen = str_replace('//', '/', $ruta_imagen);

        $sql_insert = "INSERT INTO producto_imagenes (producto_id, ruta_imagen, orden, es_principal) 
                       VALUES (?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);

        if (!$stmt_insert) {
            error_log("ERROR preparando INSERT: " . $conn->error);
            continue;
        }

        $stmt_insert->bind_param("isii", $producto_id, $ruta_imagen, $orden, $es_principal);

        if ($stmt_insert->execute()) {
            $insertados++;
            error_log("✓ Imagen $index guardada en BD - Principal: " . ($es_principal ? "SÍ" : "NO") . " - Ruta: $ruta_imagen");
        } else {
            error_log("❌ Error insertando imagen $index: " . $stmt_insert->error);
        }

        $stmt_insert->close();
    }

    error_log("=== FINALIZADO: $insertados de " . count($imagenes) . " imágenes guardadas ===");
    return $insertados > 0;
}

function verificarDirectorioUploads()
{
    $directorios = [
        __DIR__ . "/uploads/productos/",
        $_SERVER['DOCUMENT_ROOT'] . "/uploads/productos/",
        "uploads/productos/"
    ];

    $resultados = [];
    foreach ($directorios as $dir) {
        $existe = is_dir($dir);
        $escribible = is_writable($dir);
        $resultados[] = [
            'ruta' => $dir,
            'existe' => $existe,
            'escribible' => $escribible,
            'permisos' => $existe ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A'
        ];

        if (!$existe) {
            @mkdir($dir, 0777, true);
            error_log("Directorio creado: " . $dir);
        }
    }

    error_log("=== VERIFICACIÓN DE DIRECTORIOS ===");
    foreach ($resultados as $r) {
        error_log("Ruta: " . $r['ruta']);
        error_log("  - Existe: " . ($r['existe'] ? 'Sí' : 'No'));
        error_log("  - Escribible: " . ($r['escribible'] ? 'Sí' : 'No'));
        error_log("  - Permisos: " . $r['permisos']);
    }

    return $resultados;
}

/**
 * Función para obtener todas las imágenes de un producto
 * @param mysqli $conn Conexión a la base de datos
 * @param int $producto_id ID del producto
 * @return array Array con las imágenes del producto
 */
function obtenerImagenesProducto($conn, $producto_id)
{
    $imagenes = [];

    $sql = "SELECT id, ruta_imagen, orden, es_principal 
            FROM producto_imagenes 
            WHERE producto_id = ? 
            ORDER BY es_principal DESC, orden ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $imagenes[] = $row;
    }
    $stmt->close();

    return $imagenes;
}

/**
 * Función para eliminar imágenes de un producto
 * @param mysqli $conn Conexión a la base de datos
 * @param int $producto_id ID del producto
 * @param array $excluir_ids IDs de imágenes a excluir
 */
function eliminarImagenesProducto($conn, $producto_id, $excluir_ids = [])
{
    if (empty($excluir_ids)) {
        // Eliminar todas las imágenes del producto
        $sql = "DELETE FROM producto_imagenes WHERE producto_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Eliminar imágenes específicas
        $ids_str = implode(',', array_map('intval', $excluir_ids));
        $sql = "DELETE FROM producto_imagenes WHERE producto_id = ? AND id NOT IN ($ids_str)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $stmt->close();
    }
}

function verificarLimiteProductos($conn, $plan)
{
    // Obtener el total de productos activos
    $sql_count = "SELECT COUNT(*) as total FROM productos WHERE activo = 1";
    $result = $conn->query($sql_count);
    $row = $result->fetch_assoc();
    $total_productos = $row['total'];

    // Definir límites según el plan
    $limites = [
        'prueba' => 100,
        'basico' => 100,
        'emprendedor' => 500,
        'premium' => PHP_INT_MAX // ilimitado
    ];

    $limite = isset($limites[$plan]) ? $limites[$plan] : 100;

    return [
        'total' => $total_productos,
        'limite' => $limite,
        'disponibles' => max(0, $limite - $total_productos),
        'alcanzado' => $total_productos >= $limite
    ];
}

// Función para generar código automático
function generarCodigoAutomatico($conn, $prefijo = 'PROD')
{
    // Buscar el último código con el prefijo
    $sql = "SELECT MAX(CAST(SUBSTRING(codigo, LENGTH(?) + 1) AS UNSIGNED)) as ultimo_num 
            FROM productos 
            WHERE codigo LIKE CONCAT(?, '%') 
            AND codigo REGEXP '^' || ? || '[0-9]+$'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $prefijo, $prefijo, $prefijo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $ultimo_num = $row['ultimo_num'] ? intval($row['ultimo_num']) : 0;
    $nuevo_num = $ultimo_num + 1;

    // Formatear con ceros a la izquierda
    $codigo = sprintf('%s%04d', $prefijo, $nuevo_num);

    // Verificar que no exista
    $sql_check = "SELECT COUNT(*) as existe FROM productos WHERE codigo = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("s", $codigo);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    $stmt_check->close();

    if ($row_check['existe'] > 0) {
        // Si por alguna razón existe, intentar con el siguiente número
        return generarCodigoAutomatico($conn, $prefijo);
    }

    return $codigo;
}

// Configuración de paginación
$registros_por_pagina = 5;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Conectar a la base de datos de la empresa
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener información del límite de productos
    $limite_info = verificarLimiteProductos($conn, $empresa_plan);
    $limite_alcanzado = $limite_info['alcanzado'];
    $productos_disponibles = $limite_info['disponibles'];
    $total_productos_activos = $limite_info['total'];
    $limite_productos = $limite_info['limite'];

    // Obtener información de la empresa
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo, stock_minimo_global FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch_assoc();
    $stock_minimo_global = $empresa_info['stock_minimo_global'] ?? 5;

    // OBTENER LOGO DE LA EMPRESA
    $logo_empresa = null;
    $logo_src_base64 = null;

    if (!empty($empresa_info['logo'])) {
        $empresa_logo = $empresa_info['logo'];
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

        // Si encontramos el logo, convertirlo a base64
        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;

            // Obtener la extensión del archivo
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));

            // Verificar que sea una imagen válida
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                // Leer el archivo y convertirlo a base64
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
    }

    // Construir condiciones WHERE dinámicamente
    $where_conditions = "WHERE 1=1";
    $params = [];
    $types = "";

    // Obtener parámetros de filtro
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : '';
    $proveedor_filtro = isset($_GET['proveedor']) ? $_GET['proveedor'] : '';
    $sucursal_filtro = isset($_GET['sucursal']) ? $_GET['sucursal'] : '';
    $show_inactive = isset($_GET['show_inactive']) ? true : false;

    // Aplicar filtros si existen
    if (!empty($search)) {
        $search_term = "%" . $search . "%";
        $where_conditions .= " AND (p.codigo LIKE ? OR p.nombre LIKE ? OR p.marca LIKE ? OR p.descripcion LIKE ?)";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
        $types .= "ssss";
    }

    if (!empty($categoria_filtro)) {
        $where_conditions .= " AND p.categoria_id = ?";
        $params[] = $categoria_filtro;
        $types .= "i";
    }

    if (!empty($proveedor_filtro)) {
        $where_conditions .= " AND p.proveedor_id = ?";
        $params[] = $proveedor_filtro;
        $types .= "i";
    }

    if (!empty($sucursal_filtro)) {
        $where_conditions .= " AND ps.sucursal_id = ?";
        $params[] = $sucursal_filtro;
        $types .= "i";
    }

    if (!$show_inactive) {
        $where_conditions .= " AND p.activo = 1";
    }

    // Obtener el total de registros para paginación
    $sql_count = "SELECT COUNT(DISTINCT p.id) as total 
                  FROM productos p 
                  LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id 
                  $where_conditions";

    if (!empty($params)) {
        $stmt_count = $conn->prepare($sql_count);
        $stmt_count->bind_param($types, ...$params);
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
    } else {
        $result_count = $conn->query($sql_count);
    }

    $total_registros = $result_count->fetch_assoc()['total'];
    if (isset($stmt_count)) $stmt_count->close();

    // Calcular total de páginas
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // Obtener productos con información de múltiples sucursales con LIMIT para paginación
    $sql_productos = "
    SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre,
           p.tipo_producto, p.porcentaje_merma_danado, p.porcentaje_merma_deshidratacion,
           p.aplicar_merma_venta, p.aplicar_merma_compra,
           COALESCE(GROUP_CONCAT(DISTINCT ps.sucursal_id), '') as sucursales_ids,
           COALESCE(GROUP_CONCAT(DISTINCT s.nombre SEPARATOR ', '), 'Sin sucursales') as sucursales_nombres,
           COALESCE(SUM(ps.stock), 0) as stock_total,
           COALESCE(MIN(ps.stock_minimo), 0) as stock_minimo_total
    FROM productos p 
    LEFT JOIN categorias c ON p.categoria_id = c.id 
    LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
    LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
    LEFT JOIN sucursales s ON ps.sucursal_id = s.id
    $where_conditions
    GROUP BY p.id
    ORDER BY p.fecha_creacion DESC, p.id DESC
    LIMIT ? OFFSET ?
";

    // Agregar parámetros para LIMIT y OFFSET
    $params_limit = array_merge($params, [$registros_por_pagina, $offset]);
    $types_limit = $types . "ii";

    $stmt = $conn->prepare($sql_productos);
    if (!empty($params_limit)) {
        $stmt->bind_param($types_limit, ...$params_limit);
    }
    $stmt->execute();
    $result_productos = $stmt->get_result();
    $productos = [];
    while ($row = $result_productos->fetch_assoc()) {
        $productos[] = $row;
    }
    $stmt->close();

    // Obtener stock por sucursal para cada producto
    $stock_por_sucursal = [];
    $sql_stock = "SELECT producto_id, sucursal_id, stock, stock_minimo FROM producto_sucursal";
    $result_stock = $conn->query($sql_stock);
    while ($row = $result_stock->fetch_assoc()) {
        $stock_por_sucursal[$row['producto_id']][$row['sucursal_id']] = [
            'stock' => $row['stock'],
            'stock_minimo' => $row['stock_minimo']
        ];
    }

    // Obtener imágenes de productos
    $imagenes_por_producto = [];
    if (!empty($productos)) {
        $productos_ids = array_column($productos, 'id');
        $ids_str = implode(',', $productos_ids);
        $sql_imagenes = "SELECT * FROM producto_imagenes WHERE producto_id IN ($ids_str) ORDER BY producto_id, es_principal DESC, orden ASC";
        $result_imagenes = $conn->query($sql_imagenes);
        while ($row_img = $result_imagenes->fetch_assoc()) {
            $producto_id = $row_img['producto_id'];
            if (!isset($imagenes_por_producto[$producto_id])) {
                $imagenes_por_producto[$producto_id] = [];
            }
            $imagenes_por_producto[$producto_id][] = $row_img;
        }
    }

    // Obtener precios de mayoreo para todos los productos
    $precios_mayoreo_por_producto = [];
    if (!empty($productos)) {
        $productos_ids = array_column($productos, 'id');
        $ids_str = implode(',', $productos_ids);
        $sql_mayoreo = "SELECT * FROM producto_precios_mayoreo WHERE producto_id IN ($ids_str) AND activo = 1 ORDER BY cantidad_minima ASC";
        $result_mayoreo = $conn->query($sql_mayoreo);
        while ($row_mayoreo = $result_mayoreo->fetch_assoc()) {
            $producto_id = $row_mayoreo['producto_id'];
            if (!isset($precios_mayoreo_por_producto[$producto_id])) {
                $precios_mayoreo_por_producto[$producto_id] = [];
            }
            $precios_mayoreo_por_producto[$producto_id][] = $row_mayoreo;
        }
    }

    // Obtener categorías
    $sql_categorias = "SELECT id, nombre FROM categorias WHERE activo = 1";
    $result_categorias = $conn->query($sql_categorias);
    $categorias = [];
    while ($row = $result_categorias->fetch_assoc()) {
        $categorias[] = $row;
    }

    // Obtener sucursales
    $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE activo = 1";
    $result_sucursales = $conn->query($sql_sucursales);
    $sucursales = [];
    while ($row = $result_sucursales->fetch_assoc()) {
        $sucursales[] = $row;
    }

    // Obtener proveedores
    $sql_proveedores = "SELECT id, nombre FROM proveedores WHERE activo = 1";
    $result_proveedores = $conn->query($sql_proveedores);
    $proveedores = [];
    while ($row = $result_proveedores->fetch_assoc()) {
        $proveedores[] = $row;
    }

    // Estadísticas (sin paginación para mostrar totales)
    $sql_stats = "
        SELECT 
            COUNT(*) as total_productos,
            SUM(CASE WHEN p.stock > 0 THEN 1 ELSE 0 END) as con_stock,
            SUM(CASE WHEN p.stock = 0 THEN 1 ELSE 0 END) as sin_stock,
            SUM(CASE WHEN p.stock > 0 AND p.stock <= ? THEN 1 ELSE 0 END) as bajo_stock
        FROM productos p
        WHERE p.activo = 1
    ";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->bind_param("i", $stock_minimo_global);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    $stats = $result_stats->fetch_assoc();
    $stmt_stats->close();

    $total_productos = $stats['total_productos'] ?? 0;
    $con_stock = $stats['con_stock'] ?? 0;
    $sin_stock = $stats['sin_stock'] ?? 0;
    $bajo_stock = $stats['bajo_stock'] ?? 0;

    $sql_valor = "SELECT SUM(p.precio * ps.stock) as valor_total 
              FROM productos p 
              INNER JOIN producto_sucursal ps ON p.id = ps.producto_id 
              WHERE p.activo = 1";
    $result_valor = $conn->query($sql_valor);
    $valor_row = $result_valor->fetch_assoc();
    $valor_total_inventario = $valor_row['valor_total'] ?? 0;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Función para crear producto en FacturaAPI
function crearProductoFacturapi($productoData, $test_api_key_working, $organization_id)
{
    // Si no hay organization_id, simplemente retornar éxito sin sincronización
    if (empty($organization_id)) {
        return [
            'success' => true,
            'facturapi_producto_id' => null,
            'message' => 'Producto sin facturación (sin organización)'
        ];
    }

    // Si no hay API key, retornar éxito sin sincronización
    if (empty($test_api_key_working)) {
        return [
            'success' => true,
            'facturapi_producto_id' => null,
            'message' => 'Producto sin facturación (sin API key)'
        ];
    }

    try {
        $facturapi = new Facturapi($test_api_key_working);

        // Determinar el product_key según la unidad de medida
        $product_key = '43211508'; // Por defecto para "pieza"
        if (isset($productoData['unidad_medida'])) {
            switch ($productoData['unidad_medida']) {
                case 'kilo':
                    $product_key = '43211601';
                    break;
                case 'litro':
                    $product_key = '43211602';
                    break;
                default:
                    $product_key = '43211508';
            }
        }

        // Determinar unit_key según unidad de medida
        $unit_key = 'H87';
        $unit_name = 'Pieza';
        if (isset($productoData['unidad_medida'])) {
            switch ($productoData['unidad_medida']) {
                case 'kilo':
                    $unit_key = 'KG';
                    $unit_name = 'Kilogramo';
                    break;
                case 'litro':
                    $unit_key = 'LTR';
                    $unit_name = 'Litro';
                    break;
            }
        }

        $facturapiData = [
            'description' => $productoData['nombre'],
            'product_key' => $product_key,
            'unit_key' => $unit_key,
            'unit_name' => $unit_name,
            'price' => floatval($productoData['precio']),
            'tax_included' => true,
            'taxability' => '02',
            'sku' => $productoData['codigo'],
            'taxes' => [
                [
                    'type' => 'IVA',
                    'rate' => 0.16,
                    'withholding' => false,
                    'factor' => 'Tasa'
                ]
            ]
        ];

        if (!empty($productoData['descripcion'])) {
            $facturapiData['description'] .= ' - ' . $productoData['descripcion'];
        }

        $response = $facturapi->Products->create($facturapiData);

        if (isset($response->id)) {
            return [
                'success' => true,
                'facturapi_producto_id' => $response->id,
                'message' => 'Producto creado exitosamente en FacturaAPI'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Error al crear producto en FacturaAPI: No se recibió ID'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error FacturaAPI: ' . $e->getMessage()
        ];
    }
}


/**
 * Formatea el stock según la unidad de medida
 * @param float $stock Cantidad
 * @param string $unidad_medida Unidad (kg, litro, tonelada, pieza, unidad)
 * @return string Stock formateado
 */
function formatearStockPorUnidad($stock, $unidad_medida)
{
    // Mostrar con 3 decimales solo si hay decimales significativos
    if (is_numeric($stock)) {
        // Verificar si tiene decimales
        $es_decimal = ($stock - floor($stock)) > 0;

        if ($es_decimal) {
            // Mostrar con 3 decimales máximo, quitando ceros innecesarios
            $stock_formateado = rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.');
        } else {
            $stock_formateado = number_format($stock, 0, '.', '');
        }
    } else {
        $stock_formateado = '0';
    }

    // Agregar sufijo según unidad
    $sufijo = '';
    switch ($unidad_medida) {
        case 'kg':
        case 'kilo':
        case 'kilogramo':
            $sufijo = ' kg';
            break;
        case 'litro':
        case 'l':
            $sufijo = ' L';
            break;
        case 'tonelada':
        case 'ton':
            $sufijo = ' ton';
            break;
        case 'pieza':
            $sufijo = ' piezas';
            break;
        case 'unidad':
            $sufijo = ' unidades';
            break;
        default:
            $sufijo = '';
    }

    // Para unidad y pieza, usar singular si es 1
    if (($unidad_medida == 'pieza' || $unidad_medida == 'unidad') && $stock == 1) {
        $sufijo = rtrim($sufijo, 's');
    }

    return $stock_formateado . $sufijo;
}

// Función para actualizar producto en FacturaAPI
function actualizarProductoFacturapi($facturapi_producto_id, $productoData, $empresa_plan, $test_api_key_working, $timbres_disponibles, $organization_id)
{
    // Verificar si tenemos organización configurada
    if (empty($organization_id)) {
        return [
            'success' => true,
            'facturapi_producto_id' => null,
            'message' => 'Producto actualizado solo localmente (sin organización)'
        ];
    }

    // Verificar si tenemos API key y timbres disponibles
    if (empty($test_api_key_working) || $timbres_disponibles <= 0) {
        return [
            'success' => true,
            'facturapi_producto_id' => null,
            'message' => 'Producto actualizado solo localmente (sin timbres o API key)'
        ];
    }

    try {
        // Configuración - usar la API key de prueba
        $facturapi = new Facturapi($test_api_key_working);

        // Determinar el product_key según la unidad de medida
        $product_key = '43211508'; // Por defecto para "pieza"
        if (isset($productoData['unidad_medida'])) {
            switch ($productoData['unidad_medida']) {
                case 'kilo':
                    $product_key = '43211601'; // Código para kilos
                    break;
                case 'litro':
                    $product_key = '43211602'; // Código para litros
                    break;
                default:
                    $product_key = '43211508'; // Código para piezas
            }
        }

        // Determinar unit_key según unidad de medida
        $unit_key = 'H87'; // Por defecto para "pieza"
        $unit_name = 'Pieza';
        if (isset($productoData['unidad_medida'])) {
            switch ($productoData['unidad_medida']) {
                case 'kilo':
                    $unit_key = 'KG'; // Kilogramos
                    $unit_name = 'Kilogramo';
                    break;
                case 'litro':
                    $unit_key = 'LTR'; // Litros
                    $unit_name = 'Litro';
                    break;
            }
        }

        // Preparar datos para FacturaAPI
        $facturapiData = [
            'description' => $productoData['nombre'],
            'product_key' => $product_key,
            'unit_key' => $unit_key,
            'unit_name' => $unit_name,
            'price' => floatval($productoData['precio']),
            'tax_included' => true,
            'taxability' => '02', // Sí objeto de impuesto
            'sku' => $productoData['codigo'],
            'taxes' => [
                [
                    'type' => 'IVA',
                    'rate' => 0.16, // 16%
                    'withholding' => false,
                    'factor' => 'Tasa'
                ]
            ]
        ];

        // Agregar descripción si existe
        if (!empty($productoData['descripcion'])) {
            $facturapiData['description'] .= ' - ' . $productoData['descripcion'];
        }

        // SI NO TIENE ID DE FACTURAPI, CREAR NUEVO PRODUCTO
        if (empty($facturapi_producto_id)) {
            // Crear producto en FacturaAPI
            $response = $facturapi->Products->create($facturapiData);

            if (isset($response->id)) {
                return [
                    'success' => true,
                    'facturapi_producto_id' => $response->id,
                    'message' => 'Producto creado exitosamente en FacturaAPI'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al crear producto en FacturaAPI: No se recibió ID'
                ];
            }
        } else {
            // ACTUALIZAR PRODUCTO EXISTENTE EN FACTURAPI
            $response = $facturapi->Products->update($facturapi_producto_id, $facturapiData);

            // Verificar respuesta
            if (isset($response->id)) {
                return [
                    'success' => true,
                    'facturapi_producto_id' => $response->id,
                    'message' => 'Producto actualizado exitosamente en FacturaAPI'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al actualizar producto en FacturaAPI'
                ];
            }
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error FacturaAPI: ' . $e->getMessage()
        ];
    }
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear':
                crearProducto($conn, $sucursales, $stock_minimo_global, $empresa_plan, $test_api_key_working, $timbres_disponibles, $organization_id);
                break;
            case 'editar':
                editarProducto($conn, $sucursales, $stock_minimo_global, $empresa_plan, $test_api_key_working, $timbres_disponibles, $organization_id);
                break;
            case 'cambiar_estado':
                cambiarEstadoProducto($conn);
                break;
        }
    }
}

// Función para guardar precios de mayoreo
function guardarPreciosMayoreo($conn, $producto_id, $precios_mayoreo)
{
    // Eliminar precios existentes
    $sql_delete = "DELETE FROM producto_precios_mayoreo WHERE producto_id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("i", $producto_id);
    $stmt_delete->execute();
    $stmt_delete->close();

    // Insertar nuevos precios
    if (!empty($precios_mayoreo) && is_array($precios_mayoreo)) {
        $sql_insert = "INSERT INTO producto_precios_mayoreo (producto_id, cantidad_minima, precio_especial, activo) VALUES (?, ?, ?, 1)";
        $stmt_insert = $conn->prepare($sql_insert);

        foreach ($precios_mayoreo as $precio) {
            if (isset($precio['cantidad']) && isset($precio['precio']) && $precio['cantidad'] > 0 && $precio['precio'] > 0) {
                $stmt_insert->bind_param("idd", $producto_id, $precio['cantidad'], $precio['precio']);
                $stmt_insert->execute();
            }
        }
        $stmt_insert->close();
    }
}

// Función para crear producto
function crearProducto($conn, $sucursales, $stock_minimo_global, $empresa_plan, $test_api_key_working, $timbres_disponibles, $organization_id)
{
    $codigo = trim($conn->real_escape_string($_POST['codigo']));
    $nombre = trim($conn->real_escape_string($_POST['nombre']));
    $descripcion = trim($conn->real_escape_string($_POST['descripcion']));
    $marca = trim($conn->real_escape_string($_POST['marca']));
    $subprecio = floatval($_POST['subprecio']);
    $descuento = floatval($_POST['descuento']);
    $costo = floatval($_POST['costo']);
    $categoria_id = $_POST['categoria_id'] ? intval($_POST['categoria_id']) : NULL;
    $proveedor_id = $_POST['proveedor_id'] ? intval($_POST['proveedor_id']) : NULL;
    $unidad_medida = trim($conn->real_escape_string($_POST['unidad_medida'] ?? 'pieza'));
    $peso_kg = floatval($_POST['peso_kg'] ?? 1.0);
    $permite_fracciones = isset($_POST['permite_fracciones']) ? 1 : 0;
    $fecha_caducidad = !empty($_POST['fecha_caducidad']) ? $conn->real_escape_string($_POST['fecha_caducidad']) : NULL;
    $tipo_producto = trim($conn->real_escape_string($_POST['tipo_producto'] ?? 'Estandar'));
    $porcentaje_merma_danado = floatval($_POST['porcentaje_merma_danado'] ?? 0);
    $porcentaje_merma_deshidratacion = floatval($_POST['porcentaje_merma_deshidratacion'] ?? 0);
    $aplicar_merma_venta = isset($_POST['aplicar_merma_venta']) ? 1 : 0;
    $aplicar_merma_compra = isset($_POST['aplicar_merma_compra']) ? 1 : 0;
    $utilidad = floatval($_POST['utilidad'] ?? 0);

    // Obtener precios de mayoreo del POST
    $precios_mayoreo = [];
    if (isset($_POST['precios_mayoreo'])) { 
        $precios_mayoreo = json_decode($_POST['precios_mayoreo'], true);
        if (!is_array($precios_mayoreo)) {
            $precios_mayoreo = [];
        }
    }

    // Calcular precio final
    $precio = floatval($_POST['precio']);
    if ($precio <= 0) {
        $precio = $subprecio;
        if ($descuento > 0) {
            $precio = $subprecio - ($subprecio * ($descuento / 100));
        }
    }

    // Asegurar coherencia entre precio y descuento
    if ($subprecio > 0 && $precio > 0) {
        $descuento_calculado = (($subprecio - $precio) / $subprecio) * 100;
        if ($descuento_calculado >= 0 && $descuento_calculado <= 100) {
            $descuento = $descuento_calculado;
        }
    }

    // Obtener sucursales seleccionadas
    $sucursales_seleccionadas = isset($_POST['sucursales']) ? $_POST['sucursales'] : [];

    // Variable para almacenar el ID de FacturaAPI
    $facturapi_producto_id = null;

    try {
        // Iniciar transacción
        $conn->begin_transaction();

        $stock_total = 0;
        $stock_minimo_total = $stock_minimo_global;

        foreach ($sucursales_seleccionadas as $sucursal_id) {
            $stock = floatval($_POST['stock_' . $sucursal_id]);
            $stock_total += $stock;
        }

        // Primero, crear producto en FacturaAPI si hay organización configurada
        $facturapi_result = null;

        $productoData = [
            'nombre' => $nombre,
            'codigo' => $codigo,
            'precio' => $precio,
            'descripcion' => $descripcion,
            'unidad_medida' => $unidad_medida
        ];

        // Pasar el organization_id a la función
        $facturapi_result = crearProductoFacturapi($productoData, $test_api_key_working, $organization_id);

        // Si se creó exitosamente en FacturaAPI, obtener el ID
        if ($facturapi_result['success'] && isset($facturapi_result['facturapi_producto_id'])) {
            $facturapi_producto_id = $facturapi_result['facturapi_producto_id'];
        }

        // Insertar producto
       $sql = "INSERT INTO productos (codigo, nombre, descripcion, marca, precio, subprecio, costo, descuento, utilidad, categoria_id, proveedor_id, stock, stock_minimo, unidad_medida, peso_kg, permite_fracciones, fecha_caducidad, facturapi_producto_id, tipo_producto, porcentaje_merma_danado, porcentaje_merma_deshidratacion, aplicar_merma_venta, aplicar_merma_compra) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Error en preparación: " . $conn->error);
        }

        $stmt->bind_param(
    "ssssdddddiiddssssssddii",
    $codigo, $nombre, $descripcion, $marca, $precio, $precio, $costo, $descuento, $utilidad, $categoria_id,
    $proveedor_id, $stock_total, $stock_minimo_total, $unidad_medida, $peso_kg, $permite_fracciones,
    $fecha_caducidad, $facturapi_producto_id, $tipo_producto, $porcentaje_merma_danado,
    $porcentaje_merma_deshidratacion, $aplicar_merma_venta, $aplicar_merma_compra
);

        if (!$stmt->execute()) {
            throw new Exception("Error al crear producto: " . $stmt->error);
        }

        $producto_id = $conn->insert_id;
        $stmt->close();

        // Guardar precios de mayoreo
        if (!empty($precios_mayoreo)) {
            guardarPreciosMayoreo($conn, $producto_id, $precios_mayoreo);
        }

        // PROCESAR MÚLTIPLES IMÁGENES
        $imagenes_subidas = [];
        if (isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['tmp_name'][0])) {
            $imagenes_subidas = subirMultiplesImagenes($_FILES, $producto_id);

            if (!empty($imagenes_subidas)) {
                $principal_index = isset($_POST['imagen_principal']) ? intval($_POST['imagen_principal']) : 0;
                if ($principal_index >= count($imagenes_subidas)) {
                    $principal_index = 0;
                }
                guardarImagenesProducto($conn, $producto_id, $imagenes_subidas, $principal_index);
                error_log("Se subieron " . count($imagenes_subidas) . " imágenes para el producto ID: " . $producto_id);
            }
        } else {
            error_log("No se recibieron imágenes para el producto ID: " . $producto_id);
        }

        // Insertar relaciones con sucursales
        foreach ($sucursales_seleccionadas as $sucursal_id) {
            $stock = floatval($_POST['stock_' . $sucursal_id]);

            $sql_sucursal = "INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo) 
                            VALUES (?, ?, ?, ?)";
            $stmt_sucursal = $conn->prepare($sql_sucursal);
            $stmt_sucursal->bind_param("iidd", $producto_id, $sucursal_id, $stock, $stock_minimo_global);

            if (!$stmt_sucursal->execute()) {
                throw new Exception("Error al asignar sucursal: " . $stmt_sucursal->error);
            }
            $stmt_sucursal->close();
        }

        $conn->commit();

        // Preparar mensaje
        $mensaje = "Producto creado exitosamente";
        if (!empty($imagenes_subidas)) {
            $mensaje .= " con " . count($imagenes_subidas) . " imagen(es)";
        }
        if (!empty($precios_mayoreo)) {
            $mensaje .= " con " . count($precios_mayoreo) . " regla(s) de mayoreo";
        }

        if (!empty($organization_id)) {
            if (isset($facturapi_result) && $facturapi_result['success'] && isset($facturapi_result['facturapi_producto_id'])) {
                $mensaje .= " y sincronizado con FacturaAPI (ID: " . $facturapi_producto_id . ")";
            } elseif (isset($facturapi_result) && !$facturapi_result['success']) {
                $mensaje .= " (Error en FacturaAPI: " . $facturapi_result['message'] . ")";
                $_SESSION['tipo_mensaje'] = "warning";
            }
        }

        $_SESSION['mensaje'] = $mensaje;
        $_SESSION['tipo_mensaje'] = $_SESSION['tipo_mensaje'] ?? "success";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: productos.php');
    exit();
}

// Función para editar producto
function editarProducto($conn, $sucursales, $stock_minimo_global, $empresa_plan, $test_api_key_working, $timbres_disponibles, $organization_id)
{
    $id = intval($_POST['id']);
    $codigo = trim($conn->real_escape_string($_POST['codigo']));
    $nombre = trim($conn->real_escape_string($_POST['nombre']));
    $descripcion = trim($conn->real_escape_string($_POST['descripcion']));
    $marca = trim($conn->real_escape_string($_POST['marca']));
    $subprecio = floatval($_POST['subprecio']);
    $descuento = floatval($_POST['descuento']);
    $costo = floatval($_POST['costo']);
    $categoria_id = $_POST['categoria_id'] ? intval($_POST['categoria_id']) : NULL;
    $proveedor_id = $_POST['proveedor_id'] ? intval($_POST['proveedor_id']) : NULL;
    $unidad_medida = trim($conn->real_escape_string($_POST['unidad_medida'] ?? 'pieza'));
    $peso_kg = floatval($_POST['peso_kg'] ?? 1.0);
    $permite_fracciones = isset($_POST['permite_fracciones']) ? 1 : 0;
    $fecha_caducidad = !empty($_POST['fecha_caducidad']) ? $conn->real_escape_string($_POST['fecha_caducidad']) : NULL;
    $tipo_producto = trim($conn->real_escape_string($_POST['tipo_producto'] ?? 'Estandar'));
    $porcentaje_merma_danado = floatval($_POST['porcentaje_merma_danado'] ?? 0);
    $porcentaje_merma_deshidratacion = floatval($_POST['porcentaje_merma_deshidratacion'] ?? 0);
    $aplicar_merma_venta = isset($_POST['aplicar_merma_venta']) ? 1 : 0;
    $aplicar_merma_compra = isset($_POST['aplicar_merma_compra']) ? 1 : 0;
    $utilidad = floatval($_POST['utilidad'] ?? 0);

    // Obtener precios de mayoreo del POST
    $precios_mayoreo = [];
    if (isset($_POST['precios_mayoreo'])) {
        $precios_mayoreo = json_decode($_POST['precios_mayoreo'], true);
        if (!is_array($precios_mayoreo)) {
            $precios_mayoreo = [];
        }
    }

    // Calcular precio final
    $precio = floatval($_POST['precio']);

    // Asegurar coherencia entre precio y descuento
    if ($subprecio > 0 && $precio > 0) {
        $descuento_calculado = (($subprecio - $precio) / $subprecio) * 100;
        if ($descuento_calculado >= 0 && $descuento_calculado <= 100) {
            $descuento = $descuento_calculado;
        }
    }

    // Obtener sucursales seleccionadas
    $sucursales_seleccionadas = isset($_POST['sucursales']) ? $_POST['sucursales'] : [];

    try {
        // Iniciar transacción
        $conn->begin_transaction();

        // Obtener facturapi_producto_id actual si existe
        $sql_facturapi_actual = "SELECT facturapi_producto_id FROM productos WHERE id = ?";
        $stmt_facturapi = $conn->prepare($sql_facturapi_actual);
        $stmt_facturapi->bind_param("i", $id);
        $stmt_facturapi->execute();
        $result_facturapi = $stmt_facturapi->get_result();
        $datos_actuales = $result_facturapi->fetch_assoc();
        $facturapi_producto_id_actual = $datos_actuales['facturapi_producto_id'] ?? null;
        $stmt_facturapi->close();

        // Calcular stock total
        $stock_total = 0;
        foreach ($sucursales_seleccionadas as $sucursal_id) {
            $stock = floatval($_POST['stock_' . $sucursal_id]);
            $stock_total += $stock;
        }

        // Preparar datos para FacturaAPI
        $productoData = [
            'nombre' => $nombre,
            'codigo' => $codigo,
            'precio' => $precio,
            'descripcion' => $descripcion,
            'unidad_medida' => $unidad_medida
        ];

        // Procesar FacturaAPI - solo si hay organización configurada
        $facturapi_result = null;
        $nuevo_facturapi_id = $facturapi_producto_id_actual;

        // Solo procesar FacturaAPI si tenemos organización configurada
        if (!empty($organization_id) && !empty($test_api_key_working) && $timbres_disponibles > 0) {
            $facturapi_result = actualizarProductoFacturapi(
                $facturapi_producto_id_actual,
                $productoData,
                $empresa_plan,
                $test_api_key_working,
                $timbres_disponibles,
                $organization_id
            );

            // Si la operación fue exitosa y devolvió un nuevo ID
            if ($facturapi_result['success'] && isset($facturapi_result['facturapi_producto_id'])) {
                $nuevo_facturapi_id = $facturapi_result['facturapi_producto_id'];
            }
        }

        // Actualizar producto en base de datos local
        $sql = "UPDATE productos SET 
                codigo = ?, 
                nombre = ?, 
                descripcion = ?, 
                marca = ?, 
                precio = ?, 
                subprecio = ?, 
                costo = ?, 
                descuento = ?, 
                stock = ?, 
                stock_minimo = ?, 
                categoria_id = ?, 
                proveedor_id = ?, 
                unidad_medida = ?, 
                peso_kg = ?, 
                permite_fracciones = ?, 
                fecha_caducidad = ?, 
                facturapi_producto_id = ?,
                tipo_producto = ?,
                porcentaje_merma_danado = ?,
                porcentaje_merma_deshidratacion = ?,
                aplicar_merma_venta = ?,
                aplicar_merma_compra = ?,
                utilidad = ? 
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error en preparación: " . $conn->error);
        }

       $stmt->bind_param(
    "ssssddddiiddsdiissssddii",
    
    $codigo,                    
    $nombre,                  
    $descripcion,              
    $marca,                    
    $precio,                   
    $precio,               
    $costo,                   
    $descuento,                
    $stock_total,              
    $stock_minimo_global,      
    $categoria_id,              
    $proveedor_id,             
    $unidad_medida,             
    $peso_kg,                  
    $permite_fracciones,       
    $fecha_caducidad,          
    $nuevo_facturapi_id,        
    $tipo_producto,             
    $porcentaje_merma_danado,   
    $porcentaje_merma_deshidratacion, 
    $aplicar_merma_venta,       
    $aplicar_merma_compra,      
    $utilidad,                 
    $id                        
);

        if (!$stmt->execute()) {
            throw new Exception("Error al actualizar producto: " . $stmt->error);
        }
        $stmt->close();

        // Guardar precios de mayoreo
        guardarPreciosMayoreo($conn, $id, $precios_mayoreo);

        // PROCESAR IMÁGENES MÚLTIPLES

        $imagenes_para_guardar = [];

        // 1. Procesar imágenes existentes que NO se eliminaron
        if (isset($_POST['imagenes_existentes']) && !empty($_POST['imagenes_existentes'])) {
            $imagenes_existentes = json_decode($_POST['imagenes_existentes'], true);
            if (is_array($imagenes_existentes) && count($imagenes_existentes) > 0) {
                // Verificar que cada imagen existe físicamente
                foreach ($imagenes_existentes as $img) {
                    // Buscar la ruta real de la imagen
                    $ruta_img = isset($img['ruta_imagen']) ? $img['ruta_imagen'] : '';

                    if (!empty($ruta_img)) {
                        // Verificar si la imagen existe en el servidor
                        $ruta_fisica = $_SERVER['DOCUMENT_ROOT'] . $ruta_img;
                        $ruta_fisica_alternativa = dirname(__FILE__) . '/' . $ruta_img;
                        $ruta_fisica_alternativa2 = dirname(__FILE__) . '/../' . $ruta_img;

                        if (file_exists($ruta_fisica) || file_exists($ruta_fisica_alternativa) || file_exists($ruta_fisica_alternativa2)) {
                            $imagenes_para_guardar[] = $ruta_img;
                            error_log("✓ IMAGEN MANTENIDA: " . $ruta_img);
                        } else {
                            error_log("⚠ ADVERTENCIA: Imagen no encontrada en servidor: " . $ruta_img);
                            // Aún así la mantenemos en BD (podría ser ruta relativa)
                            $imagenes_para_guardar[] = $ruta_img;
                        }
                    }
                }
            }
        } else {
            // Si no hay imagenes_existentes en el POST, significa que el usuario no modificó las imágenes
            // Así que debemos obtener las imágenes actuales de la base de datos
            error_log("No se recibió imagenes_existentes, cargando desde BD para producto ID: " . $id);
            $imagenes_bd = obtenerImagenesProducto($conn, $id);
            if (!empty($imagenes_bd)) {
                foreach ($imagenes_bd as $img) {
                    $imagenes_para_guardar[] = $img['ruta_imagen'];
                    error_log("✓ IMAGEN CARGADA DESDE BD: " . $img['ruta_imagen']);
                }
            }
        }

        // 2. Procesar nuevas imágenes subidas
        $nuevas_imagenes = [];
        if (isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['tmp_name'][0])) {
            // Verificar que no se exceda el límite total
            $total_imagenes_despues = count($imagenes_para_guardar) + count($_FILES['imagenes']['tmp_name']);
            if ($total_imagenes_despues <= 5) {
                $nuevas_imagenes = subirMultiplesImagenes($_FILES, $id);
                $imagenes_para_guardar = array_merge($imagenes_para_guardar, $nuevas_imagenes);
                error_log("Se agregaron " . count($nuevas_imagenes) . " nuevas imágenes");
            } else {
                error_log("ERROR: Excede el límite de 5 imágenes. Actuales: " . count($imagenes_para_guardar) . ", Nuevas: " . count($_FILES['imagenes']['tmp_name']));
                throw new Exception("No se pueden agregar más de 5 imágenes por producto");
            }
        }

        // 3. Guardar todas las imágenes en la base de datos
        if (!empty($imagenes_para_guardar)) {
            $principal_index = isset($_POST['imagen_principal']) ? intval($_POST['imagen_principal']) : 0;

            // Asegurar que el índice principal sea válido
            if ($principal_index < 0 || $principal_index >= count($imagenes_para_guardar)) {
                $principal_index = 0;
            }

            // IMPORTANTE: Usar las rutas de imagen, no los IDs
            // Necesitamos reconstruir el array para guardarImagenesProducto
            $imagenes_con_rutas = [];
            foreach ($imagenes_para_guardar as $ruta) {
                $imagenes_con_rutas[] = ['ruta_imagen' => $ruta];
            }

            guardarImagenesProducto($conn, $id, $imagenes_para_guardar, $principal_index);
            error_log("IMÁGENES GUARDADAS EN BD: " . count($imagenes_para_guardar) . " imágenes, Principal índice: " . $principal_index);
        } else {
            // Si no hay imágenes, eliminar todas las existentes
            error_log("No hay imágenes para el producto ID: " . $id . ", eliminando todas");
            eliminarImagenesProducto($conn, $id);
        }

        // Eliminar relaciones existentes con sucursales
        $sql_delete = "DELETE FROM producto_sucursal WHERE producto_id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) {
            throw new Exception("Error al eliminar relaciones de sucursales: " . $stmt_delete->error);
        }
        $stmt_delete->close();

        // Insertar nuevas relaciones con sucursales
        foreach ($sucursales_seleccionadas as $sucursal_id) {
            $stock = floatval($_POST['stock_' . $sucursal_id]);

            $sql_sucursal = "INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo) 
                            VALUES (?, ?, ?, ?)";
            $stmt_sucursal = $conn->prepare($sql_sucursal);
            if (!$stmt_sucursal) {
                throw new Exception("Error al preparar inserción de sucursal: " . $conn->error);
            }

            $stmt_sucursal->bind_param("iidd", $id, $sucursal_id, $stock, $stock_minimo_global);

            if (!$stmt_sucursal->execute()) {
                throw new Exception("Error al asignar sucursal: " . $stmt_sucursal->error);
            }
            $stmt_sucursal->close();
        }

        // Commit de la transacción
        $conn->commit();

        // Preparar mensaje
        $mensaje = "Producto actualizado exitosamente";
        if (!empty($nuevas_imagenes)) {
            $mensaje .= " con " . count($nuevas_imagenes) . " nueva(s) imagen(es)";
        }
        if (!empty($precios_mayoreo)) {
            $mensaje .= " con " . count($precios_mayoreo) . " regla(s) de mayoreo";
        }

        // Solo agregar información de FacturaAPI si hay organización configurada
        if (!empty($organization_id)) {
            if (isset($facturapi_result) && $facturapi_result['success']) {
                if (empty($facturapi_producto_id_actual)) {
                    $mensaje .= " y se creó en FacturaAPI (ID: " . $nuevo_facturapi_id . ")";
                } else {
                    $mensaje .= " y se actualizó en FacturaAPI";
                }
            } elseif (isset($facturapi_result) && !$facturapi_result['success']) {
                $mensaje .= " (Error en FacturaAPI: " . $facturapi_result['message'] . ")";
                $_SESSION['tipo_mensaje'] = "warning";
            }
        }

        $_SESSION['mensaje'] = $mensaje;
        $_SESSION['tipo_mensaje'] = $_SESSION['tipo_mensaje'] ?? "success";
    } catch (Exception $e) {
        // Rollback en caso de error
        $conn->rollback();
        error_log("Error en editarProducto: " . $e->getMessage());
        $_SESSION['mensaje'] = "Error al actualizar producto: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: productos.php');
    exit();
}

function cambiarEstadoProducto($conn)
{
    $id = intval($_POST['id']);
    $activo = intval($_POST['activo']);

    try {
        $sql = "UPDATE productos SET activo = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $activo, $id);

        if ($stmt->execute()) {
            $estado = $activo ? "activado" : "desactivado";
            echo json_encode(['success' => true, 'message' => "Producto $estado exitosamente"]);
        } else {
            throw new Exception("Error al cambiar estado: " . $stmt->error);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Función para obtener stock por sucursal
function getStockPorSucursal($conn, $producto_id)
{
    $stock_data = [];
    $sql = "SELECT ps.sucursal_id, s.nombre as sucursal_nombre, ps.stock, ps.stock_minimo 
            FROM producto_sucursal ps 
            JOIN sucursales s ON ps.sucursal_id = s.id 
            WHERE ps.producto_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $stock_data[$row['sucursal_id']] = $row;
    }
    $stmt->close();

    return $stock_data;
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Productos - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SortableJS para ordenar imágenes -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <style>
    /* Fuerza fondo sólido en el modal y sus partes críticas */
    .modal-dialog,
    .modal-content,
    .modal-body,
    .modal-header,
    .modal-footer {
        background-color: #ffffff !important; /* Cambia a blanco sólido */
    }

    /* En modo oscuro, si lo tienes, ajusta */
    [data-theme="dark"] .modal-dialog,
    [data-theme="dark"] .modal-content,
    [data-theme="dark"] .modal-body,
    [data-theme="dark"] .modal-header,
    [data-theme="dark"] .modal-footer {
        background-color: #1e1e1e !important; /* o el gris oscuro que uses */
    }

    /* Para pantallas pequeñas, cuando es fullscreen, refuerza */
    @media (max-width: 991.98px) {
        .modal-fullscreen-lg-down .modal-content {
            background-color: #ffffff !important;
        }
        [data-theme="dark"] .modal-fullscreen-lg-down .modal-content {
            background-color: #1e1e1e !important;
        }
    }

    /* Opcional: elimina cualquier sombra o borde que pueda interferir */
    .modal-content {
        border: none !important;
        box-shadow: none !important;
    }
</style>

    <!-- Tema unificado LibertyFin (estilo landing) -->
    <link rel="stylesheet" href="css/crm-theme.css">
</head>

<body>

    <?php include 'includes/navbar.php'; ?>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Header -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 header-actions gap-3">
                    <!-- Título con badge -->
                    <h2>
                        <i class="fas fa-boxes me-2"></i>
                        Gestión de Productos
                        <?php if ($empresa_plan != 'premium'): ?>
                            <small class="badge bg-<?php echo $limite_alcanzado ? 'danger' : 'info'; ?> ms-2">
                                <?php echo $total_productos_activos; ?>/<?php echo $limite_productos; ?> productos
                            </small>
                        <?php endif; ?>
                    </h2>

                    <!-- Botones en fila para móvil con textos más cortos -->
                    <div class="d-flex flex-wrap gap-2 w-100 w-md-auto">
                        <!-- Botón Nuevo Producto -->
                        <button class="btn btn-primary flex-grow-1 flex-md-grow-0" id="btnNuevoProducto"
                            <?php echo $limite_alcanzado ? 'disabled title="Ha alcanzado el límite de productos"' : ''; ?>>
                            <i class="fas fa-plus me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Nuevo Producto</span>
                            <span class="d-sm-none">Nuevo</span>
                        </button>

                        <!-- Botón Importar -->
                        <button class="btn btn-success flex-grow-1 flex-md-grow-0" id="btnImportarProductos"
                            <?php echo $limite_alcanzado ? 'disabled title="Ha alcanzado el límite de productos"' : ''; ?>>
                            <i class="fas fa-file-import me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Importar</span>
                            <span class="d-sm-none">Importar</span>
                        </button>

                        <!-- Botón Reportes -->
                        <button class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="modal" data-bs-target="#reporteModal">
                            <i class="fas fa-chart-bar me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Reportes</span>
                            <span class="d-sm-none">Reportes</span>
                        </button>

                        <!-- Botón Plantilla -->
                        <a href="Documentos/plantilla_productos.xlsx" class="btn btn-outline-secondary flex-grow-1 flex-md-grow-0" download="plantilla_productos.xlsx">
                            <i class="fas fa-download me-1 me-md-2"></i>
                            <span class="d-none d-sm-inline">Descargar Plantilla</span>
                            <span class="d-sm-none">Plantilla</span>
                        </a>
                    </div>
                </div>

                <!-- Mensajes -->
                <?php if (isset($_SESSION['mensaje'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show">
                        <?php echo $_SESSION['mensaje']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
                <?php endif; ?>

                <!-- Alerta de límite de productos -->
                <?php if ($empresa_plan != 'premium' && $productos_disponibles <= 10 && $productos_disponibles > 0): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>¡Atención!</strong> Solo le quedan <?php echo $productos_disponibles; ?> productos disponibles en su plan <?php echo ucfirst($empresa_plan); ?>.
                        <a href="actualizar_plan.php" class="alert-link">Considere actualizar su plan</a> para continuar agregando productos.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Productos</div>
                                        <div class="metric-value text-primary"><?php echo $total_productos; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-box fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Con Stock</div>
                                        <div class="metric-value text-success"><?php echo $con_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Stock Bajo</div>
                                        <div class="metric-value text-warning"><?php echo $bajo_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Sin Stock</div>
                                        <div class="metric-value text-danger"><?php echo $sin_stock; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x text-danger opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra de Búsqueda y Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center filtros-row">
                            <div class="col-md-3">
                                <div class="search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="text" class="form-control" placeholder="Buscar por código, nombre, marca..."
                                        id="searchInput" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                        data-current-value="<?php echo htmlspecialchars($search ?? ''); ?>">
                                    <div class="search-loading" id="searchLoading">
                                        <i class="fas fa-spinner fa-spin text-muted"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filterCategoria">
                                    <option value="">Todas las categorías</option>
                                    <?php foreach ($categorias as $categoria): ?>
                                        <option value="<?php echo $categoria['id']; ?>" <?php echo (isset($categoria_filtro) && $categoria_filtro == $categoria['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($categoria['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filterProveedor">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo $proveedor['id']; ?>" <?php echo (isset($proveedor_filtro) && $proveedor_filtro == $proveedor['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filterSucursal">
                                    <option value="">Todas las sucursales</option>
                                    <?php foreach ($sucursales as $sucursal): ?>
                                        <option value="<?php echo $sucursal['id']; ?>" <?php echo (isset($sucursal_filtro) && $sucursal_filtro == $sucursal['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="showInactive" <?php echo $show_inactive ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="showInactive">Mostrar inactivos</label>
                                </div>
                            </div>
                            <div class="col-md-1">
                                <button class="btn btn-outline-secondary w-100" id="btnClearFilters" title="Limpiar filtros">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-12">
                                <small class="result-count" id="resultCount">Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Productos - Desktop -->
                <div class="card producto-grid">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Lista de Productos</h5>
                        <div class="d-flex align-items-center">
                            <small class="result-count me-3" id="resultCountDesktop">
                                Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                            </small>
                            <?php if ($total_paginas > 1): ?>
                                <span class="badge bg-primary">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="productsTable">
                                <thead>
                                    <tr>
                                        <th>Imagen</th>
                                        <th>Código</th>
                                        <th>Producto</th>
                                        <th>Unidad Medida</th>
                                        <th>Marca</th>
                                        <th>Categoría</th>
                                        <th>Subprecio</th>
                                        <th>Descuento</th>
                                        <th>Precio Final</th>
                                        <th>Stock Total</th>
                                        <th>Fecha Caducidad</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody id="productsTableBody">
                                    <?php if (empty($productos)): ?>
                                        <tr>
                                            <td colspan="14" class="text-center text-muted py-4">
                                                <i class="fas fa-box fa-3x mb-3"></i>
                                                <p>No se encontraron productos</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($productos as $producto):
                                            $imagenes_producto = $imagenes_por_producto[$producto['id']] ?? [];
                                            $precios_mayoreo = $precios_mayoreo_por_producto[$producto['id']] ?? [];
                                        ?>
                                            <tr data-categoria="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                                data-proveedor="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                                data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                                data-activo="<?php echo $producto['activo']; ?>"
                                                class="producto-row">
                                                <td>
                                                    <?php if (!empty($imagenes_producto)): ?>
                                                        <div id="carouselSmall-<?php echo $producto['id']; ?>" class="carousel slide producto-imagen-carousel" data-bs-ride="false" data-bs-interval="false">
                                                            <div class="carousel-inner">
                                                                <?php foreach ($imagenes_producto as $index => $img):
                                                                    $activeClass = ($index === 0) ? 'active' : '';
                                                                ?>
                                                                    <div class="carousel-item <?php echo $activeClass; ?>">
                                                                        <img src="<?php echo htmlspecialchars($img['ruta_imagen']); ?>"
                                                                            class="d-block w-100"
                                                                            alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                                            onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', <?php echo $index; ?>, event)">
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <?php if (count($imagenes_producto) > 1): ?>
                                                                <button class="carousel-control-prev" type="button" data-bs-target="#carouselSmall-<?php echo $producto['id']; ?>" data-bs-slide="prev">
                                                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                                                    <span class="visually-hidden">Anterior</span>
                                                                </button>
                                                                <button class="carousel-control-next" type="button" data-bs-target="#carouselSmall-<?php echo $producto['id']; ?>" data-bs-slide="next">
                                                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                                                    <span class="visually-hidden">Siguiente</span>
                                                                </button>
                                                                <div class="carousel-indicators">
                                                                    <?php for ($i = 0; $i < count($imagenes_producto); $i++): ?>
                                                                        <button type="button" data-bs-target="#carouselSmall-<?php echo $producto['id']; ?>" data-bs-slide-to="<?php echo $i; ?>" class="<?php echo ($i === 0) ? 'active' : ''; ?>" aria-current="<?php echo ($i === 0) ? 'true' : 'false'; ?>" aria-label="Slide <?php echo $i + 1; ?>"></button>
                                                                    <?php endfor; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="producto-imagen bg-light d-flex align-items-center justify-content-center no-imagen-container"
                                                            style="width: 60px; height: 60px;"
                                                            title="No hay imagen disponible"
                                                            onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', 0, event)">
                                                            <i class="fas fa-image text-muted"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($producto['codigo']); ?></strong>
                                                    <?php if (!empty($precios_mayoreo)): ?>
                                                        <span class="badge mayoreo-badge ms-1" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;">
                                                            <i class="fas fa-tags"></i> Mayoreo
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($producto['nombre']); ?></strong>
                                                        <?php if ($producto['descripcion']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($producto['descripcion']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $unidad = $producto['unidad_medida'] ?? 'pieza';
                                                    $badge_class = '';
                                                    switch ($unidad) {
                                                        case 'pieza':
                                                            $badge_class = 'unidad-pieza';
                                                            break;
                                                        case 'kilo':
                                                            $badge_class = 'unidad-kilo';
                                                            break;
                                                        case 'litro':
                                                            $badge_class = 'unidad-litro';
                                                            break;
                                                        default:
                                                            $badge_class = 'unidad-pieza';
                                                    }
                                                    ?>
                                                    <span class="badge unidad-medida-badge <?php echo $badge_class; ?>">
                                                        <?php echo ucfirst(htmlspecialchars($unidad)); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($producto['marca'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></td>
                                                <td>
                                                    <span class="badge badge-subprecio">$<?php echo number_format($producto['subprecio'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($producto['descuento'] > 0): ?>
                                                        <span class="badge badge-descuento">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0%</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $precio_final = $producto['precio'];
                                                    $subprecio = $producto['subprecio'];
                                                    if ($producto['descuento'] > 0 && $subprecio > 0) {
                                                        $precio_final = $subprecio - ($subprecio * ($producto['descuento'] / 100));
                                                    }
                                                    ?>
                                                    <span class="badge badge-precio <?php echo $producto['descuento'] > 0 ? 'text-danger fw-bold' : ''; ?>">
                                                        $<?php echo number_format($precio_final, 2); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stock_formateado = formatearStockPorUnidad($producto['stock_total'], $producto['unidad_medida'] ?? 'pieza');
                                                    ?>
                                                    <?php if ($producto['stock_total'] <= 0): ?>
                                                        <span class="badge bg-danger badge-stock"><?php echo $stock_formateado; ?></span>
                                                    <?php elseif ($producto['stock_total'] <= $stock_minimo_global): ?>
                                                        <span class="badge bg-warning badge-stock"><?php echo $stock_formateado; ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success badge-stock"><?php echo $stock_formateado; ?></span>
                                                    <?php endif; ?>
                                                    <br><small class="text-muted">Mín: <?php echo number_format($stock_minimo_global, 0); ?></small>
                                                    <?php if ($mostrar_merma && ($producto['porcentaje_merma_danado'] > 0 || $producto['porcentaje_merma_deshidratacion'] > 0)): ?>
                                                        <br><small class="text-muted merma-badge">Merma: <?php echo $producto['porcentaje_merma_danado'] + $producto['porcentaje_merma_deshidratacion']; ?>%</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($producto['fecha_caducidad'])):
                                                        $fecha_cad = new DateTime($producto['fecha_caducidad']);
                                                        $hoy = new DateTime();
                                                        $dias_restantes = $hoy->diff($fecha_cad)->days;

                                                        if ($fecha_cad < $hoy): ?>
                                                            <span class="badge bg-danger" title="Producto vencido">
                                                                <i class="fas fa-exclamation-triangle"></i> Vencido
                                                            </span>
                                                        <?php elseif ($dias_restantes <= 7): ?>
                                                            <span class="badge bg-warning" title="<?php echo $dias_restantes; ?> días para vencer">
                                                                <i class="fas fa-clock"></i> <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $producto['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                    <button class="btn btn-outline-primary btn-sm edit-producto d-none"
                                                        data-id="<?php echo $producto['id']; ?>"
                                                        data-activo="<?php echo $producto['activo']; ?>"
                                                        data-codigo="<?php echo htmlspecialchars($producto['codigo']); ?>"
                                                        data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                        data-descripcion="<?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?>"
                                                        data-marca="<?php echo htmlspecialchars($producto['marca'] ?? ''); ?>"
                                                        data-precio="<?php echo $precio_final; ?>"
                                                        data-subprecio="<?php echo $producto['subprecio']; ?>"
                                                        data-descuento="<?php echo $producto['descuento']; ?>"
                                                        data-costo="<?php echo $producto['costo']; ?>"
                                                        data-categoria_id="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                                        data-proveedor_id="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                                        data-unidad_medida="<?php echo $producto['unidad_medida'] ?? 'pieza'; ?>"
                                                        data-peso_kg="<?php echo $producto['peso_kg'] ?? 1.0; ?>"
                                                        data-permite_fracciones="<?php echo $producto['permite_fracciones'] ?? 0; ?>"
                                                        data-fecha_caducidad="<?php echo !empty($producto['fecha_caducidad']) ? $producto['fecha_caducidad'] : ''; ?>"
                                                        data-tipo_producto="<?php echo htmlspecialchars($producto['tipo_producto'] ?? 'Estandar'); ?>"
                                                        data-porcentaje_merma_danado="<?php echo $producto['porcentaje_merma_danado'] ?? 0; ?>"
                                                        data-porcentaje_merma_deshidratacion="<?php echo $producto['porcentaje_merma_deshidratacion'] ?? 0; ?>"
                                                        data-aplicar_merma_venta="<?php echo $producto['aplicar_merma_venta'] ?? 0; ?>"
                                                        data-aplicar_merma_compra="<?php echo $producto['aplicar_merma_compra'] ?? 0; ?>"
                                                        data-imagenes='<?php echo json_encode($imagenes_producto); ?>'
                                                        data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                                        data-precios-mayoreo='<?php echo json_encode($precios_mayoreo); ?>'
                                                        data-utilidad="<?php echo $producto['utilidad'] ?? 0; ?>"
                                                        data-stocks='<?php
                                                                        $stock_data = [];
                                                                        if (isset($stock_por_sucursal[$producto['id']])) {
                                                                            foreach ($stock_por_sucursal[$producto['id']] as $sucursal_id => $stock_info) {
                                                                                $stock_data[$sucursal_id] = $stock_info;
                                                                            }
                                                                        }
                                                                        echo htmlspecialchars(json_encode($stock_data));
                                                                        ?>'
                                                        title="Editar">
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación Desktop -->
                        <?php if ($total_paginas > 1): ?>
                            <div class="pagination-container" id="desktopPagination">
                                <div class="pagination-info">
                                    Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                                </div>
                                <nav>
                                    <ul class="pagination mb-0">
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php
                                                                        $query_params = $_GET;
                                                                        $query_params['pagina'] = 1;
                                                                        echo http_build_query($query_params);
                                                                        ?>" title="Primera página">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php
                                                                        $query_params = $_GET;
                                                                        $query_params['pagina'] = max(1, $pagina_actual - 1);
                                                                        echo http_build_query($query_params);
                                                                        ?>" title="Página anterior">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                        <?php
                                        $inicio = max(1, $pagina_actual - 2);
                                        $fin = min($total_paginas, $pagina_actual + 2);
                                        for ($i = $inicio; $i <= $fin; $i++):
                                        ?>
                                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php
                                                                            $query_params = $_GET;
                                                                            $query_params['pagina'] = $i;
                                                                            echo http_build_query($query_params);
                                                                            ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php
                                                                        $query_params = $_GET;
                                                                        $query_params['pagina'] = min($total_paginas, $pagina_actual + 1);
                                                                        echo http_build_query($query_params);
                                                                        ?>" title="Página siguiente">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php
                                                                        $query_params = $_GET;
                                                                        $query_params['pagina'] = $total_paginas;
                                                                        echo http_build_query($query_params);
                                                                        ?>" title="Última página">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                        <div class="no-results" id="noResultsDesktop" style="display: none;">
                            <i class="fas fa-search fa-3x mb-3"></i>
                            <h5>No se encontraron productos</h5>
                            <p>Intenta ajustar los filtros de búsqueda</p>
                        </div>
                    </div>
                </div>

                <!-- Cards de Productos - Móvil -->
                <div class="producto-cards" id="mobileProductsContainer">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Lista de Productos</h5>
                        <?php if ($total_paginas > 1): ?>
                            <span class="badge bg-primary">Pág. <?php echo $pagina_actual; ?>/<?php echo $total_paginas; ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($productos)): ?>
                        <div class="card text-center text-muted py-4">
                            <i class="fas fa-box fa-3x mb-3"></i>
                            <p>No se encontraron productos</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($productos as $producto):
                            $imagenes_producto = $imagenes_por_producto[$producto['id']] ?? [];
                            $precios_mayoreo = $precios_mayoreo_por_producto[$producto['id']] ?? [];
                            $precio_final = $producto['precio'];
                            $subprecio = $producto['subprecio'];
                            if ($producto['descuento'] > 0 && $subprecio > 0) {
                                $precio_final = $subprecio - ($subprecio * ($producto['descuento'] / 100));
                            }
                        ?>
                            <div class="producto-card-mobile" data-categoria="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                data-proveedor="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                data-activo="<?php echo $producto['activo']; ?>">
                                <div class="producto-card-header">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($imagenes_producto)): ?>
                                                <div id="carouselMobile-<?php echo $producto['id']; ?>" class="carousel slide producto-imagen-carousel me-2" style="width: 80px;" data-bs-ride="false" data-bs-interval="false">
                                                    <div class="carousel-inner">
                                                        <?php foreach ($imagenes_producto as $index => $img):
                                                            $activeClass = ($index === 0) ? 'active' : '';
                                                        ?>
                                                            <div class="carousel-item <?php echo $activeClass; ?>">
                                                                <img src="<?php echo htmlspecialchars($img['ruta_imagen']); ?>"
                                                                    class="d-block w-100"
                                                                    alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                                    onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', <?php echo $index; ?>, event)">
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php if (count($imagenes_producto) > 1): ?>
                                                        <button class="carousel-control-prev" type="button" data-bs-target="#carouselMobile-<?php echo $producto['id']; ?>" data-bs-slide="prev" style="width: 15px;">
                                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                                            <span class="visually-hidden">Anterior</span>
                                                        </button>
                                                        <button class="carousel-control-next" type="button" data-bs-target="#carouselMobile-<?php echo $producto['id']; ?>" data-bs-slide="next" style="width: 15px;">
                                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                                            <span class="visually-hidden">Siguiente</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="producto-imagen-mobile bg-light d-flex align-items-center justify-content-center me-2 no-imagen-container"
                                                    style="width: 70px; height: 70px;"
                                                    title="No hay imagen disponible"
                                                    onclick="abrirCarruselAmpliado('<?php echo $producto['id']; ?>', 0, event)">
                                                    <i class="fas fa-image text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h6 class="mb-0 text-white"><?php echo htmlspecialchars($producto['nombre']); ?></h6>
                                                <div class="d-flex align-items-center mt-1">
                                                    <span class="badge bg-light text-dark me-2"><?php echo htmlspecialchars($producto['codigo']); ?></span>
                                                    <?php if (!empty($precios_mayoreo)): ?>
                                                        <span class="badge mayoreo-badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;">
                                                            <i class="fas fa-tags"></i> Mayoreo
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($producto['tipo_producto']) && $mostrar_tipo_producto): ?>
                                                        <span class="badge tipo-producto-badge ms-1" style="font-size: 0.65rem;">
                                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($producto['tipo_producto']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <button class="btn btn-outline-light btn-sm edit-producto-mobile d-none"
                                                        data-id="<?php echo $producto['id']; ?>"
                                                        data-activo="<?php echo $producto['activo']; ?>"
                                                        data-codigo="<?php echo htmlspecialchars($producto['codigo']); ?>"
                                                        data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                        data-descripcion="<?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?>"
                                                        data-marca="<?php echo htmlspecialchars($producto['marca'] ?? ''); ?>"
                                                        data-precio="<?php echo $precio_final; ?>"
                                                        data-subprecio="<?php echo $producto['subprecio']; ?>"
                                                        data-descuento="<?php echo $producto['descuento']; ?>"
                                                        data-costo="<?php echo $producto['costo']; ?>"
                                                        data-categoria_id="<?php echo $producto['categoria_id'] ?? ''; ?>"
                                                        data-proveedor_id="<?php echo $producto['proveedor_id'] ?? ''; ?>"
                                                        data-unidad_medida="<?php echo $producto['unidad_medida'] ?? 'pieza'; ?>"
                                                        data-peso_kg="<?php echo $producto['peso_kg'] ?? 1.0; ?>"
                                                        data-permite_fracciones="<?php echo $producto['permite_fracciones'] ?? 0; ?>"
                                                        data-fecha_caducidad="<?php echo !empty($producto['fecha_caducidad']) ? $producto['fecha_caducidad'] : ''; ?>"
                                                        data-tipo_producto="<?php echo htmlspecialchars($producto['tipo_producto'] ?? 'Estandar'); ?>"
                                                        data-porcentaje_merma_danado="<?php echo $producto['porcentaje_merma_danado'] ?? 0; ?>"
                                                        data-porcentaje_merma_deshidratacion="<?php echo $producto['porcentaje_merma_deshidratacion'] ?? 0; ?>"
                                                        data-aplicar_merma_venta="<?php echo $producto['aplicar_merma_venta'] ?? 0; ?>"
                                                        data-aplicar_merma_compra="<?php echo $producto['aplicar_merma_compra'] ?? 0; ?>"
                                                        data-imagenes='<?php echo json_encode($imagenes_producto); ?>'
                                                        data-sucursales='<?php echo $producto['sucursales_ids'] ?? ''; ?>'
                                                        data-precios-mayoreo='<?php echo json_encode($precios_mayoreo); ?>'
                                                        data-utilidad="<?php echo $producto['utilidad'] ?? 0; ?>"
                                                        data-stocks='<?php
                                                                        $stock_data = [];
                                                                        if (isset($stock_por_sucursal[$producto['id']])) {
                                                                            foreach ($stock_por_sucursal[$producto['id']] as $sucursal_id => $stock_info) {
                                                                                $stock_data[$sucursal_id] = $stock_info;
                                                                            }
                                                                        }
                                                                        echo htmlspecialchars(json_encode($stock_data));
                                                                        ?>'>
                                                    </button>

                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <div class="producto-card-body">
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Unidad Medida:</span>
                                        <span class="producto-info-value">
                                            <?php
                                            $unidad = $producto['unidad_medida'] ?? 'pieza';
                                            $badge_class = '';
                                            switch ($unidad) {
                                                case 'pieza':
                                                    $badge_class = 'unidad-pieza';
                                                    break;
                                                case 'kilo':
                                                    $badge_class = 'unidad-kilo';
                                                    break;
                                                case 'litro':
                                                    $badge_class = 'unidad-litro';
                                                    break;
                                                default:
                                                    $badge_class = 'unidad-pieza';
                                            }
                                            ?>
                                            <span class="badge unidad-medida-badge <?php echo $badge_class; ?>">
                                                <?php echo ucfirst(htmlspecialchars($unidad)); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <?php if ($mostrar_tipo_producto): ?>
                                        <div class="producto-info-row">
                                            <span class="producto-info-label">Tipo:</span>
                                            <span class="producto-info-value">
                                                <span class="badge tipo-producto-badge">
                                                    <?php echo htmlspecialchars($producto['tipo_producto'] ?? 'Estandar'); ?>
                                                </span>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($mostrar_merma && ($producto['porcentaje_merma_danado'] > 0 || $producto['porcentaje_merma_deshidratacion'] > 0)): ?>
                                        <div class="producto-info-row">
                                            <span class="producto-info-label">Merma:</span>
                                            <span class="producto-info-value">
                                                <span class="badge merma-badge">
                                                    <i class="fas fa-charging-station me-1"></i>
                                                    D: <?php echo $producto['porcentaje_merma_danado']; ?>% /
                                                    Des: <?php echo $producto['porcentaje_merma_deshidratacion']; ?>%
                                                </span>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Marca:</span>
                                        <span class="producto-info-value"><?php echo htmlspecialchars($producto['marca'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Categoría:</span>
                                        <span class="producto-info-value"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Subprecio:</span>
                                        <span class="producto-info-value text-dark">$<?php echo number_format($producto['subprecio'], 2); ?></span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Descuento:</span>
                                        <span class="producto-info-value">
                                            <?php if ($producto['descuento'] > 0): ?>
                                                <span class="badge bg-danger">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                            <?php else: ?>
                                                <span class="text-muted">0%</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Precio Final:</span>
                                        <span class="producto-info-value text-success fw-bold">
                                            $<?php echo number_format($precio_final, 2); ?>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Stock Total:</span>
                                        <span class="producto-info-value">
                                            <?php
                                            $stock_formateado = formatearStockPorUnidad($producto['stock_total'], $producto['unidad_medida'] ?? 'pieza');
                                            ?>
                                            <?php if ($producto['stock_total'] <= 0): ?>
                                                <span class="badge bg-danger"><?php echo $stock_formateado; ?></span>
                                            <?php elseif ($producto['stock_total'] <= $stock_minimo_global): ?>
                                                <span class="badge bg-warning"><?php echo $stock_formateado; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><?php echo $stock_formateado; ?></span>
                                            <?php endif; ?>
                                            <small class="text-muted ms-2">Mín: <?php echo number_format($stock_minimo_global, 0); ?></small>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Fecha Caducidad:</span>
                                        <span class="producto-info-value">
                                            <?php if (!empty($producto['fecha_caducidad'])):
                                                $fecha_cad = new DateTime($producto['fecha_caducidad']);
                                                $hoy = new DateTime();
                                                $dias_restantes = $hoy->diff($fecha_cad)->days;

                                                if ($fecha_cad < $hoy): ?>
                                                    <span class="badge bg-danger" title="Producto vencido">
                                                        <i class="fas fa-exclamation-triangle"></i> Vencido
                                                    </span>
                                                <?php elseif ($dias_restantes <= 7): ?>
                                                    <span class="badge bg-warning" title="<?php echo $dias_restantes; ?> días para vencer">
                                                        <i class="fas fa-clock"></i> <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($producto['fecha_caducidad'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="producto-info-row">
                                        <span class="producto-info-label">Estado:</span>
                                        <span class="producto-info-value">
                                            <span class="status-badge <?php echo $producto['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                                            </span>
                                        </span>
                                    </div>
                                    <?php if ($producto['descripcion']): ?>
                                        <div class="producto-info-row">
                                            <span class="producto-info-label">Descripción:</span>
                                            <span class="producto-info-value"><small><?php echo htmlspecialchars($producto['descripcion']); ?></small></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Paginación Móvil -->
                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container" id="mobilePagination">
                            <div class="pagination-info">
                                <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                            </div>
                            <nav>
                                <ul class="pagination pagination-sm mb-0 justify-content-center">
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php
                                                                    $query_params = $_GET;
                                                                    $query_params['pagina'] = 1;
                                                                    echo http_build_query($query_params);
                                                                    ?>" title="Primera página">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php
                                                                    $query_params = $_GET;
                                                                    $query_params['pagina'] = max(1, $pagina_actual - 1);
                                                                    echo http_build_query($query_params);
                                                                    ?>" title="Página anterior">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item disabled">
                                        <span class="page-link text-dark">
                                            <strong><?php echo $pagina_actual; ?></strong> / <?php echo $total_paginas; ?>
                                        </span>
                                    </li>
                                    <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php
                                                                    $query_params = $_GET;
                                                                    $query_params['pagina'] = min($total_paginas, $pagina_actual + 1);
                                                                    echo http_build_query($query_params);
                                                                    ?>" title="Siguiente">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php
                                                                    $query_params = $_GET;
                                                                    $query_params['pagina'] = $total_paginas;
                                                                    echo http_build_query($query_params);
                                                                    ?>" title="Última página">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>

                    <div class="no-results" id="noResultsMobile" style="display: none;">
                        <i class="fas fa-search fa-3x mb-3"></i>
                        <h5>No se encontraron productos</h5>
                        <p>Intenta ajustar los filtros de búsqueda</p>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Botón de filtros móvil -->
    <div class="filtros-mobile d-md-none">
        <button class="filtros-toggle" id="filtrosToggle">
            <i class="fas fa-filter"></i>
        </button>
        <div class="filtros-panel" id="filtrosPanel">
            <h6 class="mb-3">Filtros de Productos</h6>
            <div class="mb-3">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" class="form-control form-control-sm" placeholder="Buscar productos..."
                        id="searchInputMobile" value="<?php echo htmlspecialchars($search ?? ''); ?>">
                </div>
            </div>
            <div class="mb-3">
                <select class="form-select form-select-sm" id="filterCategoriaMobile">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($categorias as $categoria): ?>
                        <option value="<?php echo $categoria['id']; ?>" <?php echo (isset($categoria_filtro) && $categoria_filtro == $categoria['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($categoria['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <select class="form-select form-select-sm" id="filterProveedorMobile">
                    <option value="">Todos los proveedores</option>
                    <?php foreach ($proveedores as $proveedor): ?>
                        <option value="<?php echo $proveedor['id']; ?>" <?php echo (isset($proveedor_filtro) && $proveedor_filtro == $proveedor['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proveedor['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <select class="form-select form-select-sm" id="filterSucursalMobile">
                    <option value="">Todas las sucursales</option>
                    <?php foreach ($sucursales as $sucursal): ?>
                        <option value="<?php echo $sucursal['id']; ?>" <?php echo (isset($sucursal_filtro) && $sucursal_filtro == $sucursal['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sucursal['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="showInactiveMobile" <?php echo $show_inactive ? 'checked' : ''; ?>>
                <label class="form-check-label" for="showInactiveMobile">Mostrar inactivos</label>
            </div>
            <div class="d-grid gap-2">
                <button class="btn btn-primary btn-sm" id="btnAplicarFiltrosMobile">
                    <i class="fas fa-check me-1"></i>Aplicar Filtros
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="btnClearFiltersMobile">
                    <i class="fas fa-times me-1"></i>Limpiar Filtros
                </button>
            </div>
            <small class="result-count text-center d-block mt-2" id="resultCountMobile"><?php echo count($productos); ?> de <?php echo $total_registros; ?> productos</small>
        </div>
    </div>

    <!-- Modal para Nuevo/Editar Producto -->
    <div class="modal fade" id="productoModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <i class="fas fa-box me-2"></i>Nuevo Producto
                    </h5>
                    <?php if ($empresa_plan != 'premium'): ?>
                        <span class="badge bg-white text-primary ms-2 d-none d-md-inline">
                            <?php echo $total_productos_activos; ?>/<?php echo $limite_productos; ?>
                        </span>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <form method="POST" id="productoForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="formAction" value="crear">
                        <input type="hidden" name="id" id="productoId">
                        <input type="hidden" id="productoActivo" value="1">
                        <input type="hidden" name="imagenes_existentes" id="imagenes_existentes" value="[]">
                        <input type="hidden" name="imagen_principal" id="imagen_principal" value="0">
                        <input type="hidden" name="precios_mayoreo" id="precios_mayoreo" value="[]">

                        <!-- Campos ocultos para precios -->
                        <input type="hidden" name="subprecio" id="subprecio_hidden">
                        <input type="hidden" name="descuento" id="descuento_hidden">
                        <input type="hidden" name="precio" id="precio_hidden">
                        <input type="hidden" name="costo" id="costo_hidden">
                        <input type="hidden" name="utilidad" id="utilidad_hidden">

                        <!-- SECCIÓN PARA MÚLTIPLES IMÁGENES -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-images me-2"></i>Imágenes del Producto
                                            <small class="text-muted ms-2">(Máximo 5 imágenes)</small>
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="galeriaImagenes" class="row mb-3"></div>
                                        <div id="nuevasImagenesPreview" class="row mb-3"></div>
                                        <!-- CAMBIO PARA CÁMARA: Inputs duales para móvil y desktop -->
                                        <div class="mb-3 mobile-image-buttons">
                                            <button type="button" class="btn btn-gallery-mobile w-100" id="btnSeleccionarGaleria">
                                                <i class="fas fa-images me-2"></i>Seleccionar de Galería
                                            </button>
                                            <button type="button" class="btn btn-camera-mobile w-100" id="btnTomarFoto">
                                                <i class="fas fa-camera me-2"></i>Tomar Foto
                                            </button>
                                        </div>
                                        <div class="desktop-file-input">
                                            <label class="form-label">Agregar nuevas imágenes</label>
                                            <input type="file" class="form-control" name="imagenes[]" id="imagenes"
                                                accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                                            <small class="form-text text-muted">
                                                Formatos permitidos: JPG, PNG, GIF, WebP. Tamaño máximo: 2MB por imagen. Puedes seleccionar hasta 5 imágenes.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CÓDIGO Y NOMBRE -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Código *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="codigo" id="codigo" required>
                                        <button type="button" class="btn btn-outline-secondary" id="btnGenerarCodigo">
                                            <i class="fas fa-bolt"></i> Auto
                                        </button>
                                    </div>
                                    <small class="form-text text-muted">
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnSugerirCodigo">
                                            <i class="fas fa-lightbulb"></i> Sugerir código
                                        </button>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" name="nombre" id="nombre" required>
                                </div>
                            </div>
                        </div>

                        <!-- MARCA Y DESCRIPCIÓN -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Marca</label>
                                    <input type="text" class="form-control" name="marca" id="marca" placeholder="Ej: Sony, Samsung, etc.">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Descripción</label>
                                    <textarea class="form-control" name="descripcion" id="descripcion" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- SECCIÓN DE PRECIOS CON DESCUENTO -->
                        <div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-tags me-2"></i>Información de Precios
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4" <?php echo $hide_precio_compra_style; ?>>
                        <div class="mb-3">
                            <label class="form-label">Costo</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control d-none d-md-block" name="costo_desktop" id="costo_desktop" placeholder="0.00">
                                <input type="number" class="form-control d-md-none" name="costo_mobile" id="costo_mobile" step="0.01" min="0" placeholder="0.00">
                            </div>
                            <small class="form-text text-muted">Precio de compra del producto</small>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Utilidad (%)</label>
                            <div class="input-group">
                                <input type="text" class="form-control d-none d-md-block" name="utilidad_desktop" id="utilidad_desktop" placeholder="0.00">
                                <input type="number" class="form-control d-md-none" name="utilidad_mobile" id="utilidad_mobile" step="0.01" min="0" max="1000" placeholder="0.00">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="form-text text-muted" id="utilidad_helper">
                                Porcentaje de ganancia sobre el costo
                            </small>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Descuento (%)</label>
                            <div class="input-group">
                                <input type="text" class="form-control d-none d-md-block" name="descuento_desktop" id="descuento_desktop" value="0">
                                <input type="number" class="form-control d-md-none" name="descuento_mobile" id="descuento_mobile" step="0.01" min="0" max="100" value="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="form-text text-muted" id="utilidad_helper">Descuento sobre el precio de venta</small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Precio Venta (Base) *</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control" name="subprecio_desktop" id="subprecio_desktop" readonly style="background-color: #e9ecef;">
                                <input type="number" class="form-control" name="subprecio_mobile" id="subprecio_mobile" step="0.01" min="0" readonly style="background-color: #e9ecef;">
                            </div>
                            <small class="form-text text-muted">Calculado automáticamente (Costo + Utilidad)</small>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Precio Venta (Final) *</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control d-none d-md-block" name="precio_desktop" id="precio_desktop" >
                                <input type="number" class="form-control d-md-none" name="precio_mobile" id="precio_mobile" step="0.01" min="0" >
                            </div>
                            <small class="form-text text-muted">Precio final con descuento aplicado</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

                        <!-- SECCIÓN PRECIOS DE MAYOREO -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <div class="mayoreo-header">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-chart-line me-2"></i>Precios de Mayoreo
                                            </h6>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="habilitarMayoreo">
                                                <label class="form-check-label" for="habilitarMayoreo">
                                                    <span class="badge mayoreo-badge">Habilitar precios por cantidad</span>
                                                </label>
                                            </div>
                                        </div>
                                        <small class="text-muted">Define precios especiales según la cantidad de compra</small>
                                    </div>
                                    <div class="card-body" id="mayoreoSection" style="display: none;">
                                        <div id="reglasMayoreoContainer" class="reglas-mayoreo-container mb-3"></div>
                                        <button type="button" class="btn btn-agregar-regla" id="btnAgregarReglaMayoreo" style="display: none;">
                                            <i class="fas fa-plus me-2"></i>Agregar regla de mayoreo
                                        </button>
                                        <small class="form-text text-muted d-block mt-2">
                                            <i class="fas fa-info-circle"></i> Las reglas se aplicarán automáticamente en ventas según la cantidad.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECCIÓN: CONFIGURACIONES AVANZADAS -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-cogs me-2"></i>Configuraciones Avanzadas
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6" <?php echo $hide_unidad_medida_style; ?>>
                                                <div class="mb-3">
                                                    <label class="form-label">Unidad de Medida *</label>
                                                    <select class="form-select" name="unidad_medida" id="unidad_medida" required>
                                                        <?php foreach ($tipos_unidad_permitidos as $tipo): ?>
                                                            <option value="<?php echo $tipo; ?>">
                                                                <?php echo ucfirst($tipo); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Selecciona la unidad de medida del producto</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6" <?php echo $hide_unidad_medida_style; ?>>
                                                <div class="mb-3">
                                                    <label class="form-label" id="peso_label">Peso por Unidad (kg)</label>
                                                    <input type="number" class="form-control" name="peso_kg" id="peso_kg"
                                                        step="0.001" min="0.001" value="1.000">
                                                    <small class="form-text text-muted" id="peso_helper">Peso de cada unidad en kilogramos</small>
                                                </div>
                                            </div>
                                            <?php if (!$mostrar_unidad_medida): ?>
                                                <input type="hidden" name="unidad_medida" id="unidad_medida" value="pieza">
                                                <input type="hidden" name="peso_kg" id="peso_kg" value="1.000">
                                            <?php endif; ?>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6" <?php echo $hide_unidad_medida_style; ?>>
                                                <div class="mb-3">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="permite_fracciones" id="permite_fracciones" value="1">
                                                        <label class="form-check-label" for="permite_fracciones">
                                                            Permitir venta por fracciones
                                                        </label>
                                                        <small class="form-text text-muted d-block" id="fracciones_helper">
                                                            Para kilos y litros: permite vender fracciones (ej: 0.5 kg)<br>
                                                            Para piezas: normalmente se vende por unidad completa
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if (!$mostrar_unidad_medida): ?>
                                                <input type="hidden" name="permite_fracciones" value="0">
                                            <?php endif; ?>
                                            <div class="col-md-6" <?php echo $hide_fecha_caducidad_style; ?>>
                                                <div class="mb-3">
                                                    <label class="form-label">Fecha de Caducidad</label>
                                                    <input type="date" class="form-control" name="fecha_caducidad" id="fecha_caducidad">
                                                    <small class="form-text text-muted">Opcional - Fecha en que el producto caduca</small>
                                                </div>
                                            </div>
                                            <?php if (!$mostrar_fecha_caducidad): ?>
                                                <input type="hidden" name="fecha_caducidad" id="fecha_caducidad" value="">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CATEGORÍA Y PROVEEDOR -->
                        <div class="row">
                            <div class="col-md-6" <?php echo $hide_categoria_style; ?>>
                                <div class="mb-3">
                                    <label class="form-label">Categoría</label>
                                    <div class="input-group">
                                        <select class="form-select" name="categoria_id" id="categoria_id">
                                            <option value="">Sin categoría</option>
                                            <?php foreach ($categorias as $categoria): ?>
                                                <option value="<?php echo $categoria['id']; ?>">
                                                    <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" id="btnNuevaCategoria">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php if (!$mostrar_categoria): ?>
                                <input type="hidden" name="categoria_id" id="categoria_id" value="">
                            <?php endif; ?>
                            <div class="col-md-6" <?php echo $hide_proveedor_style; ?>>
                                <div class="mb-3">
                                    <label class="form-label">Proveedor</label>
                                    <div class="input-group">
                                        <select class="form-select" name="proveedor_id" id="proveedor_id">
                                            <option value="">Sin proveedor</option>
                                            <?php foreach ($proveedores as $proveedor): ?>
                                                <option value="<?php echo $proveedor['id']; ?>">
                                                    <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" id="btnNuevoProveedor">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php if (!$mostrar_proveedor): ?>
                                <input type="hidden" name="proveedor_id" id="proveedor_id" value="">
                            <?php endif; ?>
                        </div>

                        <!-- Sección de Sucursales y Stock -->
                        <div class="sucursal-stock-section">
                            <h6 class="sucursal-stock-header">
                                <i class="fas fa-store me-2"></i>Sucursales y Stock
                                <small class="text-muted">(Stock mínimo global: <?php echo $stock_minimo_global; ?>)</small>
                            </h6>
                            <div class="row">
                                <?php foreach ($sucursales as $sucursal): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input sucursal-checkbox" type="checkbox"
                                                name="sucursales[]" value="<?php echo $sucursal['id']; ?>"
                                                id="sucursal_<?php echo $sucursal['id']; ?>">
                                            <label class="form-check-label fw-bold" for="sucursal_<?php echo $sucursal['id']; ?>">
                                                <?php
                                                echo htmlspecialchars($sucursal['nombre']);
                                                if (strtolower(trim($sucursal['nombre'])) == 'matriz') {
                                                    echo ' <span class="badge bg-primary ms-1" style="font-size: 0.65rem;">Sucursal principal</span>';
                                                }
                                                ?>
                                            </label>
                                        </div>
                                        <div class="row mt-2 stock-fields" id="stock_fields_<?php echo $sucursal['id']; ?>" style="display: none;">
                                            <div class="col-12">
                                                <label class="form-label small">Stock</label>
                                                <input type="number" class="form-control form-control-sm stock-input"
                                                    name="stock_<?php echo $sucursal['id']; ?>"
                                                    id="stock_<?php echo $sucursal['id']; ?>"
                                                    min="0" value="0"
                                                    step="any"
                                                    data-unidad="pieza">
                                                <small class="form-text text-muted stock-unidad-indicador">Stock en unidades enteras (piezas)</small>
                                            </div>
                                            <input type="hidden" class="stock-minimo-field"
                                                name="stock_minimo_<?php echo $sucursal['id']; ?>"
                                                value="<?php echo $stock_minimo_global; ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Sección de Transferencia de Stock (solo en modo edición) -->
                        <div id="seccionTransferenciaStock" style="display:none;">
                            <hr class="my-3">
                            <h6 class="sucursal-stock-header">
                                <i class="fas fa-exchange-alt me-2"></i>Transferir Stock entre Sucursales
                            </h6>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4 col-sm-6">
                                    <label class="form-label small fw-semibold">Sucursal Origen</label>
                                    <select class="form-select form-select-sm" id="trans_sucursal_origen">
                                        <option value="">— Seleccionar —</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>" data-nombre="<?php echo htmlspecialchars($sucursal['nombre']); ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 col-sm-6">
                                    <label class="form-label small fw-semibold">Sucursal Destino</label>
                                    <select class="form-select form-select-sm" id="trans_sucursal_destino">
                                        <option value="">— Seleccionar —</option>
                                        <?php foreach ($sucursales as $sucursal): ?>
                                            <option value="<?php echo $sucursal['id']; ?>" data-nombre="<?php echo htmlspecialchars($sucursal['nombre']); ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 col-sm-4">
                                    <label class="form-label small fw-semibold">Cantidad</label>
                                    <input type="number" class="form-control form-control-sm" id="trans_cantidad" min="0.01" step="any" placeholder="0">
                                </div>
                                <div class="col-md-2 col-sm-8">
                                    <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnEjecutarTransferencia">
                                        <i class="fas fa-paper-plane me-1"></i>Transferir
                                    </button>
                                </div>
                                <div class="col-12">
                                    <input type="text" class="form-control form-control-sm" id="trans_observaciones" placeholder="Observaciones (opcional)">
                                </div>
                                <div class="col-12" id="trans_resultado" style="display:none;"></div>
                            </div>
                        </div>

                        <!-- Campo para nueva categoría (oculto) -->
                        <div class="row" id="nuevaCategoriaRow" style="display: none;">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Nueva Categoría *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control nueva-categoria-field" id="nuevaCategoriaNombre" placeholder="Nombre de la nueva categoría">
                                        <button type="button" class="btn btn-primary" id="btnGuardarCategoria">
                                            <i class="fas fa-save me-2"></i>Guardar
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="btnCancelarCategoria">
                                            <i class="fas fa-times me-2"></i>Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Campo para nuevo proveedor (oculto) -->
                        <div class="row" id="nuevoProveedorRow" style="display: none;">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Nuevo Proveedor *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control nuevo-proveedor-field" id="nuevoProveedorNombre" placeholder="Nombre del nuevo proveedor">
                                        <button type="button" class="btn btn-primary" id="btnGuardarProveedor">
                                            <i class="fas fa-save me-2"></i>Guardar
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="btnCancelarProveedor">
                                            <i class="fas fa-times me-2"></i>Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger me-auto" id="btnEliminarProducto" style="display: none;" title="Eliminar Producto">
                            <i class="fas fa-trash-alt"></i><span class="d-none d-sm-inline ms-2">Eliminar Producto</span>
                        </button>
                        <button type="button" class="btn btn-outline-info" id="btnClonarProductoModal" style="display: none;" title="Clonar">
                            <i class="fas fa-clone"></i><span class="d-none d-sm-inline ms-2">Clonar</span>
                        </button>
                        <button type="button" class="btn btn-outline-warning" id="btnToggleEstadoModal" style="display: none;" title="Activar/Desactivar">
                            <i class="fas fa-toggle-on" id="btnToggleEstadoIcono"></i><span class="d-none d-sm-inline ms-2" id="btnToggleEstadoTexto">Desactivar</span>
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i><span class="d-none d-sm-inline ms-2">Cancelar</span>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i><span class="d-none d-sm-inline ms-2">Guardar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Vista Ampliada de Imagen con Carrusel -->
    <div class="modal fade imagen-ampliada-modal" id="imagenAmpliadaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content" style="background-color: transparent; border: none;">
                <button type="button" class="btn-close-imagen" data-bs-dismiss="modal" aria-label="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
                <button type="button" class="btn-download-imagen" id="btnDescargarImagen" title="Descargar imagen">
                    <i class="fas fa-download"></i>
                </button>
                <div class="modal-body p-0">
                    <div id="imagenAmpliadaCarousel" class="carousel slide" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-inner" id="imagenAmpliadaCarouselInner"></div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#imagenAmpliadaCarousel" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Anterior</span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#imagenAmpliadaCarousel" data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Siguiente</span>
                        </button>
                        <div class="carousel-indicators" id="imagenAmpliadaCarouselIndicators"></div>
                    </div>
                    <div id="imagenCargando" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                        <div class="spinner-border text-light mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="text-light">Cargando imágenes...</p>
                    </div>
                    <div id="sinImagenMensaje" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                        <div class="text-center text-light">
                            <i class="fas fa-image fa-4x mb-3 opacity-50"></i>
                            <h5>Sin imagen disponible</h5>
                            <p class="opacity-75">Este producto no tiene imágenes asociadas</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Importar Productos -->
    <div class="modal fade" id="importarModal" tabindex="-1" aria-labelledby="importarModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importarModalLabel">
                        <i class="fas fa-file-import me-2"></i>Importar Productos
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Instrucciones:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Formatos permitidos: XLS, XLSX</li>
                            <li>La primera fila debe contener los encabezados</li>
                            <li>Campos requeridos: <strong>código, nombre, precio, costo</strong></li>
                            <li>Campos opcionales: descripción, marca, subprecio, descuento, stock, categoría, proveedor, unidad_medida, peso_kg, permite_fracciones, fecha_caducidad</li>
                        </ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">1. Descarga la plantilla</label>
                        <a href="Documentos/plantilla_productos.xlsx" class="btn btn-sm btn-outline-success d-block" download="plantilla_productos.xlsx">
                            <i class="fas fa-download me-2"></i>Descargar Plantilla
                        </a>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">2. Selecciona el archivo</label>
                        <input type="file" class="form-control" id="archivoImportar" accept=".xls,.xlsx">
                        <small class="text-muted">Máximo 5MB</small>
                    </div>
                    <div class="progress mb-3" id="importProgress" style="display: none;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                            role="progressbar" style="width: 0%" id="importProgressBar">0%</div>
                    </div>
                    <div id="importResult" style="display: none;">
                        <div class="alert" id="importResultAlert" role="alert">
                            <h6 class="alert-heading" id="importResultTitle"></h6>
                            <p id="importResultMessage"></p>
                            <div id="importResultErrors" style="max-height: 200px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cerrar
                    </button>
                    <button type="button" class="btn btn-primary" id="btnProcesarImportacion">
                        <i class="fas fa-upload me-2"></i>Importar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Reportes -->
    <div class="modal fade" id="reporteModal" tabindex="-1" aria-labelledby="reporteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white" id="reporteModalLabel">
                        <i class="fas fa-chart-bar me-2"></i>Reportes de Inventario
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-boxes fa-3x text-success mb-3"></i>
                                    <h5>Inventario de Productos</h5>
                                    <p class="text-muted">Lista completa de productos en inventario</p>
                                    <form action="reporte_inventario_completo.php" method="GET" target="_blank" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Categoría:</label>
                                            <select class="form-select" name="categoria_id">
                                                <option value="">Todas las categorías</option>
                                                <?php foreach ($categorias as $categoria): ?>
                                                    <option value="<?php echo $categoria['id']; ?>">
                                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Filtrar por Stock:</label>
                                            <select class="form-select" name="stock_filter">
                                                <option value="">Todos</option>
                                                <option value="bajo">Bajo Stock</option>
                                                <option value="sin">Sin Stock</option>
                                                <option value="normal">Stock Normal</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                                    <h5>Productos Bajo Stock</h5>
                                    <p class="text-muted">Lista de productos que requieren reabastecimiento</p>
                                    <form action="reporte_inventario_bajo_stock.php" method="GET" target="_blank" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-chart-line fa-3x text-primary mb-3"></i>
                                    <h5>Movimientos de Inventario</h5>
                                    <p class="text-muted">Historial de entradas y salidas</p>
                                    <form action="reporte_movimientos_inventario.php" method="GET" target="_blank" class="mt-3">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Fecha Inicio:</label>
                                                <input type="date" class="form-control" name="fecha_inicio"
                                                    value="<?php echo date('Y-m-01'); ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Fecha Fin:</label>
                                                <input type="date" class="form-control" name="fecha_fin"
                                                    value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Sucursal:</label>
                                            <select class="form-select" name="sucursal_id">
                                                <option value="">Todas las sucursales</option>
                                                <?php foreach ($sucursales as $sucursal): ?>
                                                    <option value="<?php echo $sucursal['id']; ?>">
                                                        <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Producto (opcional):</label>
                                            <select class="form-select" name="producto_id">
                                                <option value="">Todos los productos</option>
                                                <?php foreach ($productos as $producto): ?>
                                                    <option value="<?php echo $producto['id']; ?>">
                                                        <?php echo htmlspecialchars($producto['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-file-excel me-2"></i>Generar Excel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-chart-pie fa-3x text-info mb-3"></i>
                                    <h5>Estadísticas Rápidas</h5>
                                    <p class="text-muted">Resumen del estado del inventario</p>
                                    <div class="mt-3">
                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="metric-value text-primary"><?php echo $total_productos; ?></div>
                                                <small class="text-muted">Total Productos</small>
                                            </div>
                                            <div class="col-6">
                                                <div class="metric-value text-warning"><?php echo $bajo_stock; ?></div>
                                                <small class="text-muted">Bajo Stock</small>
                                            </div>
                                        </div>
                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="metric-value text-danger"><?php echo $sin_stock; ?></div>
                                                <small class="text-muted">Sin Stock</small>
                                            </div>
                                            <div class="col-6">
                                                <div class="metric-value text-success">$<?php echo number_format($valor_total_inventario, 2); ?></div>
                                                <small class="text-muted">Valor Inventario</small>
                                            </div>
                                        </div>
                                        <div class="alert alert-info">
                                            <small>
                                                <i class="fas fa-info-circle me-1"></i>
                                                Estos datos reflejan el estado actual del inventario
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Total de Productos -->
<div class="modal fade" id="modalTotalProductos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-box me-2"></i>Lista de Productos
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="listaTotalProductos">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-2 text-muted">Cargando productos...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Productos con Stock -->
<div class="modal fade" id="modalConStock" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i>Productos con Stock
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="listaConStock">
                <div class="text-center py-4">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-2 text-muted">Cargando productos...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Productos con Stock Bajo -->
<div class="modal fade" id="modalStockBajo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #ffc107, #fd7e14); color: #856404;">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Productos con Stock Bajo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="listaStockBajo">
                <div class="text-center py-4">
                    <div class="spinner-border text-warning" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-2 text-muted">Cargando productos...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Productos Sin Stock -->
<div class="modal fade" id="modalSinStock" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle me-2"></i>Productos Sin Stock
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="listaSinStock">
                <div class="text-center py-4">
                    <div class="spinner-border text-danger" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-2 text-muted">Cargando productos...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- SortableJS -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <script>
        window.LFG_MULTISUCURSAL = <?php echo (count($sucursales) >= 2) ? 'true' : 'false'; ?>;
        window.LFG_LIMITE_ALCANZADO = <?php echo $limite_alcanzado ? 'true' : 'false'; ?>;
        window.LFG_LIMITE_PRODUCTOS = <?php echo (int)$limite_productos; ?>;
    </script>
    <script src="js/producto.js"></script>
</body>

</html>