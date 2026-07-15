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
$api_key = "sk_user_MD3D8JvfsNHvtiR65bGokbH34FQyXo7GU65w85z1qA";

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
    $codigo, $nombre, $descripcion, $marca, $precio, $subprecio, $costo, $descuento, $utilidad, $categoria_id,
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
    $subprecio,               
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
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
            --safe-area-inset-top: env(safe-area-inset-top, 0px);
            --safe-area-inset-bottom: env(safe-area-inset-bottom, 0px);
        }

        * {
            -webkit-tap-highlight-color: transparent;
            -webkit-touch-callout: none;
        }

        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
            padding-bottom: constant(safe-area-inset-bottom);
            padding-bottom: env(safe-area-inset-bottom);
        }

        /* Estilo para indicar que la fila es clickeable */
        .producto-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
            position: relative;
        }

        /* Tooltip que aparece al hacer hover en PC */
.producto-row:hover::after {
    content: "Click para ver/editar detalles";
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    background: var(--primary-color);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    pointer-events: none;
    animation: fadeInTooltip 0.3s ease;
}

/* Indicador visual de que es clickeable - ícono flotante en móvil */
.producto-card-mobile::before {
    content: "";
    position: absolute;
    bottom: 10px;
    right: 10px;
    font-size: 1.2rem;
    opacity: 0.6;
    transition: opacity 0.2s ease;
    z-index: 5;
    background: rgba(0,0,0,0.5);
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

        .producto-card-mobile:hover::before {
    opacity: 1;
}
        /* Tooltip para móvil (aparece con tap prolongado - opcional) */
.producto-card-mobile:active::after {
    content: "Toca para ver detalles";
    position: absolute;
    bottom: 50px;
    right: 10px;
    background: var(--primary-color);
    color: white;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    white-space: nowrap;
    z-index: 15;
    pointer-events: none;
    animation: fadeInTooltip 0.2s ease;
}

/* Animación del tooltip */
@keyframes fadeInTooltip {
    from {
        opacity: 0;
        transform: translateY(5px);
    }
    to {
        opacity: 1;
        transform: translateY(-50%);
    }
}

/* Para móviles pequeños, ajustar tamaño del tooltip */
@media (max-width: 576px) {
    .producto-row:hover::after {
        font-size: 0.65rem;
        padding: 4px 8px;
        right: 10px;
    }
    
    .producto-card-mobile:active::after {
        font-size: 0.6rem;
        white-space: normal;
        max-width: 180px;
        text-align: center;
        bottom: 45px;
        right: 5px;
    }
}

/* Badge flotante en PC para indicar click */
.click-hint-badge {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--primary-color);
    color: white;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 0.8rem;
    font-weight: 500;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    opacity: 0;
    animation: slideInBadge 0.5s ease forwards 1s;
    pointer-events: none;
}

@keyframes slideInBadge {
    from {
        opacity: 0;
        transform: translateX(50px);
    }
    to {
        opacity: 0.9;
        transform: translateX(0);
    }
}

.click-hint-badge i {
    margin-right: 6px;
}

/* Ocultar badge después de 5 segundos */
.click-hint-badge.fade-out {
    opacity: 0;
    transition: opacity 1s ease;
}


        .producto-row:hover {
            background-color: rgba(39, 174, 96, 0.05) !important;
        }

        /* Estilo para las tarjetas móviles */
        .producto-card-mobile {
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
        }

        .producto-card-mobile:active {
            transform: scale(0.98);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding-top: max(8px, var(--safe-area-inset-top));
        }

        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: transform 0.3s ease-out;
            will-change: transform;
        }

        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .stat-card {
            border-left: 4px solid var(--primary-color);
        }

        .producto-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            height: 100%;
        }

        .producto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .stock-bajo {
            background: #fff3cd;
            color: #856404;
        }

        .search-box {
            position: relative;
        }

        .search-box .form-control {
            padding-left: 40px;
            padding-right: 40px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .search-loading {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }

        /* Botones de acciones - mismo tamaño */
        .btn-group-actions {
            display: flex;
            gap: 4px;
        }

        .btn-group-actions .btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 6px;
        }

        .btn-group-actions .btn i {
            font-size: 0.875rem;
            margin: 0;
        }

        /* Botones personalizados con colores del tema */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-success {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-success:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        /* Estilos para botón de autogenerar código */
        #btnGenerarCodigo {
            min-width: 70px;
            transition: all 0.3s ease;
        }

        #btnGenerarCodigo:hover {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
        }

        #btnSugerirCodigo {
            color: var(--primary-color);
            font-size: 0.85rem;
        }

        #btnSugerirCodigo:hover {
            color: var(--secondary-color);
            text-decoration: underline !important;
        }

        /* Indicador de código generado automáticamente */
        .codigo-autogenerado {
            border-color: var(--primary-color) !important;
            background-color: rgba(39, 174, 96, 0.05);
        }

        /* Animación para el botón de generar */
        .btn-generar-animacion {
            animation: pulse 0.5s ease;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Botón de editar en móvil dentro del header */
        .edit-producto-mobile {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            margin-left: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.2);
            transition: all 0.2s ease;
        }

        .edit-producto-mobile:active {
            background-color: rgba(255, 255, 255, 0.4);
            transform: scale(0.95);
        }

        /* Botón de clonar en móvil */
        .clone-producto-mobile {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            margin-left: 4px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            background-color: rgba(23, 162, 184, 0.3);
            transition: all 0.2s ease;
        }

        .clone-producto-mobile:active {
            background-color: rgba(23, 162, 184, 0.6);
            transform: scale(0.95);
        }

        /* Para móvil */
        .btn-group-actions .btn.btn-sm {
            width: 32px;
            height: 32px;
        }

        .btn-group-actions .btn.btn-sm i {
            font-size: 0.75rem;
        }

        /* Botón hamburguesa para móvil */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem;
            margin-right: 1rem;
            width: 44px;
            height: 44px;
            border-radius: 8px;
            transition: background 0.2s ease;
        }

        .sidebar-toggle:active {
            background: rgba(255, 255, 255, 0.2);
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-backdrop.show {
            display: block;
            opacity: 1;
        }

        /* IMPORTANTE: ESTILOS PARA QUE EL SIDEBAR SE MUEVA SOLO */
        body.sidebar-open {
            overflow: hidden;
        }

        /* Estilos para paginación */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding: 1rem 0;
            border-top: 1px solid #dee2e6;
        }

        .pagination-info {
            color: #6c757d;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .pagination {
            margin-bottom: 0;
        }

        .pagination .page-link {
            color: #495057;
            border: 1px solid #dee2e6;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .pagination .page-link:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
            color: #495057;
        }

        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white !important;
        }

        .pagination .page-item.disabled .page-link {
            color: #6c757d;
            background-color: #f8f9fa;
            border-color: #dee2e6;
            opacity: 0.6;
        }

        /* Estilos para el select de unidad de medida */
        .unidad-medida-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            margin-left: 0.5rem;
        }

        .unidad-pieza {
            background: #17a2b8;
            color: white;
        }

        .unidad-kilo {
            background: #28a745;
            color: white;
        }

        .unidad-litro {
            background: #6f42c1;
            color: white;
        }

        /* Badge para tipo de producto */
        .tipo-producto-badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
            background: #fd7e14;
            color: white;
        }

        /* Badge para merma */
        .merma-badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
            background: #20c997;
            color: white;
        }

        .merma-warning {
            background: #ffc107;
            color: #856404;
        }

        /* ============================================= */
        /* ESTILOS PARA LA GALERÍA DE IMÁGENES MÚLTIPLES */
        /* ============================================= */

        .galeria-item {
            position: relative;
            margin-bottom: 15px;
        }

        .galeria-item .imagen-container {
            position: relative;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            cursor: move;
            transition: all 0.3s ease;
        }

        .galeria-item .imagen-container:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .galeria-item .imagen-container.principal {
            border-color: var(--primary-color);
            border-width: 3px;
            box-shadow: 0 0 0 2px rgba(39, 174, 96, 0.3);
        }

        .galeria-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            background: #f8f9fa;
        }

        .galeria-item .badge-principal {
            position: absolute;
            top: 5px;
            left: 5px;
            background: var(--primary-color);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .galeria-item .btn-eliminar-imagen {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 2;
        }

        .galeria-item .btn-eliminar-imagen:hover {
            background: #dc3545;
            transform: scale(1.1);
        }

        .galeria-item .btn-principal {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 2;
            font-size: 1rem;
        }

        .galeria-item .btn-principal:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

        .galeria-item .btn-principal.activo {
            background: var(--primary-color);
            color: white;
            border-color: white;
        }

        /* Estilos para drag & drop */
        .galeria-item .imagen-container.dragging {
            opacity: 0.5;
            transform: scale(0.98);
        }

        .galeria-sortable-ghost {
            opacity: 0.5;
            background: #e9ecef;
            border: 2px dashed var(--primary-color);
        }

        .galeria-sortable-drag {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        /* Preview de nuevas imágenes */
        .nueva-imagen-preview {
            position: relative;
            margin-bottom: 15px;
        }

        .nueva-imagen-preview img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid var(--primary-color);
        }

        .nueva-imagen-preview .btn-eliminar-nueva {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .nueva-imagen-preview .btn-eliminar-nueva:hover {
            background: #dc3545;
            transform: scale(1.1);
        }

        /* ============================================= */
        /* [CARRUSEL] ESTILOS PARA LOS CARRUSELES DE IMÁGENES - VERSIÓN CUADRADA */
        /* ============================================= */

        /* Estilos para el carrusel pequeño en la tabla - AHORA CUADRADO */
        .producto-imagen-carousel {
            width: 60px !important;
            height: 60px !important;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
            background-color: #f8f9fa;
            margin: 0 auto;
            display: block;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* Asegurar que el carrusel y sus hijos ocupen todo el espacio cuadrado */
        .producto-imagen-carousel .carousel,
        .producto-imagen-carousel .carousel-inner,
        .producto-imagen-carousel .carousel-item {
            width: 100% !important;
            height: 100% !important;
            position: relative;
        }

        /* Estilo para las imágenes dentro del carrusel - cubren todo el cuadrado */
        .producto-imagen-carousel .carousel-item img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .producto-imagen-carousel .carousel-item img:hover {
            transform: scale(1.1);
        }

        /* Controles de navegación pequeños y elegantes */
        .producto-imagen-carousel .carousel-control-prev,
        .producto-imagen-carousel .carousel-control-next {
            width: 18px;
            height: 18px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            transition: opacity 0.2s ease, background 0.2s ease;
            z-index: 10;
        }

        .producto-imagen-carousel:hover .carousel-control-prev,
        .producto-imagen-carousel:hover .carousel-control-next {
            opacity: 1;
        }

        .producto-imagen-carousel .carousel-control-prev {
            left: 2px;
        }

        .producto-imagen-carousel .carousel-control-next {
            right: 2px;
        }

        .producto-imagen-carousel .carousel-control-prev:hover,
        .producto-imagen-carousel .carousel-control-next:hover {
            background: rgba(0, 0, 0, 0.8);
        }

        .producto-imagen-carousel .carousel-control-prev-icon,
        .producto-imagen-carousel .carousel-control-next-icon {
            width: 10px;
            height: 10px;
        }

        /* Indicadores pequeños en la parte inferior */
        .producto-imagen-carousel .carousel-indicators {
            bottom: -2px;
            margin-bottom: 0;
            z-index: 10;
            gap: 2px;
        }

        .producto-imagen-carousel .carousel-indicators [data-bs-target] {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background-color: var(--primary-color);
            margin: 0;
            border: none;
            opacity: 0.7;
        }

        .producto-imagen-carousel .carousel-indicators .active {
            opacity: 1;
            transform: scale(1.2);
        }

        /* Versión para móvil del carrusel en cards */
        @media (max-width: 767.98px) {
            .producto-imagen-carousel {
                width: 70px !important;
                height: 70px !important;
            }

            .producto-imagen-carousel .carousel-control-prev,
            .producto-imagen-carousel .carousel-control-next {
                width: 20px;
                height: 20px;
                opacity: 0.7;
            }
        }

        /* Estilos para el carrusel grande en el modal de ampliación */
        #imagenAmpliadaCarousel {
            background-color: #2c3e50;
            border-radius: 12px;
            overflow: hidden;
            width: 100%;
            height: 100%;
        }

        #imagenAmpliadaCarousel .carousel-inner {
            min-height: 400px;
            display: flex;
            align-items: center;
            background-color: #1a2632;
        }

        #imagenAmpliadaCarousel .carousel-item {
            text-align: center;
        }

        #imagenAmpliadaCarousel .carousel-item img {
            max-width: 100%;
            max-height: 80vh;
            object-fit: contain;
            margin: 0 auto;
        }

        #imagenAmpliadaCarousel .carousel-control-prev,
        #imagenAmpliadaCarousel .carousel-control-next {
            width: 15%;
            opacity: 0.7;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            margin: 10px;
            height: 50px;
            top: 50%;
            transform: translateY(-50%);
        }

        #imagenAmpliadaCarousel .carousel-control-prev:hover,
        #imagenAmpliadaCarousel .carousel-control-next:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.5);
        }

        #imagenAmpliadaCarousel .carousel-indicators {
            bottom: 10px;
        }

        #imagenAmpliadaCarousel .carousel-indicators [data-bs-target] {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--primary-color);
            margin: 0 4px;
        }

        /* Botones flotantes para el modal de imagen */
        .btn-close-imagen,
        .btn-download-imagen {
            position: fixed;
            top: 20px;
            z-index: 1060;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.3s ease, transform 0.2s ease;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-close-imagen {
            right: 20px;
        }

        .btn-download-imagen {
            right: 80px;
        }

        .btn-close-imagen:hover,
        .btn-download-imagen:hover {
            background: rgba(0, 0, 0, 0.8);
            transform: scale(1.1);
        }

        .btn-close-imagen:active,
        .btn-download-imagen:active {
            transform: scale(0.95);
        }

        @media (max-width: 767.98px) {
            #imagenAmpliadaCarousel .carousel-inner {
                min-height: 300px;
            }

            .btn-close-imagen,
            .btn-download-imagen {
                width: 40px;
                height: 40px;
                top: 10px;
            }

            .btn-close-imagen {
                right: 10px;
            }

            .btn-download-imagen {
                right: 60px;
            }
        }

        /* ============================================= */
        /* MODAL RESPONSIVE MEJORADO - CON FOOTER FIJO */
        /* ============================================= */

        /* Estilos base del modal */
        .modal-dialog {
            margin: 1.75rem auto;
            max-height: calc(100vh - 3.5rem);
        }

        .modal-dialog-scrollable {
            display: flex;
            flex-direction: column;
        }

        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 3.5rem);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 16px 20px;
            border-bottom: none;
            border-radius: 12px 12px 0 0;
            flex-shrink: 0;
        }

        .modal-header .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-header .modal-title i {
            font-size: 1.2rem;
        }

        .modal-header .btn-close {
            background-color: rgba(255, 255, 255, 0.3);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3E%3Cpath d='M.293.293a1 1 0 011.414 0L8 6.586 14.293.293a1 1 0 111.414 1.414L9.414 8l6.293 6.293a1 1 0 01-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 01-1.414-1.414L6.586 8 .293 1.707a1 1 0 010-1.414z'/%3E%3C/svg%3E");
            background-size: 12px;
            opacity: 1;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            margin: 0;
            padding: 0;
            transition: all 0.3s ease;
        }

        .modal-header .btn-close:hover {
            background-color: rgba(255, 255, 255, 0.5);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 20px;
            background: #f8f9fa;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            max-height: calc(100vh - 140px);
        }

        /* Scroll personalizado */
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }

        .modal-footer {
            background: white;
            padding: 16px 20px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 0 0 12px 12px;
            flex-shrink: 0;
            display: flex;
            gap: 12px;
            position: sticky;
            bottom: 0;
            z-index: 1050;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
        }

        /* Cards dentro del modal */
        .modal-body .card {
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .modal-body .card-header {
            background: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 16px;
            border-radius: 12px 12px 0 0 !important;
        }

        .modal-body .card-header h6 {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .modal-body .card-header i {
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .modal-body .card-body {
            padding: 20px;
        }

        /* Inputs del modal */
        .modal-body .form-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 6px;
        }

        .modal-body .form-control,
        .modal-body .form-select {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
            transition: all 0.2s ease;
        }

        .modal-body .form-control:focus,
        .modal-body .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.15);
            outline: none;
        }

        /* Sección de sucursales */
        .modal-body .sucursal-stock-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
        }

        .modal-body .sucursal-stock-section .form-check {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin: 0;
            border: 1px solid #e9ecef;
        }

        .modal-body .sucursal-stock-section .form-check-input {
            width: 20px;
            height: 20px;
            margin-top: 0;
        }

        .modal-body .sucursal-stock-section .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .modal-body .sucursal-stock-section .stock-fields {
            margin-top: 12px;
            background: white;
            border-radius: 8px;
            padding: 15px;
            border: 1px dashed var(--primary-color);
        }

        /* Destello al actualizar stock tras transferencia */
        @keyframes stockActualizadoFlash {
            0% {
                background-color: #d4edda;
                border-color: #28a745;
            }

            60% {
                background-color: #c3e6cb;
                border-color: #28a745;
            }

            100% {
                background-color: white;
                border-color: var(--primary-color);
            }
        }

        .stock-actualizado-highlight {
            animation: stockActualizadoFlash 1.5s ease-out forwards;
        }

        /* Footer buttons */
        .modal-footer .btn {
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 500;
            min-width: 120px;
        }

        .modal-footer .btn-primary {
            background: var(--primary-color);
            border: none;
        }

        .modal-footer .btn-primary:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .modal-footer .btn-secondary {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
        }

        .modal-footer .btn-secondary:hover {
            background: #e9ecef;
        }

        /* ============================================= */
        /* ESTILOS PARA PRECIOS DE MAYOREO */
        /* ============================================= */

        .mayoreo-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .mayoreo-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .regla-mayoreo-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }

        .regla-mayoreo-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .regla-mayoreo-inputs {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .regla-mayoreo-inputs .form-control {
            flex: 1;
            min-width: 120px;
        }

        .btn-eliminar-regla {
            background: none;
            border: none;
            color: #dc3545;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0 10px;
            transition: all 0.2s ease;
        }

        .btn-eliminar-regla:hover {
            color: #c82333;
            transform: scale(1.1);
        }

        .btn-agregar-regla {
            background: none;
            border: 1px dashed var(--primary-color);
            color: var(--primary-color);
            padding: 10px;
            border-radius: 8px;
            width: 100%;
            transition: all 0.2s ease;
        }

        .btn-agregar-regla:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .reglas-mayoreo-container {
            max-height: 300px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .reglas-mayoreo-container::-webkit-scrollbar {
            width: 6px;
        }

        .reglas-mayoreo-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .reglas-mayoreo-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 3px;
        }

        /* ============================================= */
        /* ESTILOS PARA MÓVIL - MODAL FULL SCREEN */
        /* ============================================= */

        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 56px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
            }

            /* Ajustes para estadísticas en móvil */
            .stat-card .card-body {
                padding: 1rem;
            }

            .metric-value {
                font-size: 1.5rem;
            }

            /* Ajustes para tabla en móvil */
            .table-responsive {
                font-size: 0.875rem;
            }

            .btn-group .btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }

            /* Vista de cards para móvil */
            .producto-grid {
                display: none;
            }

            .producto-cards {
                display: block;
            }

            .pagination-container {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .pagination {
                flex-wrap: wrap;
                justify-content: center;
            }

            .pagination .page-link {
                padding: 0.375rem 0.75rem;
                font-size: 0.8rem;
                min-width: 40px;
                text-align: center;
            }

            /* Ajustes para el modal en móvil - FULL SCREEN */
            .modal-dialog {
                margin: 0;
                height: 100vh;
                max-height: 100vh;
                width: 100vw;
                max-width: 100vw;
            }

            .modal-content {
                border-radius: 0;
                height: 100vh;
                max-height: 100vh;
                border: none;
            }

            .modal-header {
                border-radius: 0;
                padding: 12px 16px;
                padding-top: max(12px, var(--safe-area-inset-top));
                min-height: 60px;
            }

            .modal-header .modal-title {
                font-size: 1rem;
            }

            .modal-header .btn-close {
                width: 40px;
                height: 40px;
            }

            .modal-body {
                padding: 16px;
                max-height: calc(100vh - 130px);
                -webkit-overflow-scrolling: touch;
                padding-bottom: 20px;
            }

            /* Scroll más delgado para móvil */
            .modal-body::-webkit-scrollbar {
                width: 4px;
            }

            .modal-footer {
                padding: 12px 16px;
                padding-bottom: max(12px, var(--safe-area-inset-bottom));
                background: white;
                border-top: 1px solid rgba(0, 0, 0, 0.1);
                gap: 8px;
                min-height: 70px;
                display: flex;
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                position: sticky;
                bottom: 0;
                z-index: 1050;
                box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.1);
            }

            /* Inputs más grandes para móvil */
            .modal-body .form-control,
            .modal-body .form-select {
                height: 48px;
                font-size: 16px !important;
                padding: 12px 14px;
            }

            /* Botones del footer - Versión móvil */
            .modal-footer .btn {
                height: 48px;
                flex: 1;
                font-size: 0.95rem;
                padding: 0 8px;
                margin: 0;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                min-width: 0;
                font-weight: 500;
                letter-spacing: 0.3px;
            }

            .modal-footer .btn i {
                font-size: 1rem;
                margin-right: 6px;
            }

            .modal-footer .btn-secondary {
                background: #f1f3f4;
                border: 1px solid #dadce0;
                color: #3c4043;
            }

            .modal-footer .btn-secondary:active {
                background: #e8eaed;
                transform: scale(0.98);
            }

            .modal-footer .btn-primary {
                background: var(--primary-color);
                border: none;
                color: white;
                font-weight: 600;
                box-shadow: 0 2px 6px rgba(39, 174, 96, 0.3);
            }

            .modal-footer .btn-primary:active {
                background: var(--secondary-color);
                transform: scale(0.98);
            }

            /* Ajustes específicos para iOS */
            @supports (-webkit-touch-callout: none) {
                .modal-body {
                    -webkit-overflow-scrolling: touch;
                }

                .modal-footer {
                    padding-bottom: max(20px, var(--safe-area-inset-bottom));
                }
            }

            /* Ajustes para mayoreo en móvil */
            .regla-mayoreo-inputs {
                flex-direction: column;
            }

            .regla-mayoreo-inputs .form-control {
                width: 100%;
            }

            .btn-eliminar-regla {
                align-self: flex-end;
            }
        }

        @media (min-width: 768px) {
            .producto-cards {
                display: none;
            }
        }

        /* Mejoras visuales */
        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-group-actions .btn {
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-group-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Botón de clonar */
        .btn-group-actions .btn-outline-info {
            color: #17a2b8;
            border-color: #17a2b8;
        }

        .btn-group-actions .btn-outline-info:hover {
            background-color: #17a2b8;
            border-color: #17a2b8;
            color: white;
        }

        /* Badges mejorados */
        .badge-stock {
            font-size: 0.75rem;
            white-space: nowrap;
        }

        .badge-precio {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 0.5rem;
        }

        .badge-subprecio {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
            padding: 0.5rem;
        }

        .badge-descuento {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
            padding: 0.5rem;
        }

        .badge-costo {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
            padding: 0.5rem;
        }

        /* Cards de productos en móvil */
        .producto-card-mobile {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            margin-bottom: 1rem;
            background: white;
            transition: all 0.3s ease;
        }

        .producto-card-mobile:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .producto-card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1rem;
        }

        .producto-card-body {
            padding: 1rem;
        }

        .producto-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .producto-info-label {
            font-weight: 600;
            color: #6c757d;
            min-width: 80px;
        }

        .producto-info-value {
            flex: 1;
        }

        /* Filtros móviles */
        .filtros-mobile {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 1020;
        }

        .filtros-toggle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.2s ease;
        }

        .filtros-toggle:active {
            transform: scale(0.95);
            background: var(--secondary-color);
        }

        .filtros-panel {
            position: fixed;
            bottom: 90px;
            left: 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            padding: 1rem;
            z-index: 1010;
            min-width: 280px;
            display: none;
        }

        .filtros-panel.show {
            display: block;
            animation: slideInUp 0.3s ease-out;
        }

        @keyframes slideInUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Scroll suave */
        .table-responsive {
            scrollbar-width: thin;
            scrollbar-color: #c1c1c1 #f1f1f1;
        }

        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Estilos para nueva categoría y proveedor */
        .nueva-categoria-field,
        .nuevo-proveedor-field {
            border: 2px dashed #28a745;
            background-color: #f8fff9;
        }

        /* Estilos para múltiples sucursales */
        .sucursal-stock-section {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }

        .sucursal-stock-header {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #495057;
        }

        .stock-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        .sucursales-list {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.5rem;
        }

        /* Estilos para productos con fracciones */
        .tipo-venta-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
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

        /* Colores personalizados para badges */
        .badge.bg-primary {
            background-color: var(--primary-color) !important;
        }

        .badge.bg-success {
            background-color: var(--secondary-color) !important;
        }

        .metric-value.text-primary {
            color: var(--primary-color) !important;
        }

        .metric-value.text-success {
            color: var(--secondary-color) !important;
        }

        .fa-2x.text-primary {
            color: var(--primary-color) !important;
        }

        .fa-2x.text-success {
            color: var(--secondary-color) !important;
        }

        /* Estilos para imagen del producto */
        .image-preview-container {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 10px;
            background: #f8f9fa;
            text-align: center;
        }

        .image-preview-container img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
        }

        .producto-imagen {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .producto-imagen:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .producto-imagen-mobile {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .producto-imagen-mobile:active {
            transform: scale(0.95);
        }

        /* Para imágenes sin vista previa */
        .no-imagen-container {
            cursor: pointer !important;
        }

        .no-imagen-container:hover {
            background-color: #f8f9fa !important;
        }

        /* Efecto de overlay en hover */
        .imagen-con-overlay {
            position: relative;
        }

        .imagen-con-overlay::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            background: rgba(0, 0, 0, 0.5);
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }

        .imagen-con-overlay:hover::after {
            opacity: 1;
        }

        /* Zona de swipe para abrir sidebar */
        #swipeZoneIndicator {
            position: fixed;
            top: 56px;
            left: 0;
            width: 30px;
            height: calc(100vh - 56px);
            background: rgba(39, 174, 96, 0.1);
            z-index: 9999;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s;
        }

        /* Responsive para móvil de la galería */
        @media (max-width: 767.98px) {

            .galeria-item img,
            .nueva-imagen-preview img {
                height: 120px;
            }

            .galeria-item .btn-eliminar-imagen,
            .galeria-item .btn-principal,
            .nueva-imagen-preview .btn-eliminar-nueva {
                width: 42px;
                height: 42px;
                font-size: 1.1rem;
            }

            .galeria-item .badge-principal {
                font-size: 0.8rem;
                padding: 5px 10px;
            }
        }

        /* Para valores decimales */
        .stock-input[step="0.001"] {
            -moz-appearance: textfield;
        }

        .stock-input[step="0.001"]::-webkit-inner-spin-button,
        .stock-input[step="0.001"]::-webkit-outer-spin-button {
            opacity: 0.5;
        }

        /* Tooltip para mostrar stock con unidades */
        [data-stock-tooltip] {
            cursor: help;
        }

        /* Estilos para botones de cámara móvil */
        .btn-camera-mobile {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }

        .btn-camera-mobile:active {
            transform: scale(0.98);
        }

        .btn-gallery-mobile {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }

        .btn-gallery-mobile:active {
            transform: scale(0.98);
        }

        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        @media (max-width: 767.98px) {
            .mobile-image-buttons {
                display: flex;
                gap: 10px;
            }

            .mobile-image-buttons .btn {
                flex: 1;
                padding: 12px;
                font-size: 0.9rem;
            }

            .desktop-file-input {
                display: none;
            }
        }

        @media (min-width: 768px) {
            .mobile-image-buttons {
                display: none;
            }

            .desktop-file-input {
                display: block;
            }
        }

        /* Estilos para las listas de productos en modales estadísticos */
.lista-productos-stats {
    list-style: none;
    padding: 0;
    margin: 0;
}

.lista-productos-stats li {
    padding: 12px 15px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: background-color 0.2s ease;
}

.lista-productos-stats li:hover {
    background-color: #f8f9fa;
}

.lista-productos-stats li:last-child {
    border-bottom: none;
}

.producto-nombre-stats {
    flex: 1;
    font-weight: 500;
    color: #2c3e50;
}

.producto-stock-stats {
    font-size: 0.85rem;
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 500;
    white-space: nowrap;
}

.stock-normal-stats {
    background-color: #d4edda;
    color: #155724;
}

.stock-bajo-stats {
    background-color: #fff3cd;
    color: #856404;
}

.stock-cero-stats {
    background-color: #f8d7da;
    color: #721c24;
}

.producto-codigo-stats {
    font-size: 0.75rem;
    color: #6c757d;
    font-family: monospace;
}

.badge-unidad-stats {
    font-size: 0.65rem;
    padding: 3px 8px;
    border-radius: 12px;
    background-color: #e9ecef;
    color: #495057;
}

.empty-state-stats {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
}

.empty-state-stats i {
    font-size: 3rem;
    margin-bottom: 15px;
    opacity: 0.5;
}

/* ============================================= */
/* ESTILOS PARA TOOLTIPS INFORMATIVOS */
/* ============================================= */

.label-with-tooltip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: help;
}

.info-tooltip-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background-color: var(--primary-color);
    color: white;
    font-size: 0.7rem;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
    margin-left: 4px;
}

.info-tooltip-icon i {
    font-size: 0.65rem;
}

.info-tooltip-icon:hover {
    transform: scale(1.1);
    background-color: var(--secondary-color);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.info-tooltip-icon:active {
    transform: scale(0.95);
}

/* Tooltip flotante personalizado */
.custom-tooltip {
    position: fixed;
    z-index: 10000;
    background: #2c3e50;
    color: white;
    padding: 10px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    max-width: 280px;
    text-align: left;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    backdrop-filter: blur(8px);
    background: rgba(44, 62, 80, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.2);
    pointer-events: none;
    line-height: 1.4;
    font-weight: normal;
    transition: opacity 0.2s ease;
}

.custom-tooltip::before {
    content: '';
    position: absolute;
    top: -6px;
    left: 15px;
    width: 12px;
    height: 12px;
    background: rgba(44, 62, 80, 0.95);
    transform: rotate(45deg);
    border-left: 1px solid rgba(255, 255, 255, 0.2);
    border-top: 1px solid rgba(255, 255, 255, 0.2);
}

.custom-tooltip.bottom::before {
    top: auto;
    bottom: -6px;
    transform: rotate(45deg);
    border-left: none;
    border-top: none;
    border-right: 1px solid rgba(255, 255, 255, 0.2);
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.custom-tooltip i {
    margin-right: 8px;
    color: var(--secondary-color);
}

/* Animación del tooltip */
@keyframes tooltipFadeIn {
    from {
        opacity: 0;
        transform: translateY(5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.custom-tooltip.show {
    animation: tooltipFadeIn 0.2s ease forwards;
}

/* Para móvil - tooltips más grandes y con fondo más oscuro */
@media (max-width: 767.98px) {
    .custom-tooltip {
        max-width: 260px;
        font-size: 0.8rem;
        padding: 12px 16px;
        border-radius: 16px;
    }
    
    .info-tooltip-icon {
        width: 22px;
        height: 22px;
        font-size: 0.75rem;
    }
    
    .info-tooltip-icon i {
        font-size: 0.7rem;
    }
}

/* Tooltip para dispositivos táctiles - más grande */
@media (hover: none) and (pointer: coarse) {
    .custom-tooltip {
        max-width: 280px;
        font-size: 0.9rem;
        padding: 14px 18px;
        border-radius: 18px;
    }
}
    </style>
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="Inicio">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="Inicio">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Usuarios">
                                    <i class="fas fa-user-cog"></i>
                                    Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="Caja">
                                <i class="fas fa-cash-register"></i>
                                Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="Productos">
                                <i class="fas fa-boxes"></i>
                                Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Clientes">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Ventas">
                                <i class="fas fa-receipt"></i>
                                Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="CortesCaja">
                                <i class="fas fa-cash-register"></i>
                                Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Proveedores">
                                <i class="fas fa-truck"></i>
                                Proveedores
                            </a>
                        </li>
                        <!-- MENÚ DE SUCURSALES CONDICIONAL -->
                        <?php if ($empresa_plan !== 'basico'  && $_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Sucursales">
                                    <i class="fas fa-store"></i>
                                    Sucursales
                                </a>
                            </li>
                        <?php endif; ?>
                        <!-- MENÚ DE FACTURACIÓN CONDICIONAL -->
                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1  && $timbres_totales > 0) : ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Facturacion/inicio.php">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    Facturación
                                    <?php if ($timbres_disponibles > 0): ?>
                                        <span class="badge bg-success ms-2" style="font-size: 0.65rem;">
                                            <?php echo $timbres_disponibles; ?> timbres
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning ms-2" style="font-size: 0.65rem;">
                                            Sin timbres
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i>
                                    Comisiones
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="Configuracion">
                                    <i class="fas fa-cogs"></i>
                                    Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

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

                        <!-- SECCIÓN: TIPO DE PRODUCTO -->
                        <div class="row mb-4" <?php echo $hide_tipo_producto_style; ?>>
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-tag me-2"></i>Tipo de Producto
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="mb-3">
                                                    <label class="form-label">Clasificación / Calidad</label>
                                                    <select class="form-select" name="tipo_producto" id="tipo_producto">
                                                        <?php foreach ($tipos_producto_permitidos as $tipo): ?>
                                                            <option value="<?php echo htmlspecialchars($tipo); ?>">
                                                                <?php echo htmlspecialchars($tipo); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Clasifica el producto por calidad, tamaño o categoría especial</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if (!$mostrar_tipo_producto): ?>
                            <input type="hidden" name="tipo_producto" id="tipo_producto" value="Estandar">
                        <?php endif; ?>

                        <!-- SECCIÓN: AJUSTES POR MERMA -->
                        <div class="row mb-4" <?php echo $hide_merma_style; ?>>
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-charging-station me-2"></i>Ajustes por Merma / Desgaste
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Merma por Daño (%)</label>
                                                    <input type="number" class="form-control" name="porcentaje_merma_danado" id="porcentaje_merma_danado"
                                                        step="0.01" min="0" max="100" value="<?php echo $config_merma['porcentaje_danado']; ?>">
                                                    <small class="form-text text-muted">Porcentaje de producto que se daña normalmente</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Merma por Deshidratación / Desgaste (%)</label>
                                                    <input type="number" class="form-control" name="porcentaje_merma_deshidratacion" id="porcentaje_merma_deshidratacion"
                                                        step="0.01" min="0" max="100" value="<?php echo $config_merma['porcentaje_deshidratacion']; ?>">
                                                    <small class="form-text text-muted">Para productos perecederos o que pierden peso</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="aplicar_merma_venta" id="aplicar_merma_venta" value="1" <?php echo $config_merma['aplicar_merma_venta'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="aplicar_merma_venta">
                                                        Aplicar merma al calcular existencias en venta
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="aplicar_merma_compra" id="aplicar_merma_compra" value="1" <?php echo $config_merma['aplicar_merma_compra'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="aplicar_merma_compra">
                                                        Aplicar merma al recibir mercancía
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="alert alert-info mt-2">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <small>La merma se aplicará automáticamente en los cálculos de inventario. Por ejemplo, al comprar 100kg con 5% de merma, se registrarán 95kg disponibles.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if (!$mostrar_merma): ?>
                            <input type="hidden" name="porcentaje_merma_danado" id="porcentaje_merma_danado" value="0">
                            <input type="hidden" name="porcentaje_merma_deshidratacion" id="porcentaje_merma_deshidratacion" value="0">
                            <input type="hidden" name="aplicar_merma_venta" id="aplicar_merma_venta" value="0">
                            <input type="hidden" name="aplicar_merma_compra" id="aplicar_merma_compra" value="0">
                        <?php endif; ?>

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
        $(document).ready(function() {
            // =============================================
            // VARIABLES GLOBALES
            // =============================================
            let searchTimeout = null;
            let isSearching = false;
            let currentPage = 1;
            let currentSearch = '';
            let cargandoProductos = false;

            let imagenesExistentes = [];
            let nuevasImagenes = [];
            let reglasMayoreo = [];
            let mayoreoHabilitado = false;

            let filtrosActuales = {
                search: '',
                categoria: '',
                proveedor: '',
                sucursal: '',
                show_inactive: false,
                pagina: 1
            };

            // =============================================
            // FUNCIONES DE FILTRADO AJAX
            // =============================================

            function obtenerValoresFiltros() {
                return {
                    search: $('#searchInput').val(),
                    categoria: $('#filterCategoria').val(),
                    proveedor: $('#filterProveedor').val(),
                    sucursal: $('#filterSucursal').val(),
                    show_inactive: $('#showInactive').is(':checked'),
                    pagina: filtrosActuales.pagina
                };
            }

            function sincronizarFiltrosMoviles() {
                $('#searchInputMobile').val(filtrosActuales.search);
                $('#filterCategoriaMobile').val(filtrosActuales.categoria);
                $('#filterProveedorMobile').val(filtrosActuales.proveedor);
                $('#filterSucursalMobile').val(filtrosActuales.sucursal);
                $('#showInactiveMobile').prop('checked', filtrosActuales.show_inactive);
            }

            function actualizarFiltrosDesdeMoviles() {
                $('#searchInput').val($('#searchInputMobile').val());
                $('#filterCategoria').val($('#filterCategoriaMobile').val());
                $('#filterProveedor').val($('#filterProveedorMobile').val());
                $('#filterSucursal').val($('#filterSucursalMobile').val());
                $('#showInactive').prop('checked', $('#showInactiveMobile').is(':checked'));
            }

            function mostrarCargando(mostrar) {
                if (mostrar) {
                    cargandoProductos = true;
                    $('#searchLoading').show();
                    $('#productsTableBody').html(`
                <tr>
                    <td colspan="14" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2 text-muted">Cargando productos...</p>
                    </td>
                </tr>
            `);
                    $('#mobileProductsContainer').empty();
                    $('#mobileProductsContainer').append(`
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-2 text-muted">Cargando productos...</p>
                </div>
            `);
                } else {
                    cargandoProductos = false;
                    $('#searchLoading').hide();
                    $('#mobileProductsContainer .spinner-border').closest('.text-center').remove();
                }
            }

            function mostrarMensajeTemporal(mensaje, tipo) {
                const alertDiv = $(`
            <div class="alert alert-${tipo} alert-dismissible fade show" role="alert" style="position: fixed; top: 70px; right: 20px; z-index: 9999; min-width: 250px; z-index: 1060;">
                <i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'} me-2"></i>
                ${mensaje}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `);
                $('body').append(alertDiv);
                setTimeout(() => {
                    alertDiv.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 3000);
            }

            function formatearStockJS(stock, unidad) {
                if (stock === undefined || stock === null) stock = 0;
                let stockFormateado;
                if (stock % 1 !== 0) {
                    stockFormateado = parseFloat(stock).toFixed(3).replace(/\.?0+$/, '');
                } else {
                    stockFormateado = Math.floor(stock);
                }
                let sufijo = '';
                switch (unidad) {
                    case 'kg':
                    case 'kilo':
                    case 'kilogramo':
                        sufijo = ' kg';
                        break;
                    case 'litro':
                    case 'l':
                        sufijo = ' L';
                        break;
                    case 'tonelada':
                    case 'ton':
                        sufijo = ' ton';
                        break;
                    case 'pieza':
                        sufijo = stock == 1 ? ' pieza' : ' piezas';
                        break;
                    case 'unidad':
                        sufijo = stock == 1 ? ' unidad' : ' unidades';
                        break;
                    default:
                        sufijo = '';
                }
                return stockFormateado + sufijo;
            }

            // Agregar badge flotante de ayuda (solo para PC, primera visita)
function mostrarAyudaClickeable() {
    // Verificar si ya se mostró antes
    if (!localStorage.getItem('clickHintShown')) {
        const hintBadge = $('<div class="click-hint-badge"><i class="fas fa-mouse-pointer"></i> Haz clic en cualquier producto para ver/editar detalles</div>');
        $('body').append(hintBadge);
        
        // Auto-ocultar después de 5 segundos
        setTimeout(() => {
            hintBadge.addClass('fade-out');
            setTimeout(() => hintBadge.remove(), 1000);
        }, 5000);
        
        // También ocultar al hacer clic en cualquier producto
        $(document).one('click', '.producto-row, .producto-card-mobile', function() {
            hintBadge.addClass('fade-out');
            setTimeout(() => hintBadge.remove(), 500);
        });
        
        localStorage.setItem('clickHintShown', 'true');
    }
}

// Llamar a la función después de 1 segundo
setTimeout(mostrarAyudaClickeable, 1000);

// Agregar título/tooltip nativo para dispositivos que lo soporten
$('.producto-row, .producto-card-mobile').attr('title', 'Haz clic para ver/editar detalles del producto');

            function formatearFechaCaducidadJS(fecha) {
                if (!fecha) return '<span class="text-muted small">N/A</span>';
                const fechaObj = new Date(fecha);
                const hoy = new Date();
                hoy.setHours(0, 0, 0, 0);
                const diffDays = Math.ceil((fechaObj - hoy) / (1000 * 60 * 60 * 24));
                if (diffDays < 0) {
                    return '<span class="badge bg-danger" title="Producto vencido"><i class="fas fa-exclamation-triangle"></i> Vencido</span>';
                } else if (diffDays <= 7) {
                    const dia = fechaObj.getDate().toString().padStart(2, '0');
                    const mes = (fechaObj.getMonth() + 1).toString().padStart(2, '0');
                    const anio = fechaObj.getFullYear();
                    return `<span class="badge bg-warning" title="${diffDays} días para vencer"><i class="fas fa-clock"></i> ${dia}/${mes}/${anio}</span>`;
                } else {
                    const dia = fechaObj.getDate().toString().padStart(2, '0');
                    const mes = (fechaObj.getMonth() + 1).toString().padStart(2, '0');
                    const anio = fechaObj.getFullYear();
                    return `<span class="badge bg-light text-dark">${dia}/${mes}/${anio}</span>`;
                }
            }

            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function ucfirst(str) {
                if (!str) return '';
                return str.charAt(0).toUpperCase() + str.slice(1);
            }

            function actualizarTablaProductos(response) {
                const tbody = $('#productsTableBody');
                tbody.empty();

                if (!response.productos || response.productos.length === 0) {
                    tbody.html(`
                <tr>
                    <td colspan="14" class="text-center text-muted py-4">
                        <i class="fas fa-box fa-3x mb-3"></i>
                        <p>No se encontraron productos</p>
                    </td>
                </tr>
            `);
                    return;
                }

                response.productos.forEach(producto => {
                    let imagenesHtml = '';
                    if (producto.imagenes && producto.imagenes.length > 0) {
                        imagenesHtml = `
                    <div id="carouselSmall-${producto.id}" class="carousel slide producto-imagen-carousel" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-inner">
                            ${producto.imagenes.map((img, idx) => `
                                <div class="carousel-item ${idx === 0 ? 'active' : ''}">
                                    <img src="${img.ruta_imagen}" class="d-block w-100" alt="${escapeHtml(producto.nombre)}" onclick="abrirCarruselAmpliado('${producto.id}', ${idx}, event)">
                                </div>
                            `).join('')}
                        </div>
                        ${producto.imagenes.length > 1 ? `
                            <button class="carousel-control-prev" type="button" data-bs-target="#carouselSmall-${producto.id}" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Anterior</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#carouselSmall-${producto.id}" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Siguiente</span>
                            </button>
                            <div class="carousel-indicators">
                                ${producto.imagenes.map((_, idx) => `
                                    <button type="button" data-bs-target="#carouselSmall-${producto.id}" data-bs-slide-to="${idx}" class="${idx === 0 ? 'active' : ''}" aria-label="Slide ${idx + 1}"></button>
                                `).join('')}
                            </div>
                        ` : ''}
                    </div>
                `;
                    } else {
                        imagenesHtml = `
                    <div class="producto-imagen bg-light d-flex align-items-center justify-content-center no-imagen-container" style="width: 60px; height: 60px; cursor: pointer;" onclick="abrirCarruselAmpliado('${producto.id}', 0, event)">
                        <i class="fas fa-image text-muted"></i>
                    </div>
                `;
                    }

                    let unidadBadgeClass = 'unidad-pieza';
                    switch (producto.unidad_medida) {
                        case 'kilo':
                            unidadBadgeClass = 'unidad-kilo';
                            break;
                        case 'litro':
                            unidadBadgeClass = 'unidad-litro';
                            break;
                    }

                    let precioFinal = producto.precio;
                    if (producto.descuento > 0 && producto.subprecio > 0) {
                        precioFinal = producto.subprecio - (producto.subprecio * (producto.descuento / 100));
                    }

                    let stockFormateado = formatearStockJS(producto.stock_total, producto.unidad_medida);
                    let stockBadgeClass = 'bg-success';
                    if (producto.stock_total <= 0) stockBadgeClass = 'bg-danger';
                    else if (producto.stock_total <= response.stock_minimo_global) stockBadgeClass = 'bg-warning';

                    const row = `
                <tr data-categoria="${producto.categoria_id || ''}" data-proveedor="${producto.proveedor_id || ''}" data-activo="${producto.activo}" class="producto-row">
                    <td>${imagenesHtml}</td>
                    <td><strong>${escapeHtml(producto.codigo)}</strong>${producto.tiene_mayoreo ? '<span class="badge mayoreo-badge ms-1" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;"><i class="fas fa-tags"></i> Mayoreo</span>' : ''}</td>
                    <td><div><strong>${escapeHtml(producto.nombre)}</strong>${producto.descripcion ? `<br><small class="text-muted">${escapeHtml(producto.descripcion)}</small>` : ''}</div></td>
                    <td><span class="badge unidad-medida-badge ${unidadBadgeClass}">${ucfirst(producto.unidad_medida || 'pieza')}</span></td>
                    <td>${escapeHtml(producto.marca || 'N/A')}</td>
                    <td>${escapeHtml(producto.categoria_nombre || 'Sin categoría')}</td>
                    <td><span class="badge badge-subprecio">$${parseFloat(producto.subprecio || 0).toFixed(2)}</span></td>
                    <td>${producto.descuento > 0 ? `<span class="badge badge-descuento">-${parseFloat(producto.descuento).toFixed(0)}%</span>` : '<span class="text-muted">0%</span>'}</td>
                    <td><span class="badge badge-precio ${producto.descuento > 0 ? 'text-danger fw-bold' : ''}">$${precioFinal.toFixed(2)}</span></td>
                    <td><span class="badge ${stockBadgeClass} badge-stock">${stockFormateado}</span><br><small class="text-muted">Mín: ${response.stock_minimo_global}</small>${producto.porcentaje_merma_danado > 0 || producto.porcentaje_merma_deshidratacion > 0 ? `<br><small class="text-muted merma-badge">Merma: ${parseFloat(producto.porcentaje_merma_danado) + parseFloat(producto.porcentaje_merma_deshidratacion)}%</small>` : ''}</td>
                    <td>${formatearFechaCaducidadJS(producto.fecha_caducidad)}</td>
                    <td><span class="status-badge ${producto.activo ? 'status-active' : 'status-inactive'}">${producto.activo ? 'Activo' : 'Inactivo'}</span>
                        <button class="btn btn-outline-primary btn-sm edit-producto d-none" data-id="${producto.id}" data-activo="${producto.activo}" data-codigo="${escapeHtml(producto.codigo)}" data-nombre="${escapeHtml(producto.nombre)}" data-descripcion="${escapeHtml(producto.descripcion || '')}" data-marca="${escapeHtml(producto.marca || '')}" data-precio="${precioFinal}" data-subprecio="${producto.subprecio}" data-descuento="${producto.descuento}" data-costo="${producto.costo}" data-categoria_id="${producto.categoria_id || ''}" data-proveedor_id="${producto.proveedor_id || ''}" data-unidad_medida="${producto.unidad_medida}" data-peso_kg="${producto.peso_kg}" data-permite_fracciones="${producto.permite_fracciones}" data-fecha_caducidad="${producto.fecha_caducidad || ''}" data-tipo_producto="${escapeHtml(producto.tipo_producto || 'Estandar')}" data-porcentaje_merma_danado="${producto.porcentaje_merma_danado}" data-porcentaje_merma_deshidratacion="${producto.porcentaje_merma_deshidratacion}" data-aplicar_merma_venta="${producto.aplicar_merma_venta}" data-aplicar_merma_compra="${producto.aplicar_merma_compra}" data-imagenes='${JSON.stringify(producto.imagenes || [])}' data-sucursales='${producto.sucursales_ids || ""}' data-precios-mayoreo='${JSON.stringify(producto.precios_mayoreo || [])}' data-stocks='${JSON.stringify(producto.stocks_por_sucursal || {})}' title="Editar"></button>
                    </td>
                </tr>
            `;
                    tbody.append(row);
                });

                reinicializarEventosProductos();
            }

            function cargarListaProductosStats(tipo, modalId, containerId) {
    $.ajax({
        url: 'ajax_productos_stats.php',
        type: 'GET',
        data: { tipo: tipo },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.productos && response.productos.length > 0) {
                let html = '<ul class="lista-productos-stats">';
                response.productos.forEach(function(producto) {
                    let stockClass = '';
                    let stockText = '';
                    if (producto.stock_total <= 0) {
                        stockClass = 'stock-cero-stats';
                        stockText = 'Sin stock';
                    } else if (producto.stock_total <= response.stock_minimo) {
                        stockClass = 'stock-bajo-stats';
                        stockText = 'Stock bajo: ' + formatearStockStats(producto.stock_total, producto.unidad_medida);
                    } else {
                        stockClass = 'stock-normal-stats';
                        stockText = 'Stock: ' + formatearStockStats(producto.stock_total, producto.unidad_medida);
                    }
                    
                    html += `
                        <li>
                            <div style="flex: 1;">
                                <div class="producto-nombre-stats">${escapeHtml(producto.nombre)}</div>
                                <div class="producto-codigo-stats">${escapeHtml(producto.codigo)}</div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge-unidad-stats">${escapeHtml(producto.unidad_medida || 'pieza')}</span>
                                <span class="producto-stock-stats ${stockClass}">${stockText}</span>
                            </div>
                        </li>
                    `;
                });
                html += '</ul>';
                $(containerId).html(html);
            } else {
                $(containerId).html(`
                    <div class="empty-state-stats">
                        <i class="fas fa-box-open"></i>
                        <p>No hay productos en esta categoría</p>
                    </div>
                `);
            }
        },
        error: function() {
            $(containerId).html(`
                <div class="empty-state-stats">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error al cargar los productos</p>
                </div>
            `);
        }
    });
}

function formatearStockStats(stock, unidad) {
    if (stock === undefined || stock === null) stock = 0;
    let stockFormateado;
    if (stock % 1 !== 0) {
        stockFormateado = parseFloat(stock).toFixed(3).replace(/\.?0+$/, '');
    } else {
        stockFormateado = Math.floor(stock);
    }
    
    let sufijo = '';
    switch (unidad) {
        case 'kg': case 'kilo': case 'kilogramo':
            sufijo = ' kg';
            break;
        case 'litro': case 'l':
            sufijo = ' L';
            break;
        case 'tonelada': case 'ton':
            sufijo = ' ton';
            break;
        case 'pieza':
            sufijo = stock == 1 ? ' pieza' : ' piezas';
            break;
        default:
            sufijo = '';
    }
    return stockFormateado + sufijo;
}

// Eventos click en las tarjetas de estadísticas
$('.stat-card').on('click', function(e) {
    e.stopPropagation();
    const card = $(this);
    const label = card.find('.metric-label').text().trim();
    const value = card.find('.metric-value').text().trim();
    
    switch(label) {
        case 'Total Productos':
            $('#listaTotalProductos').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalTotalProductos').modal('show');
            cargarListaProductosStats('total', '#modalTotalProductos', '#listaTotalProductos');
            break;
        case 'Con Stock':
            $('#listaConStock').html('<div class="text-center py-4"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalConStock').modal('show');
            cargarListaProductosStats('con_stock', '#modalConStock', '#listaConStock');
            break;
        case 'Stock Bajo':
            $('#listaStockBajo').html('<div class="text-center py-4"><div class="spinner-border text-warning" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalStockBajo').modal('show');
            cargarListaProductosStats('bajo_stock', '#modalStockBajo', '#listaStockBajo');
            break;
        case 'Sin Stock':
            $('#listaSinStock').html('<div class="text-center py-4"><div class="spinner-border text-danger" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalSinStock').modal('show');
            cargarListaProductosStats('sin_stock', '#modalSinStock', '#listaSinStock');
            break;
    }
});

// Agregar cursor pointer a las tarjetas de estadísticas
$('.stat-card').css('cursor', 'pointer');

            function actualizarTarjetasMoviles(response) {
                const container = $('#mobileProductsContainer');
                container.empty();

                if (!response.productos || response.productos.length === 0) {
                    container.append(`
                <div class="card text-center text-muted py-4">
                    <i class="fas fa-box fa-3x mb-3"></i>
                    <p>No se encontraron productos</p>
                </div>
            `);
                    return;
                }

                response.productos.forEach(producto => {
                    let unidadBadgeClass = 'unidad-pieza';
                    switch (producto.unidad_medida) {
                        case 'kilo':
                            unidadBadgeClass = 'unidad-kilo';
                            break;
                        case 'litro':
                            unidadBadgeClass = 'unidad-litro';
                            break;
                    }

                    let precioFinal = producto.precio;
                    if (producto.descuento > 0 && producto.subprecio > 0) {
                        precioFinal = producto.subprecio - (producto.subprecio * (producto.descuento / 100));
                    }

                    let stockFormateado = formatearStockJS(producto.stock_total, producto.unidad_medida);
                    let stockBadgeClass = 'bg-success';
                    if (producto.stock_total <= 0) stockBadgeClass = 'bg-danger';
                    else if (producto.stock_total <= response.stock_minimo_global) stockBadgeClass = 'bg-warning';

                    let imagenesHtml = '';
                    if (producto.imagenes && producto.imagenes.length > 0) {
                        imagenesHtml = `
                    <div id="carouselMobile-${producto.id}" class="carousel slide producto-imagen-carousel me-2" style="width: 80px;" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-inner">
                            ${producto.imagenes.map((img, idx) => `
                                <div class="carousel-item ${idx === 0 ? 'active' : ''}">
                                    <img src="${img.ruta_imagen}" class="d-block w-100" alt="${escapeHtml(producto.nombre)}" onclick="abrirCarruselAmpliado('${producto.id}', ${idx}, event)">
                                </div>
                            `).join('')}
                        </div>
                        ${producto.imagenes.length > 1 ? `
                            <button class="carousel-control-prev" type="button" data-bs-target="#carouselMobile-${producto.id}" data-bs-slide="prev" style="width: 15px;">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Anterior</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#carouselMobile-${producto.id}" data-bs-slide="next" style="width: 15px;">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Siguiente</span>
                            </button>
                        ` : ''}
                    </div>
                `;
                    } else {
                        imagenesHtml = `
                    <div class="producto-imagen-mobile bg-light d-flex align-items-center justify-content-center me-2 no-imagen-container" style="width: 70px; height: 70px; cursor: pointer;" onclick="abrirCarruselAmpliado('${producto.id}', 0, event)">
                        <i class="fas fa-image text-muted"></i>
                    </div>
                `;
                    }

                    const card = `
                <div class="producto-card-mobile" data-categoria="${producto.categoria_id || ''}" data-proveedor="${producto.proveedor_id || ''}" data-activo="${producto.activo}">
                    <div class="producto-card-header">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-center">
                                ${imagenesHtml}
                                <div>
                                    <h6 class="mb-0 text-white">${escapeHtml(producto.nombre)}</h6>
                                    <div class="d-flex align-items-center mt-1">
                                        <span class="badge bg-light text-dark me-2">${escapeHtml(producto.codigo)}</span>
                                        ${producto.tiene_mayoreo ? '<span class="badge mayoreo-badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;"><i class="fas fa-tags"></i> Mayoreo</span>' : ''}
                                        ${producto.tipo_producto ? `<span class="badge tipo-producto-badge ms-1" style="font-size: 0.65rem;"><i class="fas fa-tag"></i> ${escapeHtml(producto.tipo_producto)}</span>` : ''}
                                        <button class="btn btn-outline-light btn-sm edit-producto-mobile d-none" data-id="${producto.id}" data-activo="${producto.activo}" data-codigo="${escapeHtml(producto.codigo)}" data-nombre="${escapeHtml(producto.nombre)}" data-descripcion="${escapeHtml(producto.descripcion || '')}" data-marca="${escapeHtml(producto.marca || '')}" data-precio="${precioFinal}" data-subprecio="${producto.subprecio}" data-descuento="${producto.descuento}" data-costo="${producto.costo}" data-categoria_id="${producto.categoria_id || ''}" data-proveedor_id="${producto.proveedor_id || ''}" data-unidad_medida="${producto.unidad_medida}" data-peso_kg="${producto.peso_kg}" data-permite_fracciones="${producto.permite_fracciones}" data-fecha_caducidad="${producto.fecha_caducidad || ''}" data-tipo_producto="${escapeHtml(producto.tipo_producto || 'Estandar')}" data-porcentaje_merma_danado="${producto.porcentaje_merma_danado}" data-porcentaje_merma_deshidratacion="${producto.porcentaje_merma_deshidratacion}" data-aplicar_merma_venta="${producto.aplicar_merma_venta}" data-aplicar_merma_compra="${producto.aplicar_merma_compra}" data-imagenes='${JSON.stringify(producto.imagenes || [])}' data-sucursales='${producto.sucursales_ids || ""}' data-precios-mayoreo='${JSON.stringify(producto.precios_mayoreo || [])}' data-stocks='${JSON.stringify(producto.stocks_por_sucursal || {})}'>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="producto-card-body">
                        <div class="producto-info-row"><span class="producto-info-label">Unidad Medida:</span><span class="producto-info-value"><span class="badge unidad-medida-badge ${unidadBadgeClass}">${ucfirst(producto.unidad_medida || 'pieza')}</span></span></div>
                        ${producto.tipo_producto ? `<div class="producto-info-row"><span class="producto-info-label">Tipo:</span><span class="producto-info-value"><span class="badge tipo-producto-badge">${escapeHtml(producto.tipo_producto)}</span></span></div>` : ''}
                        ${(producto.porcentaje_merma_danado > 0 || producto.porcentaje_merma_deshidratacion > 0) ? `<div class="producto-info-row"><span class="producto-info-label">Merma:</span><span class="producto-info-value"><span class="badge merma-badge"><i class="fas fa-charging-station me-1"></i> D: ${producto.porcentaje_merma_danado}% / Des: ${producto.porcentaje_merma_deshidratacion}%</span></span></div>` : ''}
                        <div class="producto-info-row"><span class="producto-info-label">Marca:</span><span class="producto-info-value">${escapeHtml(producto.marca || 'N/A')}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Categoría:</span><span class="producto-info-value">${escapeHtml(producto.categoria_nombre || 'Sin categoría')}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Subprecio:</span><span class="producto-info-value text-dark">$${parseFloat(producto.subprecio || 0).toFixed(2)}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Descuento:</span><span class="producto-info-value">${producto.descuento > 0 ? `<span class="badge bg-danger">-${parseFloat(producto.descuento).toFixed(0)}%</span>` : '<span class="text-muted">0%</span>'}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Precio Final:</span><span class="producto-info-value text-success fw-bold">$${precioFinal.toFixed(2)}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Stock Total:</span><span class="producto-info-value"><span class="badge ${stockBadgeClass}">${stockFormateado}</span> <small class="text-muted ms-2">Mín: ${response.stock_minimo_global}</small></span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Fecha Caducidad:</span><span class="producto-info-value">${formatearFechaCaducidadJS(producto.fecha_caducidad)}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Estado:</span><span class="producto-info-value"><span class="status-badge ${producto.activo ? 'status-active' : 'status-inactive'}">${producto.activo ? 'Activo' : 'Inactivo'}</span></span></div>
                        ${producto.descripcion ? `<div class="producto-info-row"><span class="producto-info-label">Descripción:</span><span class="producto-info-value"><small>${escapeHtml(producto.descripcion)}</small></span></div>` : ''}
                    </div>
                </div>
            `;
                    container.append(card);
                });

                reinicializarEventosProductos();
            }

            // =============================================
// SISTEMA DE TOOLTIPS INFORMATIVOS
// =============================================

// Configuración de los tooltips para cada campo
const tooltipsConfig = {
    'costo': {
        titulo: 'Costo del producto',
        descripcion: 'Precio de compra del producto. Este valor sirve como base para calcular la utilidad.'
    },
    'utilidad': {
        titulo: 'Utilidad (%)',
        descripcion: 'Porcentaje de ganancia sobre el costo. El precio de venta se calculará automáticamente: Precio = Costo × (1 + Utilidad/100)'
    },
    'descuento': {
        titulo: 'Descuento (%)',
        descripcion: 'Descuento directo sobre el precio de venta. Si aplicas descuento, el precio final se recalculará automáticamente.'
    },
    'precio_venta_base': {
        titulo: 'Precio base',
        descripcion: 'Precio original del producto sin descuentos aplicados. Este es el precio de referencia.'
    },
    'precio_venta_final': {
        titulo: 'Precio final',
        descripcion: 'Precio después de aplicar el descuento. Este es el precio que pagará el cliente.'
    },
    'codigo': {
        titulo: 'Código de producto',
        descripcion: 'Identificador único del producto. Puedes usar el botón "Auto" para generarlo automáticamente o escribir uno personalizado.'
    },
    'nombre': {
        titulo: 'Nombre del producto',
        descripcion: 'Nombre descriptivo del producto que aparecerá en ventas y facturas.'
    },
    'marca': {
        titulo: 'Marca',
        descripcion: 'Marca o fabricante del producto. Campo opcional.'
    },
    'descripcion': {
        titulo: 'Descripción',
        descripcion: 'Información adicional sobre el producto (características, especificaciones, etc.). Opcional.'
    },
    'categoria': {
        titulo: 'Categoría',
        descripcion: 'Agrupa productos similares para facilitar la organización y búsqueda.'
    },
    'proveedor': {
        titulo: 'Proveedor',
        descripcion: 'Empresa o persona que suministra el producto. Útil para gestión de compras.'
    },
    'unidad_medida': {
        titulo: 'Unidad de medida',
        descripcion: 'Cómo se mide el producto: piezas (unidades completas), kilos (peso) o litros (volumen).'
    },
    'peso': {
        titulo: 'Peso/Volumen',
        descripcion: 'Para kilos: peso en kg por unidad. Para litros: volumen en L por unidad.'
    },
    'fracciones': {
        titulo: 'Ventas por fracciones',
        descripcion: 'Permite vender cantidades fraccionadas (ej: 0.5 kg, 1.75 L). Para piezas normalmente se venden completas.'
    },
    'fecha_caducidad': {
        titulo: '⏰ Fecha de caducidad',
        descripcion: 'Fecha límite de consumo/venta. El sistema alertará cuando el producto esté próximo a vencer.'
    },
    'tipo_producto': {
        titulo: 'Tipo/Calidad',
        descripcion: 'Clasificación adicional del producto por calidad, tamaño o categoría especial.'
    },
    'merma_danado': {
        titulo: 'Merma por daño',
        descripcion: 'Porcentaje de producto que se estima se dañará durante el almacenamiento o manejo.'
    },
    'merma_deshidratacion': {
        titulo: 'Merma por deshidratación',
        descripcion: 'Para productos perecederos que pierden peso/volumen con el tiempo (frutas, verduras, carnes).'
    },
    'aplicar_merma_venta': {
        titulo: 'Aplicar merma en venta',
        descripcion: 'Al vender, se descuenta automáticamente el porcentaje de merma del inventario disponible.'
    },
    'aplicar_merma_compra': {
        titulo: 'Aplicar merma en compra',
        descripcion: 'Al comprar mercancía, se aplica el descuento por merma automáticamente al inventario.'
    },
    'mayoreo': {
        titulo: 'Precios de Mayoreo',
        descripcion: 'Define precios especiales según la cantidad de compra. Ej: 10 piezas a $100 c/u, 50 piezas a $90 c/u.'
    },
    'stock': {
        titulo: 'Stock por sucursal',
        descripcion: 'Cantidad de producto disponible en cada sucursal. Según la unidad de medida, puedes usar decimales (kilos/litros) o enteros (piezas).'
    },
    'transferencia': {
        titulo: 'Transferencia entre sucursales',
        descripcion: 'Mueve stock de una sucursal a otra. El sistema actualizará automáticamente los inventarios y registrará el movimiento.'
    }
};

// Función para mostrar tooltip
function showTooltip(content, targetElement, event) {
    // Eliminar tooltip existente
    $('.custom-tooltip').remove();
    
    // Crear nuevo tooltip
    const tooltip = $('<div class="custom-tooltip">' + content + '</div>');
    $('body').append(tooltip);
    
    // Posicionar el tooltip
    const targetRect = targetElement.getBoundingClientRect();
    const tooltipHeight = tooltip.outerHeight();
    const tooltipWidth = tooltip.outerWidth();
    
    let top = targetRect.bottom + window.scrollY + 8;
    let left = targetRect.left + window.scrollX + (targetRect.width / 2) - (tooltipWidth / 2);
    
    // Ajustar si se sale de la pantalla
    if (left + tooltipWidth > window.innerWidth) {
        left = window.innerWidth - tooltipWidth - 10;
    }
    if (left < 10) {
        left = 10;
    }
    
    // Si no hay espacio abajo, mostrar arriba
    if (top + tooltipHeight > window.innerHeight + window.scrollY) {
        top = targetRect.top + window.scrollY - tooltipHeight - 8;
        tooltip.addClass('bottom');
    }
    
    tooltip.css({
        top: top,
        left: left
    }).addClass('show');
    
    // Auto-cerrar después de 4 segundos
    setTimeout(() => {
        tooltip.fadeOut(300, function() {
            $(this).remove();
        });
    }, 4000);
}

// Función para agregar tooltips a los labels
function agregarTooltipALabel(labelId, configKey, textoLabel) {
    const labelElement = $(labelId);
    if (labelElement.length && tooltipsConfig[configKey]) {
        const config = tooltipsConfig[configKey];
        
        // Cambiar estructura del label para incluir el ícono
        labelElement.html(`
            <span class="label-with-tooltip">
                ${textoLabel}
                <span class="info-tooltip-icon" data-tooltip-key="${configKey}">
                    <i class="fas fa-question"></i>
                </span>
            </span>
        `);
    }
}

// Inicializar todos los tooltips
function inicializarTooltips() {
    // Mapeo de selectores a configuraciones
    const tooltipMappings = [
        { selector: 'label[for="costo"], label:contains("Costo")', key: 'costo', texto: 'Costo' },
        { selector: 'label:contains("Utilidad")', key: 'utilidad', texto: 'Utilidad (%)' },
        { selector: 'label:contains("Descuento")', key: 'descuento', texto: 'Descuento (%)' },
        { selector: 'label:contains("Precio Venta (Base)")', key: 'precio_venta_base', texto: 'Precio Venta (Base) *' },
        { selector: 'label:contains("Precio Venta (Final)")', key: 'precio_venta_final', texto: 'Precio Venta (Final) *' },
        { selector: 'label[for="codigo"], label:contains("Código")', key: 'codigo', texto: 'Código *' },
        { selector: 'label[for="nombre"], label:contains("Nombre")', key: 'nombre', texto: 'Nombre *' },
        { selector: 'label[for="marca"], label:contains("Marca")', key: 'marca', texto: 'Marca' },
        { selector: 'label[for="descripcion"], label:contains("Descripción")', key: 'descripcion', texto: 'Descripción' },
        { selector: 'label[for="categoria_id"], label:contains("Categoría")', key: 'categoria', texto: 'Categoría' },
        { selector: 'label[for="proveedor_id"], label:contains("Proveedor")', key: 'proveedor', texto: 'Proveedor' },
        { selector: 'label[for="unidad_medida"], label:contains("Unidad de Medida")', key: 'unidad_medida', texto: 'Unidad de Medida *' },
        { selector: 'label[for="peso_kg"], label:contains("Peso")', key: 'peso', texto: 'Peso por Unidad (kg)' },
        { selector: 'label[for="permite_fracciones"], label:contains("Permitir venta por fracciones")', key: 'fracciones', texto: 'Permitir venta por fracciones' },
        { selector: 'label[for="fecha_caducidad"], label:contains("Fecha de Caducidad")', key: 'fecha_caducidad', texto: 'Fecha de Caducidad' },
        { selector: 'label[for="tipo_producto"], label:contains("Clasificación / Calidad")', key: 'tipo_producto', texto: 'Clasificación / Calidad' },
        { selector: 'label:contains("Merma por Daño")', key: 'merma_danado', texto: 'Merma por Daño (%)' },
        { selector: 'label:contains("Merma por Deshidratación")', key: 'merma_deshidratacion', texto: 'Merma por Deshidratación / Desgaste (%)' },
        { selector: 'label[for="aplicar_merma_venta"], label:contains("Aplicar merma al calcular existencias en venta")', key: 'aplicar_merma_venta', texto: 'Aplicar merma al calcular existencias en venta' },
        { selector: 'label[for="aplicar_merma_compra"], label:contains("Aplicar merma al recibir mercancía")', key: 'aplicar_merma_compra', texto: 'Aplicar merma al recibir mercancía' },
        { selector: '.mayoreo-header .form-check-label', key: 'mayoreo', texto: 'Habilitar precios por cantidad' },
        { selector: '.sucursal-stock-header', key: 'stock', texto: 'Sucursales y Stock' }
    ];
    
    // Aplicar tooltips
    tooltipMappings.forEach(mapping => {
        $(mapping.selector).each(function() {
            if (!$(this).find('.info-tooltip-icon').length) {
                agregarTooltipALabel($(this), mapping.key, mapping.texto);
            }
        });
    });
    
    // Evento para mostrar tooltip al hacer clic o hover
    $(document).off('click mouseenter', '.info-tooltip-icon').on('click mouseenter', '.info-tooltip-icon', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const key = $(this).data('tooltip-key');
        const config = tooltipsConfig[key];
        
        if (config) {
            const content = `<i class="fas fa-info-circle"></i><strong>${config.titulo}</strong><br><small>${config.descripcion}</small>`;
            showTooltip(content, this, e);
        }
    });
    
    // También agregar tooltip al header de transferencia de stock
    if ($('#seccionTransferenciaStock').length) {
        const transferHeader = $('#seccionTransferenciaStock h6');
        if (transferHeader.length && !transferHeader.find('.info-tooltip-icon').length) {
            transferHeader.html(`
                <i class="fas fa-exchange-alt me-2"></i>
                Transferir Stock entre Sucursales
                <span class="info-tooltip-icon" data-tooltip-key="transferencia" style="margin-left: 8px;">
                    <i class="fas fa-question"></i>
                </span>
            `);
        }
    }
}

// Llamar a inicialización después de que el modal se muestre
$(document).on('shown.bs.modal', '#productoModal', function() {
    inicializarTooltips();
});

// También inicializar cuando se carga la página por si el modal está visible
setTimeout(inicializarTooltips, 100);

            function actualizarPaginacion(response) {
                const paginaActual = response.pagina_actual;
                const totalPaginas = response.total_paginas;
                const totalRegistros = response.total_registros;
                const productosMostrados = response.productos ? response.productos.length : 0;

                filtrosActuales.pagina = paginaActual;

                if (totalPaginas > 1) {
                    let paginacionHtml = `
                <div class="pagination-container" id="desktopPagination">
                    <div class="pagination-info">Mostrando ${productosMostrados} de ${totalRegistros} productos</div>
                    <nav><ul class="pagination mb-0">
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="1"><i class="fas fa-angle-double-left"></i></a></li>
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="${paginaActual - 1}"><i class="fas fa-angle-left"></i></a></li>`;

                    let inicio = Math.max(1, paginaActual - 2);
                    let fin = Math.min(totalPaginas, paginaActual + 2);
                    for (let i = inicio; i <= fin; i++) {
                        paginacionHtml += `<li class="page-item ${i == paginaActual ? 'active' : ''}"><a class="page-link" href="#" data-pagina="${i}">${i}</a></li>`;
                    }

                    paginacionHtml += `
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="${paginaActual + 1}"><i class="fas fa-angle-right"></i></a></li>
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="${totalPaginas}"><i class="fas fa-angle-double-right"></i></a></li>
                    </ul></nav>
                </div>
            `;

                    if ($('#desktopPagination').length) $('#desktopPagination').replaceWith(paginacionHtml);
                    else $('.producto-grid .card-body').append(paginacionHtml);

                    $('.pagination .page-link[data-pagina]').off('click').on('click', function(e) {
                        e.preventDefault();
                        const pagina = $(this).data('pagina');
                        if (pagina && pagina !== filtrosActuales.pagina) {
                            filtrosActuales.pagina = pagina;
                            cargarProductosConFiltros();
                        }
                    });
                } else {
                    $('#desktopPagination').remove();
                }

                if (totalPaginas > 1) {
                    let paginacionMobileHtml = `
                <div class="pagination-container" id="mobilePagination">
                    <div class="pagination-info">${productosMostrados} de ${totalRegistros} productos</div>
                    <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="1"><i class="fas fa-angle-double-left"></i></a></li>
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="${paginaActual - 1}"><i class="fas fa-angle-left"></i></a></li>
                        <li class="page-item disabled"><span class="page-link text-dark"><strong>${paginaActual}</strong> / ${totalPaginas}</span></li>
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="${paginaActual + 1}"><i class="fas fa-angle-right"></i></a></li>
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="${totalPaginas}"><i class="fas fa-angle-double-right"></i></a></li>
                    </ul></nav>
                </div>
            `;

                    if ($('#mobilePagination').length) $('#mobilePagination').replaceWith(paginacionMobileHtml);
                    else $('#mobileProductsContainer').append(paginacionMobileHtml);

                    $('.pagination .page-link[data-pagina-mobile]').off('click').on('click', function(e) {
                        e.preventDefault();
                        const pagina = $(this).data('pagina-mobile');
                        if (pagina && pagina !== filtrosActuales.pagina) {
                            filtrosActuales.pagina = pagina;
                            cargarProductosConFiltros();
                        }
                    });
                } else {
                    $('#mobilePagination').remove();
                }

                const texto = `Mostrando ${productosMostrados} de ${totalRegistros} productos`;
                $('#resultCount, #resultCountDesktop, #resultCountMobile').text(texto);

                if (totalPaginas > 1) {
                    $('.producto-grid .card-header .badge').text(`Página ${paginaActual} de ${totalPaginas}`);
                    $('.producto-cards .d-flex .badge').text(`Pág. ${paginaActual}/${totalPaginas}`);
                }
            }

            function actualizarEstadisticas(response) {
                if (response.estadisticas) {
                    $('.stat-card:eq(0) .metric-value').text(response.estadisticas.total_productos || 0);
                    $('.stat-card:eq(1) .metric-value').text(response.estadisticas.con_stock || 0);
                    $('.stat-card:eq(2) .metric-value').text(response.estadisticas.bajo_stock || 0);
                    $('.stat-card:eq(3) .metric-value').text(response.estadisticas.sin_stock || 0);
                }
            }

            function eliminarProducto(id, nombre) {
                if (!id || id <= 0) {
                    mostrarMensajeTemporal('ID de producto inválido', 'danger');
                    return;
                }

                // PRIMERA confirmación
                if (!confirm(`¿Estás SEGURO de que deseas eliminar el producto "${nombre}"?\n\nEsto verificará si tiene dependencias (ventas, compras, movimientos).`)) {
                    return;
                }

                // Mostrar indicador de carga
                const btnEliminar = $('#btnEliminarProducto');
                const originalHtml = btnEliminar.html();
                btnEliminar.html('<i class="fas fa-spinner fa-spin me-2"></i>Verificando...').prop('disabled', true);

                // Verificar dependencias
                $.ajax({
                    url: 'verificar_dependencias_producto.php',
                    type: 'POST',
                    data: {
                        id: id
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            if (response.tiene_dependencias) {
                                // Mostrar mensaje detallado de dependencias
                                let mensaje = `No se puede eliminar el producto "${nombre}" porque tiene registros asociados:\n\n`;
                                if (response.ventas > 0) mensaje += `Ventas: ${response.ventas} registros\n`;
                                if (response.compras > 0) mensaje += `Compras: ${response.compras} registros\n`;
                                if (response.movimientos > 0) mensaje += `Movimientos de inventario: ${response.movimientos} registros\n`;
                                mensaje += `\nSugerencia: Desactive el producto en lugar de eliminarlo.`;
                                alert(mensaje);
                                btnEliminar.html(originalHtml).prop('disabled', false);
                            } else {
                                // SEGUNDA confirmación (final)
                                const confirmacionFinal = confirm(`CONFIRMACIÓN FINAL \n\n¿ELIMINAR PERMANENTEMENTE el producto "${nombre}"?\n\nEsta acción ELIMINARÁ:\n• Las imágenes del producto\n• Los precios de mayoreo\n• La relación con sucursales\n• El producto en sí\n\nEsta acción es IRREVERSIBLE. `);

                                if (confirmacionFinal) {
                                    ejecutarEliminacionProducto(id, nombre);
                                } else {
                                    btnEliminar.html(originalHtml).prop('disabled', false);
                                }
                            }
                        } else {
                            mostrarMensajeTemporal(response.message || 'Error al verificar dependencias', 'danger');
                            btnEliminar.html(originalHtml).prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al verificar dependencias:', error);
                        mostrarMensajeTemporal('Error de conexión al verificar dependencias', 'danger');
                        btnEliminar.html(originalHtml).prop('disabled', false);
                    }
                });
            }



            function ejecutarEliminacionProducto(id, nombre) {
                const btnEliminar = $('#btnEliminarProducto');
                const originalHtml = btnEliminar.html();
                btnEliminar.html('<i class="fas fa-spinner fa-spin me-2"></i>Eliminando...').prop('disabled', true);

                $.ajax({
                    url: 'eliminar_producto.php',
                    type: 'POST',
                    data: {
                        id: id,
                        confirmacion: 'true'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            mostrarMensajeTemporal(`Producto "${nombre}" eliminado exitosamente`, 'success');
                            $('#productoModal').modal('hide');
                            // Recargar la página después de 1 segundo
                            setTimeout(() => {
                                window.location.href = window.location.pathname + '?recargado=' + Date.now();
                            }, 1000);
                        } else {
                            mostrarMensajeTemporal(response.message || 'Error al eliminar producto', 'danger');
                            btnEliminar.html(originalHtml).prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al eliminar producto:', error);
                        let errorMsg = 'Error de conexión al eliminar producto';
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.message) errorMsg = response.message;
                        } catch (e) {}
                        mostrarMensajeTemporal(errorMsg, 'danger');
                        btnEliminar.html(originalHtml).prop('disabled', false);
                    }
                });
            }

            // Mostrar/ocultar botones según sea edición o nuevo producto
            $(document).on('shown.bs.modal', '#productoModal', function() {
                const formAction = $('#formAction').val();
                if (formAction === 'editar') {
                    const productoId = $('#productoId').val();
                    const productoNombre = $('#nombre').val() || 'este producto';

                    // Sección de transferencia (solo edición, mínimo 2 sucursales)
                    <?php if (count($sucursales) >= 2): ?>
                        $('#seccionTransferenciaStock').show();
                    <?php endif; ?>

                    // Botón Eliminar
                    $('#btnEliminarProducto').show();
                    $('#btnEliminarProducto').off('click').on('click', function(e) {
                        e.preventDefault();
                        eliminarProducto(productoId, productoNombre);
                    });

                    // Botón Clonar
                    $('#btnClonarProductoModal').show();
                    $('#btnClonarProductoModal').off('click').on('click', function() {
                        $('#productoModal').modal('hide');
                        // Recolectar datos actuales del producto para clonar
                        const productoData = {
                            id: productoId,
                            nombre: productoNombre,
                            descripcion: $('#descripcion').val() || '',
                            marca: $('#marca').val() || '',
                            precio: $('#precio_hidden').val() || $('#precio_desktop').val() || '',
                            subprecio: $('#subprecio_hidden').val() || $('#subprecio_desktop').val() || '',
                            descuento: $('#descuento_hidden').val() || $('#descuento_desktop').val() || '0',
                            costo: $('#costo_hidden').val() || $('#costo_desktop').val() || '',
                            categoria_id: $('#categoria_id').val() || '',
                            proveedor_id: $('#proveedor_id').val() || '',
                            unidad_medida: $('#unidad_medida').val() || 'pieza',
                            peso_kg: $('#peso_kg').val() || '1.000',
                            permite_fracciones: $('#permite_fracciones').is(':checked') ? 1 : 0,
                            fecha_caducidad: $('#fecha_caducidad').val() || '',
                            tipo_producto: $('#tipo_producto').val() || 'Estandar',
                            porcentaje_merma_danado: $('#porcentaje_merma_danado').val() || 0,
                            porcentaje_merma_deshidratacion: $('#porcentaje_merma_deshidratacion').val() || 0,
                            aplicar_merma_venta: $('#aplicar_merma_venta').is(':checked') ? 1 : 0,
                            aplicar_merma_compra: $('#aplicar_merma_compra').is(':checked') ? 1 : 0,
                            imagenes: JSON.parse($('#imagenes_existentes').val() || '[]'),
                            precios_mayoreo: reglasMayoreo || [],
                            sucursales: [],
                            stocks: {}
                        };
                        setTimeout(() => clonarProducto(productoData), 400);
                    });

                    // Botón Activar/Desactivar
                    $('#btnToggleEstadoModal').show();
                    // Leer estado actual desde hidden input
                    const productoActivo = parseInt($('#productoActivo').val()) || 0;
                    if (productoActivo) {
                        $('#btnToggleEstadoModal').removeClass('btn-outline-success').addClass('btn-outline-warning');
                        $('#btnToggleEstadoModal').find('i').removeClass('fa-toggle-off').addClass('fa-toggle-on');
                        $('#btnToggleEstadoTexto').text('Desactivar');
                    } else {
                        $('#btnToggleEstadoModal').removeClass('btn-outline-warning').addClass('btn-outline-success');
                        $('#btnToggleEstadoModal').find('i').removeClass('fa-toggle-on').addClass('fa-toggle-off');
                        $('#btnToggleEstadoTexto').text('Activar');
                    }
                    $('#btnToggleEstadoModal').off('click').on('click', function() {
                        const estadoActual = parseInt($('#productoActivo').val()) || 0;
                        const nuevoActivo = estadoActual ? 0 : 1;
                        const texto = nuevoActivo == 1 ? 'activar' : 'desactivar';
                        if (confirm(`¿Estás seguro de ${texto} el producto "${productoNombre}"?`)) {
                            $('#productoModal').modal('hide');
                            $.ajax({
                                url: 'productos.php',
                                type: 'POST',
                                data: {
                                    accion: 'cambiar_estado',
                                    id: productoId,
                                    activo: nuevoActivo
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        mostrarMensajeTemporal(response.message || `Producto ${texto === 'activar' ? 'activado' : 'desactivado'} correctamente`, 'success');
                                        cargarProductosConFiltros();
                                    } else {
                                        mostrarMensajeTemporal(response.message || 'Error al cambiar estado', 'danger');
                                    }
                                },
                                error: function() {
                                    mostrarMensajeTemporal('Error de conexión al cambiar estado', 'danger');
                                }
                            });
                        }
                    });
                } else {
                    $('#btnEliminarProducto').hide();
                    $('#btnClonarProductoModal').hide();
                    $('#btnToggleEstadoModal').hide();
                    $('#seccionTransferenciaStock').hide();
                    $('#trans_resultado').hide().empty();
                    $('#trans_sucursal_origen, #trans_sucursal_destino').val('');
                    $('#trans_cantidad').val('');
                    $('#trans_observaciones').val('');
                }
            });

            function cargarProductosConFiltros() {
                if (cargandoProductos) return;

                const filtros = {
                    search: filtrosActuales.search,
                    categoria: filtrosActuales.categoria,
                    proveedor: filtrosActuales.proveedor,
                    sucursal: filtrosActuales.sucursal,
                    show_inactive: filtrosActuales.show_inactive ? '1' : '0',
                    pagina: filtrosActuales.pagina
                };

                mostrarCargando(true);

                $.ajax({
                    url: 'ajax_productos.php',
                    type: 'GET',
                    data: filtros,
                    dataType: 'json',
                    timeout: 30000,
                    success: function(response) {
                        if (response.success) {
                            actualizarTablaProductos(response);
                            actualizarTarjetasMoviles(response);
                            actualizarPaginacion(response);
                            actualizarEstadisticas(response);

                            const params = new URLSearchParams();
                            if (filtros.search) params.set('search', filtros.search);
                            if (filtros.categoria) params.set('categoria', filtros.categoria);
                            if (filtros.proveedor) params.set('proveedor', filtros.proveedor);
                            if (filtros.sucursal) params.set('sucursal', filtros.sucursal);
                            if (filtros.show_inactive === '1') params.set('show_inactive', '1');
                            if (filtros.pagina > 1) params.set('pagina', filtros.pagina);
                            window.history.pushState({}, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
                        } else {
                            mostrarMensajeTemporal(response.message || 'Error al cargar productos', 'danger');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error AJAX:', error);
                        mostrarMensajeTemporal('Error al cargar los productos. Por favor, intenta de nuevo.', 'danger');
                    },
                    complete: function() {
                        mostrarCargando(false);
                    }
                });
            }

            function aplicarFiltrosYRecargar(resetPagina = true) {
                const nuevosFiltros = {
                    search: $('#searchInput').val(),
                    categoria: $('#filterCategoria').val(),
                    proveedor: $('#filterProveedor').val(),
                    sucursal: $('#filterSucursal').val(),
                    show_inactive: $('#showInactive').is(':checked'),
                    pagina: resetPagina ? 1 : filtrosActuales.pagina
                };

                filtrosActuales = nuevosFiltros;
                sincronizarFiltrosMoviles();
                cargarProductosConFiltros();
            }

            // =============================================
            // FUNCIONES PARA PRECIOS DE MAYOREO
            // =============================================

            function agregarReglaMayoreo(cantidad = '', precio = '') {
                reglasMayoreo.push({
                    cantidad: parseFloat(cantidad) || 0,
                    precio: parseFloat(precio) || 0
                });
                renderizarReglasMayoreo();
            }

            function eliminarReglaMayoreo(index) {
                reglasMayoreo.splice(index, 1);
                renderizarReglasMayoreo();
            }

            function renderizarReglasMayoreo() {
                const container = $('#reglasMayoreoContainer');
                container.empty();
                if (reglasMayoreo.length === 0) {
                    container.html(`<div class="text-center text-muted py-3"><i class="fas fa-chart-line fa-2x mb-2"></i><p>No hay reglas de mayoreo configuradas</p><small>Agrega reglas para precios especiales por cantidad</small></div>`);
                    return;
                }
                const reglasOrdenadas = [...reglasMayoreo].sort((a, b) => a.cantidad - b.cantidad);
                reglasOrdenadas.forEach((regla, idx) => {
                    const indexOriginal = reglasMayoreo.findIndex(r => r.cantidad === regla.cantidad && r.precio === regla.precio);
                    const unidad = $('#unidad_medida').val() || 'pieza';
                    const unidadTexto = unidad === 'pieza' ? 'piezas' : unidad;
                    const reglaHtml = `<div class="regla-mayoreo-item"><div class="regla-mayoreo-inputs"><div class="flex-grow-1"><label class="form-label small">Cantidad mínima (${unidadTexto})</label><input type="number" class="form-control form-control-sm" value="${regla.cantidad}" step="any" min="0.001" data-index="${indexOriginal}" data-campo="cantidad" onchange="actualizarReglaMayoreoDesdeInput(this)"></div><div class="flex-grow-1"><label class="form-label small">Precio especial ($)</label><input type="number" class="form-control form-control-sm" value="${regla.precio}" step="0.01" min="0" data-index="${indexOriginal}" data-campo="precio" onchange="actualizarReglaMayoreoDesdeInput(this)"></div><button type="button" class="btn-eliminar-regla" onclick="eliminarReglaMayoreoDesdeJS(${indexOriginal})"><i class="fas fa-trash-alt"></i></button></div><small class="text-muted">Aplica para compras de ${regla.cantidad} o más ${unidadTexto}</small></div>`;
                    container.append(reglaHtml);
                });
                actualizarCampoMayoreoOculto();
            }

            window.actualizarReglaMayoreoDesdeInput = function(input) {
                const index = $(input).data('index');
                const campo = $(input).data('campo');
                const valor = $(input).val();
                if (reglasMayoreo[index]) {
                    reglasMayoreo[index][campo] = parseFloat(valor) || 0;
                    renderizarReglasMayoreo();
                }
            };

            window.eliminarReglaMayoreoDesdeJS = function(index) {
                reglasMayoreo.splice(index, 1);
                renderizarReglasMayoreo();
            };

            function actualizarCampoMayoreoOculto() {
                const reglasValidas = reglasMayoreo.filter(r => r.cantidad > 0 && r.precio > 0);
                $('#precios_mayoreo').val(JSON.stringify(reglasValidas));
            }

            function validarReglasMayoreo() {
                if (!mayoreoHabilitado) return true;
                if (reglasMayoreo.length === 0) {
                    alert('Debes agregar al menos una regla de mayoreo o deshabilitar la opción');
                    return false;
                }
                for (const regla of reglasMayoreo) {
                    if (regla.cantidad <= 0 || regla.precio <= 0) {
                        alert('Todas las reglas deben tener cantidad y precio válidos (mayores a 0)');
                        return false;
                    }
                    const precioNormal = parseFloat($('#precio_hidden').val()) || 0;
                    if (regla.precio >= precioNormal && precioNormal > 0) {
                        alert(`El precio especial ($${regla.precio}) debe ser menor al precio normal ($${precioNormal})`);
                        return false;
                    }
                }
                const cantidades = reglasMayoreo.map(r => r.cantidad);
                const duplicados = cantidades.filter((c, i) => cantidades.indexOf(c) !== i);
                if (duplicados.length > 0) {
                    alert(`No puedes tener dos reglas con la misma cantidad mínima (${duplicados[0]})`);
                    return false;
                }
                return true;
            }

            function cargarReglasMayoreo(preciosMayoreo) {
                if (preciosMayoreo && Array.isArray(preciosMayoreo) && preciosMayoreo.length > 0) {
                    mayoreoHabilitado = true;
                    reglasMayoreo = preciosMayoreo.map(p => ({
                        cantidad: parseFloat(p.cantidad_minima) || 0,
                        precio: parseFloat(p.precio_especial) || 0
                    }));
                    $('#habilitarMayoreo').prop('checked', true);
                    $('#mayoreoSection').show();
                    $('#btnAgregarReglaMayoreo').show();
                } else {
                    mayoreoHabilitado = false;
                    reglasMayoreo = [];
                    $('#habilitarMayoreo').prop('checked', false);
                    $('#mayoreoSection').hide();
                    $('#btnAgregarReglaMayoreo').hide();
                }
                renderizarReglasMayoreo();
            }

            $('#habilitarMayoreo').on('change', function() {
                mayoreoHabilitado = $(this).is(':checked');
                if (mayoreoHabilitado) {
                    $('#mayoreoSection').slideDown();
                    $('#btnAgregarReglaMayoreo').show();
                    if (reglasMayoreo.length === 0) agregarReglaMayoreo(10, 0);
                } else {
                    $('#mayoreoSection').slideUp();
                    $('#btnAgregarReglaMayoreo').hide();
                    reglasMayoreo = [];
                    renderizarReglasMayoreo();
                }
            });

            $('#btnAgregarReglaMayoreo').on('click', function() {
                let sugerencia = 10;
                if (reglasMayoreo.length > 0) sugerencia = Math.max(...reglasMayoreo.map(r => r.cantidad)) + 10;
                agregarReglaMayoreo(sugerencia, 0);
            });

            // =============================================
            // FUNCIONES PARA CARRUSEL AMPLIADO
            // =============================================

            window.abrirCarruselAmpliado = function(productoId, slideIndex, event) {
                if (event) event.stopPropagation();
                const modal = new bootstrap.Modal(document.getElementById('imagenAmpliadaModal'));
                const carouselInner = document.getElementById('imagenAmpliadaCarouselInner');
                const carouselIndicators = document.getElementById('imagenAmpliadaCarouselIndicators');
                const imagenCargando = document.getElementById('imagenCargando');
                const sinImagenMensaje = document.getElementById('sinImagenMensaje');
                const btnDescargar = document.getElementById('btnDescargarImagen');
                let imagenes = [];
                let productoNombre = '';
                const carouselSmall = document.getElementById('carouselSmall-' + productoId);
                if (carouselSmall) {
                    const items = carouselSmall.querySelectorAll('.carousel-item img');
                    items.forEach(img => {
                        if (img.src) imagenes.push({
                            ruta_imagen: img.src
                        });
                    });
                    const row = carouselSmall.closest('tr');
                    if (row) productoNombre = row.querySelector('td:nth-child(3) strong')?.textContent.trim() || 'Producto';
                }
                if (imagenes.length === 0) {
                    const carouselMobile = document.getElementById('carouselMobile-' + productoId);
                    if (carouselMobile) {
                        const items = carouselMobile.querySelectorAll('.carousel-item img');
                        items.forEach(img => {
                            if (img.src) imagenes.push({
                                ruta_imagen: img.src
                            });
                        });
                        const card = carouselMobile.closest('.producto-card-mobile');
                        if (card) productoNombre = card.querySelector('.producto-card-header h6')?.textContent.trim() || 'Producto';
                    }
                }

                function mostrarSinImagen() {
                    carouselInner.innerHTML = '';
                    carouselIndicators.innerHTML = '';
                    imagenCargando.style.display = 'none';
                    sinImagenMensaje.style.display = 'block';
                    btnDescargar.style.display = 'none';
                    modal.show();
                }
                if (imagenes.length === 0) {
                    mostrarSinImagen();
                    return;
                }

                function cargarImagenes() {
                    if (imagenes.length === 0) {
                        mostrarSinImagen();
                        return;
                    }
                    imagenCargando.style.display = 'none';
                    sinImagenMensaje.style.display = 'none';
                    btnDescargar.style.display = 'flex';
                    let innerHtml = '',
                        indicatorsHtml = '';
                    imagenes.forEach((img, index) => {
                        const activeClass = index === slideIndex ? 'active' : '';
                        innerHtml += `<div class="carousel-item ${activeClass}"><img src="${img.ruta_imagen}" class="d-block w-100" alt="${productoNombre} - Imagen ${index + 1}"></div>`;
                        indicatorsHtml += `<button type="button" data-bs-target="#imagenAmpliadaCarousel" data-bs-slide-to="${index}" class="${index === slideIndex ? 'active' : ''}" aria-label="Slide ${index + 1}"></button>`;
                    });
                    carouselInner.innerHTML = innerHtml;
                    carouselIndicators.innerHTML = indicatorsHtml;
                    btnDescargar.onclick = function() {
                        descargarImagen(imagenes[slideIndex].ruta_imagen, productoNombre);
                    };
                    const carouselElement = document.getElementById('imagenAmpliadaCarousel');
                    carouselElement.addEventListener('slid.bs.carousel', function(event) {
                        btnDescargar.onclick = function() {
                            descargarImagen(imagenes[event.to].ruta_imagen, productoNombre);
                        };
                    });
                }
                imagenCargando.style.display = 'flex';
                sinImagenMensaje.style.display = 'none';
                btnDescargar.style.display = 'none';
                carouselInner.innerHTML = '';
                carouselIndicators.innerHTML = '';
                setTimeout(cargarImagenes, 200);
                modal.show();
            };

            function descargarImagen(src, nombreProducto) {
                const link = document.createElement('a');
                link.href = src;
                const nombreArchivo = nombreProducto.toLowerCase().replace(/[^a-z0-9áéíóúñü]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '') + '.jpg';
                link.download = nombreArchivo;
                link.target = '_blank';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            // =============================================
            // FUNCIONES PARA MÚLTIPLES IMÁGENES (CÁMARA MÓVIL)
            // =============================================

            function regenerarGaleriaImagenes() {
                const galeria = $('#galeriaImagenes');
                galeria.empty();
                if (imagenesExistentes.length === 0) {
                    galeria.html(`<div class="col-12 text-center text-muted py-3"><i class="fas fa-images fa-3x mb-2"></i><p>No hay imágenes para este producto</p></div>`);
                    return;
                }
                imagenesExistentes.forEach((img, index) => {
                    const isPrincipal = img.es_principal == 1;
                    const imagenHtml = `<div class="col-md-4 col-6 galeria-item" data-index="${index}" data-id="${img.id || ''}"><div class="imagen-container ${isPrincipal ? 'principal' : ''}"><img src="${img.ruta_imagen}" alt="Imagen ${index + 1}"><span class="badge-principal">${isPrincipal ? 'Principal' : ''}</span><button type="button" class="btn-eliminar-imagen" onclick="eliminarImagenExistente(${index})"><i class="fas fa-times"></i></button><button type="button" class="btn-principal ${isPrincipal ? 'activo' : ''}" onclick="marcarComoPrincipal(${index})">${isPrincipal ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>'}</button></div></div>`;
                    galeria.append(imagenHtml);
                });
            }

            function inicializarGaleriaImagenes(imagenesData) {
                imagenesExistentes = imagenesData || [];
                if (imagenesExistentes.length > 0 && !imagenesExistentes.some(img => img.es_principal == 1)) imagenesExistentes[0].es_principal = 1;
                regenerarGaleriaImagenes();
                $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                const principalIndex = imagenesExistentes.findIndex(img => img.es_principal == 1);
                if (principalIndex !== -1) $('#imagen_principal').val(principalIndex);
                if (imagenesExistentes.length > 1) {
                    const galeriaEl = document.getElementById('galeriaImagenes');
                    if (galeriaEl) new Sortable(galeriaEl, {
                        animation: 150,
                        ghostClass: 'galeria-sortable-ghost',
                        dragClass: 'galeria-sortable-drag',
                        onEnd: function(evt) {
                            const items = Array.from(galeriaEl.children);
                            const newOrder = [];
                            items.forEach(item => newOrder.push(imagenesExistentes[$(item).data('index')]));
                            imagenesExistentes = newOrder;
                            const principalIndex = imagenesExistentes.findIndex(img => img.es_principal == 1);
                            if (principalIndex !== -1) $('#imagen_principal').val(principalIndex);
                            $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                        }
                    });
                }
            }

            window.marcarComoPrincipal = function(index) {
                imagenesExistentes = imagenesExistentes.map((img, i) => {
                    img.es_principal = (i === index) ? 1 : 0;
                    return img;
                });
                $('#imagen_principal').val(index);
                $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                regenerarGaleriaImagenes();
            };

            window.eliminarImagenExistente = function(index) {
                if (confirm('¿Estás seguro de eliminar esta imagen?')) {
                    const eraPrincipal = imagenesExistentes[index]?.es_principal == 1;
                    imagenesExistentes.splice(index, 1);
                    if (eraPrincipal && imagenesExistentes.length > 0) {
                        imagenesExistentes[0].es_principal = 1;
                        $('#imagen_principal').val(0);
                    } else if (imagenesExistentes.length === 0) $('#imagen_principal').val(0);
                    regenerarGaleriaImagenes();
                    $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                }
            };

            // Función para manejar selección de archivos (desktop y galería)
            function manejarSeleccionImagenesArchivo(files) {
                if (!files || files.length === 0) return;
                const totalImagenes = imagenesExistentes.length + nuevasImagenes.length + files.length;
                if (totalImagenes > 5) {
                    alert('Solo puedes tener hasta 5 imágenes en total.');
                    return;
                }

                Array.from(files).forEach((file, index) => {
                    if (file.size > 4 * 1024 * 1024) {
                        alert(`La imagen "${file.name}" es demasiado grande. Máximo 4MB`);
                        return;
                    }
                    if (!file.type.match('image.*')) {
                        alert(`El archivo "${file.name}" no es una imagen válida`);
                        return;
                    }

                    // Guardar el archivo en el array nuevasImagenes
                    const previewUrl = URL.createObjectURL(file);
                    nuevasImagenes.push({
                        file: file,
                        preview: previewUrl
                    });
                    mostrarPreviewNuevaImagen(previewUrl, nuevasImagenes.length - 1);
                });
            }

            // Función para manejar captura de cámara
            function manejarCapturaCamara() {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                input.capture = 'environment'; // 'environment' para cámara trasera, 'user' para frontal
                input.onchange = function(e) {
                    if (e.target.files && e.target.files.length > 0) {
                        manejarSeleccionImagenesArchivo(e.target.files);
                    }
                    // Limpiar el input para poder tomar otra foto después
                    input.value = '';
                };
                input.click();
            }

            // Función para mostrar preview de nueva imagen
            function mostrarPreviewNuevaImagen(previewUrl, index) {
                const previewContainer = $('#nuevasImagenesPreview');
                previewContainer.append(`
            <div class="col-md-4 col-6 nueva-imagen-preview" data-file-index="${index}">
                <img src="${previewUrl}" alt="Nueva imagen">
                <button type="button" class="btn-eliminar-nueva" onclick="eliminarNuevaImagen(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `);
            }

            window.eliminarNuevaImagen = function(index) {
                // Liberar el objeto URL si existe
                if (nuevasImagenes[index] && nuevasImagenes[index].preview && nuevasImagenes[index].preview.startsWith('blob:')) {
                    URL.revokeObjectURL(nuevasImagenes[index].preview);
                }
                nuevasImagenes = nuevasImagenes.filter((_, i) => i !== index);
                // Actualizar los índices en los elementos del DOM
                $('.nueva-imagen-preview').each((i, el) => {
                    $(el).attr('data-file-index', i);
                    $(el).find('.btn-eliminar-nueva').attr('onclick', `eliminarNuevaImagen(${i})`);
                });
                $(`.nueva-imagen-preview[data-file-index="${index}"]`).remove();
            };

            function resetearGaleriaImagenes() {
                // Liberar URLs de objetos blob antes de limpiar
                nuevasImagenes.forEach(img => {
                    if (img.preview && img.preview.startsWith('blob:')) {
                        URL.revokeObjectURL(img.preview);
                    }
                });
                imagenesExistentes = [];
                nuevasImagenes = [];
                $('#galeriaImagenes').empty();
                $('#nuevasImagenesPreview').empty();
                $('#imagenes').val('');
                $('#imagenes_existentes').val('[]');
                $('#imagen_principal').val('0');
            }

            // Eventos para los botones móviles
            $('#btnSeleccionarGaleria').on('click', function() {
                $('#imagenes').click();
            });

            $('#btnTomarFoto').on('click', function() {
                manejarCapturaCamara();
            });

            // Evento para el input de archivo original (desktop)
            $('#imagenes').on('change', function() {
                manejarSeleccionImagenesArchivo(this.files);
                this.value = ''; // Limpiar para permitir seleccionar el mismo archivo nuevamente
            });

            // =============================================
            // FUNCIONES PARA PRECIOS Y STOCK
            // =============================================

            function sincronizarCamposPrecio() {
                const isMobile = window.innerWidth < 768;
                if (isMobile) {
                    $('#subprecio_desktop').val($('#subprecio_mobile').val());
                    $('#descuento_desktop').val($('#descuento_mobile').val());
                    $('#precio_desktop').val($('#precio_mobile').val());
                    $('#costo_desktop').val($('#costo_mobile').val() || '');
                     $('#utilidad_desktop').val($('#utilidad_mobile').val() || '');
                    $('#subprecio_hidden').val($('#subprecio_mobile').val());
                    $('#descuento_hidden').val($('#descuento_mobile').val());
                    $('#precio_hidden').val($('#precio_mobile').val());
                    $('#costo_hidden').val($('#costo_mobile').val() || '');
                } else {
                    $('#subprecio_mobile').val($('#subprecio_desktop').val());
                    $('#descuento_mobile').val($('#descuento_desktop').val());
                    $('#precio_mobile').val($('#precio_desktop').val());
                    $('#costo_mobile').val($('#costo_desktop').val() || '');
                     $('#utilidad_mobile').val($('#utilidad_desktop').val() || '');
                    $('#subprecio_hidden').val($('#subprecio_desktop').val());
                    $('#descuento_hidden').val($('#descuento_desktop').val());
                    $('#precio_hidden').val($('#precio_desktop').val());
                    $('#costo_hidden').val($('#costo_desktop').val() || '');
                    $('#utilidad_hidden').val($('#utilidad_mobile').val() || '');
                    $('#utilidad_hidden').val($('#utilidad_desktop').val() || '');
                }
            }

            // Calcular Precio Base (subprecio) desde Costo + Utilidad
function calcularPrecioDesdeUtilidad() {
    const isMobile = window.innerWidth < 768;
    let costo = isMobile ? parseFloat($('#costo_mobile').val()) || 0 : parseFloat($('#costo_desktop').val()) || 0;
    let utilidad = isMobile ? parseFloat($('#utilidad_mobile').val()) || 0 : parseFloat($('#utilidad_desktop').val()) || 0;
    
    if (costo > 0 && utilidad > 0) {
        let precioCalculado = costo * (1 + (utilidad / 100));
        if (isMobile) {
            $('#subprecio_mobile').val(precioCalculado.toFixed(2));
        } else {
            $('#subprecio_desktop').val(precioCalculado.toFixed(2));
        }
        sincronizarCamposPrecio();
        // Recalcular precio final si hay descuento
        calcularPrecioDesdeDescuento();
    } else if (costo > 0 && utilidad === 0) {
        // Utilidad 0% = precio igual al costo
        if (isMobile) {
            $('#subprecio_mobile').val(costo.toFixed(2));
        } else {
            $('#subprecio_desktop').val(costo.toFixed(2));
        }
        sincronizarCamposPrecio();
        calcularPrecioDesdeDescuento();
    }
}

// Calcular Utilidad desde Costo y Precio Base (subprecio)
function calcularUtilidadDesdePrecio() {
    const isMobile = window.innerWidth < 768;
    let costo = isMobile ? parseFloat($('#costo_mobile').val()) || 0 : parseFloat($('#costo_desktop').val()) || 0;
    let precioBase = isMobile ? parseFloat($('#subprecio_mobile').val()) || 0 : parseFloat($('#subprecio_desktop').val()) || 0;
    
    if (costo > 0 && precioBase > 0 && precioBase > costo) {
        let utilidadCalculada = ((precioBase - costo) / costo) * 100;
        if (isMobile) {
            $('#utilidad_mobile').val(utilidadCalculada.toFixed(2));
        } else {
            $('#utilidad_desktop').val(utilidadCalculada.toFixed(2));
        }
        sincronizarCamposPrecio();
    } else if (costo > 0 && precioBase > 0 && precioBase <= costo) {
        // Precio menor o igual al costo = sin ganancia
        if (isMobile) {
            $('#utilidad_mobile').val('0.00');
        } else {
            $('#utilidad_desktop').val('0.00');
        }
        sincronizarCamposPrecio();
    }
}

            function formatearDecimales(valor) {
                const num = parseFloat(valor);
                return isNaN(num) ? '' : num.toFixed(2);
            }

            function actualizarValidacionStockPorUnidad() {
    const unidad = $('#unidad_medida').val();
    let step = 1;
    let mensaje = '';
    let placeholder = '';
    
    switch (unidad) {
        case 'pieza':
        case 'unidad':
            step = 1;
            mensaje = '<strong>Piezas:</strong> Stock en unidades enteras (1, 2, 3 piezas...)';
            placeholder = 'Ej: 5, 10, 25 piezas';
            break;
        case 'kg':
        case 'kilo':
        case 'kilogramo':
            step = 0.001;
            mensaje = '<strong>Kilos:</strong> Stock en kilogramos (puede usar decimales, ej: 1.5 kg)';
            placeholder = 'Ej: 1.5, 2.75, 0.5 kg';
            break;
        case 'litro':
        case 'l':
            step = 0.001;
            mensaje = '<strong>Litros:</strong> Stock en litros (puede usar decimales, ej: 1.75 L)';
            placeholder = 'Ej: 1.75, 2.5, 0.3 L';
            break;
        case 'tonelada':
        case 'ton':
            step = 0.001;
            mensaje = '<strong>Toneladas:</strong> Stock en toneladas (puede usar decimales)';
            placeholder = 'Ej: 1.5, 2.75 toneladas';
            break;
        default:
            step = 1;
            mensaje = 'Stock en unidades enteras';
            placeholder = 'Ej: 5, 10, 25';
    }
    
    $('.stock-input').each(function() {
        $(this).attr('step', step).attr('placeholder', placeholder).data('tipo', (step === 1) ? 'entero' : 'decimal');
    });
    
    $('.stock-unidad-indicador').html(mensaje);
}

            function sanitizarStock(input) {
                const tipo = $(input).data('tipo');
                let valor = $(input).val();
                if (valor === '' || valor === null) {
                    $(input).val(0);
                    return;
                }
                valor = valor.replace(',', '.');
                let numero = parseFloat(valor);
                if (isNaN(numero) || numero < 0) {
                    $(input).val(0);
                    return;
                }
                if (tipo === 'entero') $(input).val(Math.round(numero));
                else if (tipo === 'decimal') $(input).val((Math.round(numero * 1000) / 1000).toString().replace(/\.?0+$/, ''));
            }

            function prevenirCaracteresNoPermitidos(e, input) {
                const tipo = $(input).data('tipo');
                if (e.key === 'Backspace' || e.key === 'Delete' || e.key === 'Tab' || e.key === 'Escape' || e.key === 'Enter' || e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Home' || e.key === 'End') return;
                if (tipo === 'entero' && !/^\d$/.test(e.key)) {
                    e.preventDefault();
                    return false;
                }
                if (tipo === 'decimal') {
                    if (/^\d$/.test(e.key)) return;
                    if (e.key === '.' && !$(input).val().includes('.')) return;
                    e.preventDefault();
                    return false;
                }
            }

            $('#unidad_medida').on('change', function() {
                actualizarValidacionStockPorUnidad();
            });
            $(document).on('keydown', '.stock-input', function(e) {
                prevenirCaracteresNoPermitidos(e, this);
            });
            $(document).on('keyup', '.stock-input', function() {
                sanitizarStock(this);
            });
            $(document).on('blur', '.stock-input', function() {
                if ($(this).val() === '' || $(this).val() === null) $(this).val(0);
            });

            function calcularPrecioDesdeDescuento() {
                const isMobile = window.innerWidth < 768;
                const subprecio = isMobile ? parseFloat($('#subprecio_mobile').val()) || 0 : parseFloat($('#subprecio_desktop').val()) || 0;
                const descuento = isMobile ? parseFloat($('#descuento_mobile').val()) || 0 : parseFloat($('#descuento_desktop').val()) || 0;
                if (subprecio <= 0) return;
                let precioFinal = subprecio;
                if (descuento > 0 && descuento <= 100) precioFinal = subprecio - (subprecio * (descuento / 100));
                if (isMobile) $('#precio_mobile').val(precioFinal.toFixed(2));
                else $('#precio_desktop').val(precioFinal.toFixed(2));
                sincronizarCamposPrecio();
            }

            function calcularDescuentoDesdePrecio() {
                const isMobile = window.innerWidth < 768;
                const subprecio = isMobile ? parseFloat($('#subprecio_mobile').val()) || 0 : parseFloat($('#subprecio_desktop').val()) || 0;
                const precioFinal = isMobile ? parseFloat($('#precio_mobile').val()) || 0 : parseFloat($('#precio_desktop').val()) || 0;
                if (subprecio <= 0 || precioFinal <= 0 || precioFinal >= subprecio) {
                    if (isMobile) $('#descuento_mobile').val(0);
                    else $('#descuento_desktop').val(0);
                    return;
                }
                const descuento = ((subprecio - precioFinal) / subprecio) * 100;
                if (isMobile) $('#descuento_mobile').val(Math.min(100, descuento).toFixed(2));
                else $('#descuento_desktop').val(Math.min(100, descuento).toFixed(2));
                sincronizarCamposPrecio();
            }

 function cargarValoresPrecio(productoData) {
    const isMobile = window.innerWidth < 768;
    if (isMobile) {
        $('#subprecio_mobile').val(productoData.subprecio);
        $('#descuento_mobile').val(productoData.descuento);
        $('#precio_mobile').val(productoData.precio);
        $('#costo_mobile').val(productoData.costo || '');
        $('#utilidad_mobile').val(productoData.utilidad || '');
    } else {
        $('#subprecio_desktop').val(productoData.subprecio);
        $('#descuento_desktop').val(productoData.descuento);
        $('#precio_desktop').val(productoData.precio);
        $('#costo_desktop').val(productoData.costo || '');
        $('#utilidad_desktop').val(productoData.utilidad || '');
    }
    sincronizarCamposPrecio();
}

            $('#subprecio_desktop, #descuento_desktop, #subprecio_mobile, #descuento_mobile').on('input', function() {
    calcularPrecioDesdeDescuento();
});
           $('#precio_desktop, #precio_mobile').on('input', function() {
    calcularDescuentoDesdePrecio();
});

// Nuevos eventos para Utilidad y Costo
$('#costo_desktop, #costo_mobile').on('input', function() {
    calcularPrecioDesdeUtilidad();
});
$('#utilidad_desktop, #utilidad_mobile').on('input', function() {
    calcularPrecioDesdeUtilidad();
});
$('#subprecio_desktop, #subprecio_mobile').on('input', function() {
    calcularUtilidadDesdePrecio();
    calcularPrecioDesdeDescuento();
});

            function actualizarCamposPorUnidad() {
                const unidad = $('#unidad_medida').val();
                const permiteFraccionesCheckbox = $('#permite_fracciones');
                const fraccionesHelper = $('#fracciones_helper');
                const fraccionesContainer = permiteFraccionesCheckbox.closest('.col-md-6');
                const pesoLabel = $('#peso_label');
                const pesoHelper = $('#peso_helper');
                const pesoContainer = $('#peso_kg').closest('.col-md-6');

                if (unidad === 'kilo' || unidad === 'litro') {
                    // Para kilos y litros: mostrar opción de fracciones
                    fraccionesContainer.show();
                    permiteFraccionesCheckbox.prop('checked', true).prop('disabled', true);
                    fraccionesHelper.html(`<strong>Para ${unidad}s:</strong> permite vender fracciones (ej: 0.5 ${unidad})`);

                    // Mostrar campo de peso
                    pesoContainer.show();
                    if (unidad === 'kilo') {
                        pesoLabel.text('Peso por Unidad (kg)');
                        pesoHelper.text('Peso de cada unidad en kilogramos (ej: 1 kg = 1.000)');
                    } else if (unidad === 'litro') {
                        pesoLabel.text('Volumen por Unidad (L)');
                        pesoHelper.text('Volumen de cada unidad en litros (ej: 1 L = 1.000)');
                    }

                } else if (unidad === 'pieza') {
                    // Para piezas: OCULTAR opción de fracciones
                    fraccionesContainer.hide();
                    permiteFraccionesCheckbox.prop('checked', false).prop('disabled', false);

                    // Cambiar textos para indicar PIEZAS
                    pesoLabel.text('Piezas');
                    pesoHelper.html('Piezas');

                    // Asegurar que el campo de peso sea visible pero con el texto correcto
                    pesoContainer.show();

                    // Actualizar también los campos de stock para que usen el nuevo texto
                    actualizarValidacionStockPorUnidad();

                } else {
                    // Para otros casos
                    fraccionesContainer.show();
                    permiteFraccionesCheckbox.prop('disabled', false);
                    fraccionesHelper.html('Permite vender fracciones del producto (ej: 0.5 unidades)');

                    pesoContainer.show();
                    pesoLabel.text('Piezas');
                    pesoHelper.text('Piezas');
                }
            }
            $('#unidad_medida').on('change', actualizarCamposPorUnidad);

            // =============================================
            // ENVÍO DEL FORMULARIO CON AJAX PARA MANEJAR IMÁGENES
            // =============================================

            // Reemplazar el evento submit original
            $('#productoForm').off('submit').on('submit', function(e) {
                e.preventDefault();

                sincronizarCamposPrecio();

                if (mayoreoHabilitado && !validarReglasMayoreo()) {
                    return false;
                }

                const reglasValidas = reglasMayoreo.filter(r => r.cantidad > 0 && r.precio > 0);
                $('#precios_mayoreo').val(JSON.stringify(reglasValidas));

                const subprecio = parseFloat($('#subprecio_hidden').val());
                const precio = parseFloat($('#precio_hidden').val());

                // if (!subprecio || subprecio <= 0) {
                //     alert('El precio original es requerido y debe ser mayor a 0.');
                //     return false;
                // }

                // if (!precio || precio <= 0) {
                //     alert('El precio final es requerido y debe ser mayor a 0.');
                //     return false;
                // }

                if ($('.sucursal-checkbox:checked').length === 0) {
                    alert('Debe seleccionar al menos una sucursal para el producto.');
                    return false;
                }

                let stockValido = true;
                $('.sucursal-checkbox:checked').each(function() {
                    if (parseFloat($('#stock_' + $(this).val()).val()) < 0) stockValido = false;
                });

                if (!stockValido) {
                    alert('El stock no puede ser negativo.');
                    return false;
                }

                const totalImagenes = imagenesExistentes.length + nuevasImagenes.length;
                if (totalImagenes > 5) {
                    alert('Solo puedes tener hasta 5 imágenes por producto.');
                    return false;
                }

                // Crear FormData y agregar todos los datos del formulario
                const formData = new FormData(this);

                // Eliminar el campo imagenes[] original (puede estar vacío o no contener los archivos de cámara)
                formData.delete('imagenes[]');

                // Agregar cada archivo de nuevasImagenes al FormData
                for (let i = 0; i < nuevasImagenes.length; i++) {
                    if (nuevasImagenes[i].file) {
                        formData.append('imagenes[]', nuevasImagenes[i].file);
                    }
                }

                // Mostrar indicador de carga y DESHABILITAR el botón Guardar
                const submitBtn = $(this).find('button[type="submit"]');
                const originalBtnText = submitBtn.html();
                submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...').prop('disabled', true);

                // También deshabilitar el botón Cancelar (opcional, pero recomendado)
                const cancelBtn = $(this).closest('.modal-content').find('.btn-secondary');
                if (cancelBtn.length) {
                    cancelBtn.prop('disabled', true);
                }

                // Enviar con AJAX
                $.ajax({
                    url: $(this).attr('action') || window.location.href,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        // Verificar si hay mensaje de éxito
                        if (response.includes('Producto creado') || response.includes('Producto actualizado') || response.includes('exitosa')) {
                            mostrarMensajeTemporal('Producto guardado exitosamente', 'success');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            // Buscar mensaje de alerta en la respuesta
                            const tempDiv = $('<div>').html(response);
                            const alertMsg = tempDiv.find('.alert');
                            if (alertMsg.length) {
                                mostrarMensajeTemporal(alertMsg.text(), 'info');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                mostrarMensajeTemporal('Producto guardado exitosamente', 'success');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1500);
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al guardar:', error);
                        mostrarMensajeTemporal('Error al guardar el producto. Intenta de nuevo.', 'danger');
                        // REHABILITAR el botón en caso de error
                        submitBtn.html(originalBtnText).prop('disabled', false);
                        if (cancelBtn.length) {
                            cancelBtn.prop('disabled', false);
                        }
                    },
                    complete: function() {
                        // Nota: No rehabilitamos el botón en complete porque en caso de éxito
                        // la página se recargará, y en caso de error ya lo rehabilitamos arriba.
                    }
                });

                return false;
            });

            // =============================================
            // CONTROL DEL SIDEBAR
            // =============================================
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function openSidebar() {
                if (sidebar && sidebarBackdrop) {
                    sidebar.classList.add('show');
                    sidebarBackdrop.classList.add('show');
                    document.body.classList.add('sidebar-open');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeSidebar() {
                if (sidebar && sidebarBackdrop) {
                    sidebar.classList.remove('show');
                    sidebarBackdrop.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                    document.body.style.overflow = '';
                }
            }

            function toggleSidebar() {
                if (sidebar.classList.contains('show')) closeSidebar();
                else openSidebar();
            }
            if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
            if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);
            document.querySelectorAll('#sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) closeSidebar();
                });
            });

            // =============================================
            // FUNCIONES PARA NUEVO/EDITAR/CLONAR PRODUCTO
            // =============================================

            function nuevoProducto() {
                <?php if ($limite_alcanzado): ?>
                    mostrarMensajeTemporal('Ha alcanzado el límite de productos para su plan (<?php echo $limite_productos; ?> productos). Actualice su plan.', 'danger');
                    return;
                <?php endif; ?>

                // SOLO cambiar el título y la acción - NO limpiar nada
                $('#modalTitle').text('Nuevo Producto');
                $('#formAction').val('crear');
                $('#productoId').val('');

                if (mayoreoHabilitado) {
                    $('#mayoreoSection').show();
                    $('#btnAgregarReglaMayoreo').show();
                }

                $('.sucursal-checkbox:checked').each(function() {
                    $('#stock_fields_' + $(this).val()).show();
                });

                actualizarCamposPorUnidad();
                actualizarValidacionStockPorUnidad();

                $('#productoModal').modal('show');
            }

            function abrirModalEdicionProducto(productoData) {
    $('#modalTitle').text('Editar Producto');
    $('#formAction').val('editar');
    $('#productoId').val(productoData.id);
    $('#productoActivo').val(productoData.activo !== undefined ? productoData.activo : 1);
    $('#codigo').val(productoData.codigo);
    $('#nombre').val(productoData.nombre);
    $('#descripcion').val(productoData.descripcion || '');
    $('#marca').val(productoData.marca || '');
    cargarValoresPrecio(productoData);
    
    // ⭐ NUEVO: Asignar el costo explícitamente
    if (productoData.costo !== undefined && productoData.costo !== null) {
        const isMobile = window.innerWidth < 768;
        if (isMobile) {
            $('#costo_mobile').val(productoData.costo);
        } else {
            $('#costo_desktop').val(productoData.costo);
        }
        sincronizarCamposPrecio();
    }
    
    $('#categoria_id').val(productoData.categoria_id || '');
    $('#proveedor_id').val(productoData.proveedor_id || '');
    $('#unidad_medida').val(productoData.unidad_medida || 'pieza');
    $('#peso_kg').val(productoData.peso_kg || '1.000');
    $('#permite_fracciones').prop('checked', productoData.permite_fracciones == 1);
    $('#fecha_caducidad').val(productoData.fecha_caducidad);
    $('#tipo_producto').val(productoData.tipo_producto || 'Estandar');
    $('#imagenes_existentes').val(JSON.stringify(productoData.imagenes || []));
    $('#porcentaje_merma_danado').val(productoData.porcentaje_merma_danado || 0);
    $('#porcentaje_merma_deshidratacion').val(productoData.porcentaje_merma_deshidratacion || 0);
    $('#aplicar_merma_venta').prop('checked', productoData.aplicar_merma_venta == 1);
    $('#aplicar_merma_compra').prop('checked', productoData.aplicar_merma_compra == 1);
    resetearGaleriaImagenes();
    if (productoData.imagenes && productoData.imagenes.length > 0) inicializarGaleriaImagenes(productoData.imagenes);
    cargarReglasMayoreo(productoData.precios_mayoreo);
    $('.sucursal-checkbox').prop('checked', false);
    $('.stock-fields').hide();
    $('.stock-input').val(0);
    productoData.sucursales.forEach(sucursalId => {
        if (sucursalId) {
            $('#sucursal_' + sucursalId).prop('checked', true);
            $('#stock_fields_' + sucursalId).show();
            if (productoData.stocks && productoData.stocks[sucursalId]) $('#stock_' + sucursalId).val(productoData.stocks[sucursalId].stock || 0);
        }
    });
    toggleNuevaCategoria(false);
    toggleNuevoProveedor(false);
    actualizarCamposPorUnidad();
    actualizarValidacionStockPorUnidad();
    $('#productoModal').modal('show');
}

            function clonarProducto(productoData) {
                <?php if ($limite_alcanzado): ?>
                    mostrarMensajeTemporal('Ha alcanzado el límite de productos para su plan (<?php echo $limite_productos; ?> productos). Actualice su plan.', 'danger');
                    return;
                <?php endif; ?>
                $('#productoForm')[0].reset();
                $('#modalTitle').text('Clonar Producto: ' + productoData.nombre);
                $('#formAction').val('crear');
                $('#productoId').val('');
                $('#nombre').val(productoData.nombre + ' (Copia)');
                $('#descripcion').val(productoData.descripcion || '');
                $('#marca').val(productoData.marca || '');
                cargarValoresPrecio(productoData);
                let codigoClonado = String(productoData.codigo || '');
                if (codigoClonado && !codigoClonado.startsWith('S')) codigoClonado = 'S' + codigoClonado;
                $('#codigo').val(codigoClonado).removeClass('codigo-autogenerado');
                resetearGaleriaImagenes();
                cargarReglasMayoreo(productoData.precios_mayoreo || []);
                $('#categoria_id').val(productoData.categoria_id || '');
                $('#proveedor_id').val(productoData.proveedor_id || '');
                $('#unidad_medida').val(productoData.unidad_medida || 'pieza');
                $('#peso_kg').val(productoData.peso_kg || '1.000');
                $('#permite_fracciones').prop('checked', productoData.permite_fracciones == 1);
                $('#fecha_caducidad').val(productoData.fecha_caducidad || '');
                $('#tipo_producto').val(productoData.tipo_producto || 'Estandar');
                $('#porcentaje_merma_danado').val(productoData.porcentaje_merma_danado || 0);
                $('#porcentaje_merma_deshidratacion').val(productoData.porcentaje_merma_deshidratacion || 0);
                $('#aplicar_merma_venta').prop('checked', productoData.aplicar_merma_venta == 1);
                $('#aplicar_merma_compra').prop('checked', productoData.aplicar_merma_compra == 1);
                $('.sucursal-checkbox').prop('checked', false);
                $('.stock-fields').hide();
                $('.stock-input').val(0);
                if (productoData.sucursales && productoData.sucursales.length > 0) {
                    productoData.sucursales.forEach(sucursalId => {
                        if (sucursalId) {
                            $('#sucursal_' + sucursalId).prop('checked', true);
                            $('#stock_fields_' + sucursalId).show();
                            if (productoData.stocks && productoData.stocks[sucursalId]) $('#stock_' + sucursalId).val(productoData.stocks[sucursalId].stock || 0);
                        }
                    });
                }
                toggleNuevaCategoria(false);
                toggleNuevoProveedor(false);
                actualizarCamposPorUnidad();
                actualizarValidacionStockPorUnidad();
                $('#productoModal').modal('show');
            }

            function reinicializarEventosProductos() {
                $('.edit-producto').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    abrirModalEdicionProducto({
                        id: $(this).data('id'),
                        activo: $(this).data('activo') !== undefined ? $(this).data('activo') : 1,
                        codigo: $(this).data('codigo'),
                        nombre: $(this).data('nombre'),
                        descripcion: $(this).data('descripcion'),
                        marca: $(this).data('marca'),
                        precio: $(this).data('precio'),
                        subprecio: $(this).data('subprecio') || $(this).data('precio'),
                        descuento: $(this).data('descuento') || 0,
                        costo: $(this).data('costo'),
                        categoria_id: $(this).data('categoria_id'),
                        proveedor_id: $(this).data('proveedor_id'),
                        unidad_medida: $(this).data('unidad_medida'),
                        peso_kg: $(this).data('peso_kg'),
                        permite_fracciones: $(this).data('permite_fracciones'),
                        fecha_caducidad: $(this).data('fecha_caducidad') || '',
                        tipo_producto: $(this).data('tipo_producto') || 'Estandar',
                        porcentaje_merma_danado: $(this).data('porcentaje_merma_danado') || 0,
                        porcentaje_merma_deshidratacion: $(this).data('porcentaje_merma_deshidratacion') || 0,
                        aplicar_merma_venta: $(this).data('aplicar_merma_venta') || 0,
                        aplicar_merma_compra: $(this).data('aplicar_merma_compra') || 0,
                        imagenes: $(this).data('imagenes') || [],
                        sucursales: $(this).data('sucursales') ? $(this).data('sucursales').toString().split(',').filter(id => id !== '') : [],
                        stocks: $(this).data('stocks') || {},
                        precios_mayoreo: $(this).data('precios-mayoreo') || []
                    });
                });
                $('.clone-producto').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clonarProducto({
                        id: $(this).data('id'),
                        codigo: $(this).data('codigo'),
                        nombre: $(this).data('nombre'),
                        descripcion: $(this).data('descripcion'),
                        marca: $(this).data('marca'),
                        precio: $(this).data('precio'),
                        subprecio: $(this).data('subprecio') || $(this).data('precio'),
                        descuento: $(this).data('descuento') || 0,
                        costo: $(this).data('costo'),
                        categoria_id: $(this).data('categoria_id'),
                        proveedor_id: $(this).data('proveedor_id'),
                        unidad_medida: $(this).data('unidad_medida'),
                        peso_kg: $(this).data('peso_kg'),
                        permite_fracciones: $(this).data('permite_fracciones'),
                        fecha_caducidad: $(this).data('fecha_caducidad') || '',
                        tipo_producto: $(this).data('tipo_producto') || 'Estandar',
                        porcentaje_merma_danado: $(this).data('porcentaje_merma_danado') || 0,
                        porcentaje_merma_deshidratacion: $(this).data('porcentaje_merma_deshidratacion') || 0,
                        aplicar_merma_venta: $(this).data('aplicar_merma_venta') || 0,
                        aplicar_merma_compra: $(this).data('aplicar_merma_compra') || 0,
                        imagenes: $(this).data('imagenes') || [],
                        sucursales: $(this).data('sucursales') ? $(this).data('sucursales').toString().split(',').filter(id => id !== '') : [],
                        stocks: $(this).data('stocks') || {},
                        precios_mayoreo: $(this).data('precios-mayoreo') || []
                    });
                });
                $('.clone-producto-mobile').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clonarProducto({
                        id: $(this).data('id'),
                        codigo: $(this).data('codigo'),
                        nombre: $(this).data('nombre'),
                        descripcion: $(this).data('descripcion'),
                        marca: $(this).data('marca'),
                        precio: $(this).data('precio'),
                        subprecio: $(this).data('subprecio') || $(this).data('precio'),
                        descuento: $(this).data('descuento') || 0,
                        costo: $(this).data('costo'),
                        categoria_id: $(this).data('categoria_id'),
                        proveedor_id: $(this).data('proveedor_id'),
                        unidad_medida: $(this).data('unidad_medida'),
                        peso_kg: $(this).data('peso_kg'),
                        permite_fracciones: $(this).data('permite_fracciones'),
                        fecha_caducidad: $(this).data('fecha_caducidad') || '',
                        tipo_producto: $(this).data('tipo_producto') || 'Estandar',
                        porcentaje_merma_danado: $(this).data('porcentaje_merma_danado') || 0,
                        porcentaje_merma_deshidratacion: $(this).data('porcentaje_merma_deshidratacion') || 0,
                        aplicar_merma_venta: $(this).data('aplicar_merma_venta') || 0,
                        aplicar_merma_compra: $(this).data('aplicar_merma_compra') || 0,
                        imagenes: $(this).data('imagenes') || [],
                        sucursales: $(this).data('sucursales') ? $(this).data('sucursales').toString().split(',').filter(id => id !== '') : [],
                        stocks: $(this).data('stocks') || {},
                        precios_mayoreo: $(this).data('precios-mayoreo') || []
                    });
                });
                $('.cambiar-estado-btn, .cambiar-estado-btn-mobile').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const id = $(this).data('id');
                    const nuevoActivo = $(this).data('activo');
                    const texto = nuevoActivo == 1 ? 'activar' : 'desactivar';
                    if (confirm(`¿Deseas ${texto} este producto?`)) {
                        $.ajax({
                            url: 'productos.php',
                            type: 'POST',
                            data: {
                                accion: 'cambiar_estado',
                                id: id,
                                activo: nuevoActivo
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    mostrarMensajeTemporal(response.message, 'success');
                                    cargarProductosConFiltros();
                                } else {
                                    mostrarMensajeTemporal(response.message, 'danger');
                                }
                            },
                            error: function() {
                                mostrarMensajeTemporal('Error al cambiar el estado del producto', 'danger');
                            }
                        });
                    }
                });
                $('.producto-row').off('click').on('click', function(event) {
                    if (!$(event.target).closest('.btn-group-actions, .carousel-control-prev, .carousel-control-next, .carousel-indicators, .producto-imagen-carousel, .no-imagen-container, .carousel-item img, .cambiar-estado-btn').length) {
                        const editBtn = $(this).find('.edit-producto');
                        if (editBtn.length) editBtn.click();
                    }
                });
                $('.producto-card-mobile').off('click').on('click', function(event) {
                    if (!$(event.target).closest('.edit-producto-mobile, .clone-producto-mobile, .cambiar-estado-btn-mobile, .carousel-control-prev, .carousel-control-next, .producto-imagen-carousel, .no-imagen-container, .carousel-item img').length) {
                        const editBtn = $(this).find('.edit-producto-mobile');
                        if (editBtn.length) editBtn.click();
                    }
                });
            }

            function toggleNuevaCategoria(mostrar) {
                if (mostrar) {
                    $('#nuevaCategoriaRow').show();
                    $('#categoria_id').prop('disabled', true);
                    $('#nuevaCategoriaNombre').focus();
                } else {
                    $('#nuevaCategoriaRow').hide();
                    $('#categoria_id').prop('disabled', false);
                    $('#nuevaCategoriaNombre').val('');
                }
            }

            function toggleNuevoProveedor(mostrar) {
                if (mostrar) {
                    $('#nuevoProveedorRow').show();
                    $('#proveedor_id').prop('disabled', true);
                    $('#nuevoProveedorNombre').focus();
                } else {
                    $('#nuevoProveedorRow').hide();
                    $('#proveedor_id').prop('disabled', false);
                    $('#nuevoProveedorNombre').val('');
                }
            }

            function inicializarCamposPrecio() {
                sincronizarCamposPrecio();
                $('#subprecio_desktop, #costo_desktop, #precio_desktop, #utilidad_desktop').on('blur', function() {
    $(this).val(formatearDecimales($(this).val()));
    sincronizarCamposPrecio();
});
            }

            function obtenerDatosProductoDesdeElemento(elemento) {
                let targetElement = elemento.closest('.producto-row, .producto-card-mobile');
                if (!targetElement) return null;
                if (targetElement.classList.contains('producto-row')) {
                    const editButton = targetElement.querySelector('.edit-producto');
                    if (editButton) return {
                        id: editButton.dataset.id,
                        activo: editButton.dataset.activo !== undefined ? editButton.dataset.activo : 1,
                        codigo: editButton.dataset.codigo,
                        nombre: editButton.dataset.nombre,
                        descripcion: editButton.dataset.descripcion,
                        marca: editButton.dataset.marca,
                        precio: editButton.dataset.precio,
                        subprecio: editButton.dataset.subprecio,
                        descuento: editButton.dataset.descuento,
                        costo: editButton.dataset.costo,
                        categoria_id: editButton.dataset.categoria_id,
                        proveedor_id: editButton.dataset.proveedor_id,
                        unidad_medida: editButton.dataset.unidad_medida,
                        peso_kg: editButton.dataset.peso_kg,
                        permite_fracciones: editButton.dataset.permite_fracciones,
                        fecha_caducidad: editButton.dataset.fecha_caducidad,
                        tipo_producto: editButton.dataset.tipo_producto,
                        porcentaje_merma_danado: editButton.dataset.porcentaje_merma_danado,
                        porcentaje_merma_deshidratacion: editButton.dataset.porcentaje_merma_deshidratacion,
                        aplicar_merma_venta: editButton.dataset.aplicar_merma_venta,
                        aplicar_merma_compra: editButton.dataset.aplicar_merma_compra,
                        imagenes: editButton.dataset.imagenes ? JSON.parse(editButton.dataset.imagenes) : [],
                        sucursales: editButton.dataset.sucursales ? editButton.dataset.sucursales.toString().split(',').filter(id => id !== '') : [],
                        stocks: editButton.dataset.stocks ? JSON.parse(editButton.dataset.stocks) : {},
                        precios_mayoreo: editButton.dataset.preciosMayoreo ? JSON.parse(editButton.dataset.preciosMayoreo) : [],
                        utilidad: editButton.dataset.utilidad || '',
                    };
                } else if (targetElement.classList.contains('producto-card-mobile')) {
                    const editButtonMobile = targetElement.querySelector('.edit-producto-mobile');
                    if (editButtonMobile) return {
                        id: editButtonMobile.dataset.id,
                        activo: editButtonMobile.dataset.activo !== undefined ? editButtonMobile.dataset.activo : 1,
                        codigo: editButtonMobile.dataset.codigo,
                        nombre: editButtonMobile.dataset.nombre,
                        descripcion: editButtonMobile.dataset.descripcion,
                        marca: editButtonMobile.dataset.marca,
                        precio: editButtonMobile.dataset.precio,
                        subprecio: editButtonMobile.dataset.subprecio,
                        descuento: editButtonMobile.dataset.descuento,
                        costo: editButtonMobile.dataset.costo,
                        categoria_id: editButtonMobile.dataset.categoria_id,
                        proveedor_id: editButtonMobile.dataset.proveedor_id,
                        unidad_medida: editButtonMobile.dataset.unidad_medida,
                        peso_kg: editButtonMobile.dataset.peso_kg,
                        permite_fracciones: editButtonMobile.dataset.permite_fracciones,
                        fecha_caducidad: editButtonMobile.dataset.fecha_caducidad,
                        tipo_producto: editButtonMobile.dataset.tipo_producto,
                        porcentaje_merma_danado: editButtonMobile.dataset.porcentaje_merma_danado,
                        porcentaje_merma_deshidratacion: editButtonMobile.dataset.porcentaje_merma_deshidratacion,
                        aplicar_merma_venta: editButtonMobile.dataset.aplicar_merma_venta,
                        aplicar_merma_compra: editButtonMobile.dataset.aplicar_merma_compra,
                        imagenes: editButtonMobile.dataset.imagenes ? JSON.parse(editButtonMobile.dataset.imagenes) : [],
                        sucursales: editButtonMobile.dataset.sucursales ? editButtonMobile.dataset.sucursales.toString().split(',').filter(id => id !== '') : [],
                        stocks: editButtonMobile.dataset.stocks ? JSON.parse(editButtonMobile.dataset.stocks) : {},
                        precios_mayoreo: editButtonMobile.dataset.preciosMayoreo ? JSON.parse(editButtonMobile.dataset.preciosMayoreo) : [],
                        utilidad: editButtonMobile.dataset.utilidad || '',
                    };
                }
                return null;
            }

            function abrirModalEdicionDesdeClick(event, elemento) {
                if ($(event.target).closest('.btn-group-actions, .edit-producto, .edit-producto-mobile, .clone-producto, .clone-producto-mobile, .cambiar-estado-btn, .cambiar-estado-btn-mobile, form, .btn, .carousel-control-prev, .carousel-control-next, .carousel-indicators, .producto-imagen-carousel, .no-imagen-container, .carousel-item img').length) return;
                const productoData = obtenerDatosProductoDesdeElemento(elemento);
                if (productoData && productoData.id) abrirModalEdicionProducto(productoData);
            }

            $(document).on('click', '.producto-row', function(event) {
                abrirModalEdicionDesdeClick(event, this);
            });
            $(document).on('click', '.producto-card-mobile', function(event) {
                abrirModalEdicionDesdeClick(event, this);
            });

            // =============================================
            // EVENTOS DE FILTROS
            // =============================================

            $('#searchInput, #searchInputMobile').on('input', function() {
                clearTimeout(searchTimeout);
                $('#searchLoading').show();
                searchTimeout = setTimeout(() => {
                    if ($(this).attr('id') === 'searchInput') $('#searchInputMobile').val($(this).val());
                    else $('#searchInput').val($(this).val());
                    aplicarFiltrosYRecargar(true);
                }, 500);
            });

            $('#filterCategoria, #filterProveedor, #filterSucursal, #showInactive').on('change', function() {
                aplicarFiltrosYRecargar(true);
            });
            $('#filterCategoriaMobile, #filterProveedorMobile, #filterSucursalMobile, #showInactiveMobile').on('change', function() {
                actualizarFiltrosDesdeMoviles();
                aplicarFiltrosYRecargar(true);
            });

            $('#btnAplicarFiltrosMobile').on('click', function() {
                actualizarFiltrosDesdeMoviles();
                aplicarFiltrosYRecargar(true);
                $('#filtrosPanel').removeClass('show');
            });

            $('#btnClearFilters, #btnClearFiltersMobile').on('click', function() {
                $('#searchInput, #searchInputMobile').val('');
                $('#filterCategoria, #filterCategoriaMobile').val('');
                $('#filterProveedor, #filterProveedorMobile').val('');
                $('#filterSucursal, #filterSucursalMobile').val('');
                $('#showInactive, #showInactiveMobile').prop('checked', false);
                aplicarFiltrosYRecargar(true);
            });

            // =============================================
            // OTROS EVENTOS
            // =============================================

            $('#btnNuevoProducto').on('click', nuevoProducto);

            $('#btnGenerarCodigo').on('click', function() {
                $.ajax({
                    url: 'generar_codigo.php',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#codigo').val(response.codigo).addClass('codigo-autogenerado');
                            setTimeout(() => $('#codigo').removeClass('codigo-autogenerado'), 2000);
                            $('#nombre').focus();
                        } else alert('Error al generar código: ' + response.message);
                    },
                    error: function() {
                        alert('Error de conexión al generar código');
                    }
                });
            });

            $('#btnSugerirCodigo').on('click', function() {
                if (!$('#codigo').val()) $('#btnGenerarCodigo').click();
                else {
                    $('#codigo').focus();
                    $('#codigo').select();
                }
            });

            $('.sucursal-checkbox').on('change', function() {
                const sucursalId = $(this).val();
                if ($(this).is(':checked')) $('#stock_fields_' + sucursalId).show();
                else {
                    $('#stock_fields_' + sucursalId).hide();
                    $('#stock_' + sucursalId).val(0);
                }
            });

            $('#btnNuevaCategoria').on('click', () => toggleNuevaCategoria(true));
            $('#btnCancelarCategoria').on('click', () => toggleNuevaCategoria(false));
            $('#btnGuardarCategoria').on('click', function() {
                const nombreCategoria = $('#nuevaCategoriaNombre').val().trim();
                if (!nombreCategoria) {
                    alert('Por favor ingresa un nombre para la categoría.');
                    return;
                }
                $(this).html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...').prop('disabled', true);
                $.ajax({
                    url: 'guardar_categoria.php',
                    type: 'POST',
                    data: {
                        accion: 'crear',
                        nombre: nombreCategoria
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            const nuevaOpcion = new Option(response.nombre, response.categoria_id, true, true);
                            $('#categoria_id').append(nuevaOpcion).trigger('change');
                            toggleNuevaCategoria(false);
                            $('#filterCategoria, #filterCategoriaMobile').append(new Option(response.nombre, response.categoria_id));
                            alert(response.message);
                        } else alert('Error al crear la categoría: ' + response.message);
                    },
                    error: function() {
                        alert('Error de conexión al crear la categoría');
                    },
                    complete: function() {
                        $('#btnGuardarCategoria').html('<i class="fas fa-save me-2"></i>Guardar').prop('disabled', false);
                    }
                });
            });

            $('#btnNuevoProveedor').on('click', () => toggleNuevoProveedor(true));
            $('#btnCancelarProveedor').on('click', () => toggleNuevoProveedor(false));
            $('#btnGuardarProveedor').on('click', function() {
                const nombreProveedor = $('#nuevoProveedorNombre').val().trim();
                if (!nombreProveedor) {
                    alert('Por favor ingresa un nombre para el proveedor.');
                    return;
                }
                $(this).html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...').prop('disabled', true);
                $.ajax({
                    url: 'guardar_proveedor.php',
                    type: 'POST',
                    data: {
                        accion: 'crear',
                        nombre: nombreProveedor
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            const nuevaOpcion = new Option(response.nombre, response.proveedor_id, true, true);
                            $('#proveedor_id').append(nuevaOpcion).trigger('change');
                            toggleNuevoProveedor(false);
                            $('#filterProveedor, #filterProveedorMobile').append(new Option(response.nombre, response.proveedor_id));
                            alert(response.message);
                        } else alert('Error al crear el proveedor: ' + response.message);
                    },
                    error: function() {
                        alert('Error de conexión al crear el proveedor');
                    },
                    complete: function() {
                        $('#btnGuardarProveedor').html('<i class="fas fa-save me-2"></i>Guardar').prop('disabled', false);
                    }
                });
            });

            $('#nuevaCategoriaNombre, #nuevoProveedorNombre').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    if ($(this).attr('id') === 'nuevaCategoriaNombre') $('#btnGuardarCategoria').click();
                    else $('#btnGuardarProveedor').click();
                }
            });

            // =============================================
            // TRANSFERENCIA DE STOCK ENTRE SUCURSALES
            // =============================================

            // Exclusión mutua: al cambiar un select deshabilita esa opción en el otro
            function sincronizarSelectsTransferencia() {
                const origenVal = $('#trans_sucursal_origen').val();
                const destinoVal = $('#trans_sucursal_destino').val();

                // Resetear todas las opciones en ambos selects
                $('#trans_sucursal_origen option, #trans_sucursal_destino option').prop('disabled', false);

                // Deshabilitar en destino la opción seleccionada en origen
                if (origenVal) {
                    $('#trans_sucursal_destino option[value="' + origenVal + '"]').prop('disabled', true);
                    // Si destino tenía ese mismo valor, limpiarlo
                    if ($('#trans_sucursal_destino').val() === origenVal) {
                        $('#trans_sucursal_destino').val('');
                    }
                }

                // Deshabilitar en origen la opción seleccionada en destino
                if (destinoVal) {
                    $('#trans_sucursal_origen option[value="' + destinoVal + '"]').prop('disabled', true);
                    // Si origen tenía ese mismo valor, limpiarlo
                    if ($('#trans_sucursal_origen').val() === destinoVal) {
                        $('#trans_sucursal_origen').val('');
                    }
                }
            }

            $(document).on('change', '#trans_sucursal_origen, #trans_sucursal_destino', function() {
                sincronizarSelectsTransferencia();
            });

            // Actualiza la sección Sucursales y Stock del modal con los nuevos stocks
            function refrescarStockSucursales(stocksActualizados) {
                $.each(stocksActualizados, function(sucId, stockVal) {
                    const $checkbox = $('#sucursal_' + sucId);
                    const $stockFields = $('#stock_fields_' + sucId);
                    const $input = $('#stock_' + sucId);

                    // Si el checkbox existe pero no estaba marcado (sucursal destino nueva),
                    // marcarlo y mostrar sus campos igual que cuando el usuario lo activa
                    if ($checkbox.length && !$checkbox.is(':checked')) {
                        $checkbox.prop('checked', true);
                        $stockFields.show();
                    }

                    // Actualizar el valor del input
                    if ($input.length) {
                        $input.val(stockVal);

                        // Destello visual para indicar el cambio
                        $stockFields
                            .addClass('stock-actualizado-highlight')
                            .delay(1500)
                            .queue(function(next) {
                                $(this).removeClass('stock-actualizado-highlight');
                                next();
                            });
                    }
                });
            }

            $(document).on('click', '#btnEjecutarTransferencia', function() {
                const productoId = $('#productoId').val();
                const origenId = $('#trans_sucursal_origen').val();
                const destinoId = $('#trans_sucursal_destino').val();
                const cantidad = parseFloat($('#trans_cantidad').val());
                const observaciones = $('#trans_observaciones').val().trim();
                const $resultado = $('#trans_resultado');

                // Validaciones del lado cliente
                if (!origenId || !destinoId) {
                    $resultado.show().html('<div class="alert alert-warning py-2 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Selecciona origen y destino.</div>');
                    return;
                }
                if (!cantidad || cantidad <= 0) {
                    $resultado.show().html('<div class="alert alert-warning py-2 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Ingresa una cantidad válida mayor a 0.</div>');
                    return;
                }

                const $btn = $(this);
                $btn.html('<i class="fas fa-spinner fa-spin me-1"></i>Transfiriendo...').prop('disabled', true);
                $resultado.hide().empty();

                $.ajax({
                    url: 'transferir_stock.php',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        producto_id: parseInt(productoId),
                        sucursal_origen_id: parseInt(origenId),
                        sucursal_destino_id: parseInt(destinoId),
                        cantidad: cantidad,
                        observaciones: observaciones
                    }),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $resultado.show().html('<div class="alert alert-success py-2 mb-0">' + response.message + '</div>');

                            // Actualizar sección Sucursales y Stock
                            if (response.data && response.data.stocks_actualizados) {
                                refrescarStockSucursales(response.data.stocks_actualizados);
                            }

                            // Limpiar campos y restaurar opciones de los selects
                            $('#trans_sucursal_origen, #trans_sucursal_destino').val('');
                            $('#trans_sucursal_origen option, #trans_sucursal_destino option').prop('disabled', false);
                            $('#trans_cantidad').val('');
                            $('#trans_observaciones').val('');
                        } else {
                            $resultado.show().html('<div class="alert alert-danger py-2 mb-0"><i class="fas fa-times-circle me-1"></i>' + response.message + '</div>');
                        }
                    },
                    error: function() {
                        $resultado.show().html('<div class="alert alert-danger py-2 mb-0"><i class="fas fa-times-circle me-1"></i>Error de conexión al transferir stock.</div>');
                    },
                    complete: function() {
                        $btn.html('<i class="fas fa-paper-plane me-1"></i>Transferir').prop('disabled', false);
                    }
                });
            });

            $('#productoModal').on('hidden.bs.modal', function() {
                toggleNuevaCategoria(false);
                toggleNuevoProveedor(false);
                $('.alert-temp').remove();
            });

            const filtrosToggle = document.getElementById('filtrosToggle');
            const filtrosPanel = document.getElementById('filtrosPanel');
            if (filtrosToggle) {
                filtrosToggle.addEventListener('click', () => filtrosPanel.classList.toggle('show'));
                document.addEventListener('click', function(e) {
                    if (filtrosToggle && filtrosPanel && !filtrosToggle.contains(e.target) && !filtrosPanel.contains(e.target)) filtrosPanel.classList.remove('show');
                });
            }

            let archivoSeleccionado = null;
            $('#btnImportarProductos').on('click', function() {
                $('#importarModal').modal('show');
                $('#archivoImportar').val('');
                $('#importProgress').hide();
                $('#importResult').hide();
            });
            $('#archivoImportar').on('change', function(e) {
                archivoSeleccionado = e.target.files[0];
                if (archivoSeleccionado && archivoSeleccionado.size > 5 * 1024 * 1024) {
                    alert('El archivo es demasiado grande. Máximo 5MB.');
                    $(this).val('');
                    archivoSeleccionado = null;
                }
            });
            $('#btnProcesarImportacion').on('click', function() {
                if (!archivoSeleccionado) {
                    alert('Por favor selecciona un archivo');
                    return;
                }
                if (!confirm('¿Estás seguro de importar productos? Esta acción no se puede deshacer.')) return;
                $('#importProgress').show();
                $('#importProgressBar').css('width', '50%').text('Procesando...');
                $('#importResult').hide();
                $(this).prop('disabled', true);
                const formData = new FormData();
                formData.append('archivo', archivoSeleccionado);
                $.ajax({
                    url: 'importar_productos.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        $('#importProgressBar').css('width', '100%').text('Completado');
                        setTimeout(() => {
                            $('#importProgress').hide();
                            $('#importResult').show();
                            if (response.success) {
                                $('#importResultAlert').removeClass('alert-danger').addClass('alert-success');
                                $('#importResultTitle').html('<i class="fas fa-check-circle me-2"></i>Importación Exitosa');
                                $('#importResultMessage').text(response.message);
                                if (response.errores && response.errores.length > 0) {
                                    let erroresHtml = '<hr><h6>Errores encontrados:</h6><ul class="mb-0">';
                                    response.errores.forEach(error => erroresHtml += `<li class="text-danger small">${error}</li>`);
                                    erroresHtml += '</ul>';
                                    $('#importResultErrors').html(erroresHtml);
                                } else $('#importResultErrors').empty();
                                setTimeout(() => window.location.reload(), 2000);
                            } else {
                                $('#importResultAlert').removeClass('alert-success').addClass('alert-danger');
                                $('#importResultTitle').html('<i class="fas fa-exclamation-triangle me-2"></i>Error');
                                $('#importResultMessage').text(response.message);
                                $('#importResultErrors').empty();
                            }
                        }, 500);
                    },
                    error: function(xhr, status, error) {
                        $('#importProgress').hide();
                        $('#importResult').show();
                        $('#importResultAlert').removeClass('alert-success').addClass('alert-danger');
                        $('#importResultTitle').html('<i class="fas fa-exclamation-triangle me-2"></i>Error');
                        $('#importResultMessage').text('Error de conexión: ' + error);
                        $('#importResultErrors').empty();
                    },
                    complete: function() {
                        $('#btnProcesarImportacion').prop('disabled', false);
                    }
                });
            });

            const urlParams = new URLSearchParams(window.location.search);
            filtrosActuales = {
                search: urlParams.get('search') || '',
                categoria: urlParams.get('categoria') || '',
                proveedor: urlParams.get('proveedor') || '',
                sucursal: urlParams.get('sucursal') || '',
                show_inactive: urlParams.get('show_inactive') === '1',
                pagina: parseInt(urlParams.get('pagina')) || 1
            };
            $('#searchInput, #searchInputMobile').val(filtrosActuales.search);
            $('#filterCategoria, #filterCategoriaMobile').val(filtrosActuales.categoria);
            $('#filterProveedor, #filterProveedorMobile').val(filtrosActuales.proveedor);
            $('#filterSucursal, #filterSucursalMobile').val(filtrosActuales.sucursal);
            $('#showInactive, #showInactiveMobile').prop('checked', filtrosActuales.show_inactive);

            if (filtrosActuales.search || filtrosActuales.categoria || filtrosActuales.proveedor || filtrosActuales.sucursal || filtrosActuales.show_inactive) {
                cargarProductosConFiltros();
            }

            actualizarCamposPorUnidad();
            actualizarValidacionStockPorUnidad();
            inicializarCamposPrecio();
        });
    </script>
</body>

</html>
