<?php
date_default_timezone_set('America/Mexico_City');

// ============================================
// CONFIGURACIONES Y CONEXIONES
// ============================================

// Configuración de la base de datos principal
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$db_main = "juanc141_ventas";

// Configuración cPanel API
$cpanel_host = "libertyfin.com.mx";
$cpanel_user = "juanc141";
$cpanel_api_token = "4KGLQYQZ3E7A52QI7EK20HFZCE7UD7S9";

// Configuración SMTP para enviar correos
$smtp_host = "smtp.titan.email";
$smtp_username = "notificaciones@libertyfin.com.mx";
$smtp_password = "N0tific4ci0n3s.2026#";
$smtp_port = 465;

// Directorio para almacenar documentos
$upload_dir = "uploads/";

// Crear directorio si no existe
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ============================================
// FUNCIÓN PARA OBTENER GIROS COMERCIALES
// ============================================

function obtenerGirosComerciales($conn)
{
    $giros_comerciales = array();

    try {
        if (!$conn) {
            error_log("❌ Conexión no establecida en obtenerGirosComerciales");
            return $giros_comerciales;
        }

        $conn->select_db($GLOBALS['db_main']);

        $check_table = $conn->query("SHOW TABLES LIKE 'giro_comercial'");

        if ($check_table && $check_table->num_rows > 0) {
            $sql = "SELECT id, nombre FROM giro_comercial ORDER BY nombre ASC";
            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $giros_comerciales[] = array(
                        'id' => $row['id'],
                        'nombre' => $row['nombre']
                    );
                }
                $result->close();
            } else {
                error_log("⚠️ Tabla giro_comercial está vacía");
            }
        } else {
            error_log("❌ Tabla giro_comercial no existe en la BD");
        }

        if ($check_table) $check_table->close();
    } catch (Exception $e) {
        error_log("❌ Error en obtenerGirosComerciales: " . $e->getMessage());
    }

    return $giros_comerciales;
}

// ============================================
// CONEXIÓN A LA BASE DE DATOS PRINCIPAL
// ============================================

$conn_main = new mysqli($servername, $username, $password);

if ($conn_main->connect_error) {
    die("Error de conexión: " . $conn_main->connect_error);
}

$giros_comerciales = obtenerGirosComerciales($conn_main);

$sql_create_main_db = "CREATE DATABASE IF NOT EXISTS $db_main";
if ($conn_main->query($sql_create_main_db) === TRUE) {
    $conn_main->select_db($db_main);

    $sql_create_table = "CREATE TABLE IF NOT EXISTS empresas (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nombre_empresa VARCHAR(255) NOT NULL,
        giro_comercial VARCHAR(100) NOT NULL,
        rfc VARCHAR(13) DEFAULT NULL,
        no_distribuidor VARCHAR(7) DEFAULT NULL,
        telefono VARCHAR(20),
        direccion TEXT,
        nombre_contacto VARCHAR(100) NOT NULL,
        email_admin VARCHAR(100) NOT NULL,
        password_admin VARCHAR(255) NOT NULL,
        usuario_admin VARCHAR(100) NOT NULL,
        nombre_base_datos VARCHAR(100) NOT NULL UNIQUE,
        usuario_base_datos VARCHAR(100) NOT NULL,
        password_bd VARCHAR(255) NOT NULL,
        constancia_fiscal VARCHAR(255),
        credencial_identificacion VARCHAR(255) NOT NULL,
        fecha_subida_constancia DATETIME,
        fecha_subida_credencial DATETIME NOT NULL,
        declaracion_veracidad BOOLEAN NOT NULL DEFAULT FALSE,
        estado_verificacion ENUM('pendiente', 'en_revision', 'aprobado', 'rechazado') DEFAULT 'pendiente',
        observaciones_verificacion TEXT,
        fecha_verificacion DATETIME,
        correo_enviado BOOLEAN DEFAULT FALSE,
        fecha_envio_correo DATETIME,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_vencimiento DATE NOT NULL,
        activo BOOLEAN DEFAULT TRUE
    )";

    if (!$conn_main->query($sql_create_table)) {
        die("Error creando tabla: " . $conn_main->error);
    }

    $required_columns = [
        'giro_comercial' => "ALTER TABLE empresas ADD COLUMN giro_comercial VARCHAR(100) NOT NULL AFTER nombre_empresa",
        'usuario_base_datos' => "ALTER TABLE empresas ADD COLUMN usuario_base_datos VARCHAR(100) NOT NULL AFTER nombre_base_datos",
        'password_bd' => "ALTER TABLE empresas ADD COLUMN password_bd VARCHAR(255) NOT NULL AFTER usuario_base_datos",
        'email_admin' => "ALTER TABLE empresas ADD COLUMN email_admin VARCHAR(100) NOT NULL AFTER nombre_contacto",
        'usuario_admin' => "ALTER TABLE empresas ADD COLUMN usuario_admin VARCHAR(100) NOT NULL AFTER password_admin",
        'constancia_fiscal' => "ALTER TABLE empresas ADD COLUMN constancia_fiscal VARCHAR(255) AFTER password_bd",
        'credencial_identificacion' => "ALTER TABLE empresas ADD COLUMN credencial_identificacion VARCHAR(255) NOT NULL AFTER constancia_fiscal",
        'fecha_subida_constancia' => "ALTER TABLE empresas ADD COLUMN fecha_subida_constancia DATETIME AFTER credencial_identificacion",
        'fecha_subida_credencial' => "ALTER TABLE empresas ADD COLUMN fecha_subida_credencial DATETIME NOT NULL AFTER fecha_subida_constancia",
        'declaracion_veracidad' => "ALTER TABLE empresas ADD COLUMN declaracion_veracidad BOOLEAN NOT NULL DEFAULT FALSE AFTER fecha_subida_credencial",
        'estado_verificacion' => "ALTER TABLE empresas ADD COLUMN estado_verificacion ENUM('pendiente', 'en_revision', 'aprobado', 'rechazado') DEFAULT 'pendiente' AFTER declaracion_veracidad",
        'observaciones_verificacion' => "ALTER TABLE empresas ADD COLUMN observaciones_verificacion TEXT AFTER estado_verificacion",
        'fecha_verificacion' => "ALTER TABLE empresas ADD COLUMN fecha_verificacion DATETIME AFTER observaciones_verificacion",
        'correo_enviado' => "ALTER TABLE empresas ADD COLUMN correo_enviado BOOLEAN DEFAULT FALSE AFTER fecha_verificacion",
        'fecha_envio_correo' => "ALTER TABLE empresas ADD COLUMN fecha_envio_correo DATETIME AFTER correo_enviado",
        'fecha_vencimiento' => "ALTER TABLE empresas ADD COLUMN fecha_vencimiento DATE NOT NULL AFTER fecha_creacion",
        'no_distribuidor' => "ALTER TABLE empresas ADD COLUMN no_distribuidor VARCHAR(7) DEFAULT NULL AFTER rfc"
    ];

    foreach ($required_columns as $column_name => $alter_sql) {
        $check_column = $conn_main->query("SHOW COLUMNS FROM empresas LIKE '$column_name'");
        if ($check_column && $check_column->num_rows == 0) {
            if (!$conn_main->query($alter_sql)) {
                die("Error agregando columna $column_name: " . $conn_main->error);
            }
        }
        if ($check_column) $check_column->close();
    }
} else {
    die("Error creando base de datos principal: " . $conn_main->error);
}

// ============================================
// FUNCIONES AUXILIARES
// ============================================

function call_uapi($host, $cpanelUser, $apiToken, $module, $function, $params = [])
{
    $url = "https://{$host}:2083/execute/{$module}/{$function}";

    if (!empty($params)) {
        $url .= "?" . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: cpanel {$cpanelUser}:{$apiToken}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'cPanel API Client');

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ["status" => 0, "error" => "cURL error: $err"];
    }

    if ($http_code !== 200) {
        return ["status" => 0, "error" => "HTTP error: $http_code - Response: " . $response];
    }

    $decoded_response = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ["status" => 0, "error" => "Invalid JSON response: " . $response];
    }

    return $decoded_response;
}

function validarRFC($rfc)
{
    $rfc = strtoupper(trim($rfc));
    if (empty($rfc)) return true;
    $patron = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/';
    return preg_match($patron, $rfc);
}

function generarNombreBD($nombre_empresa)
{
    if (empty($nombre_empresa)) return 'empresa';
    $nombre_limpio = preg_replace('/[^a-zA-Z0-9_]/', '_', $nombre_empresa);
    $nombre_limpio = preg_replace('/_{2,}/', '_', $nombre_limpio);
    $nombre_limpio = trim($nombre_limpio, '_');
    $nombre_limpio = strtolower($nombre_limpio);
    if (strlen($nombre_limpio) > 30) {
        $nombre_limpio = substr($nombre_limpio, 0, 30);
    }
    return $nombre_limpio;
}

function generarNombreUsuarioBD($nombre_empresa)
{
    if (empty($nombre_empresa)) return 'admin';
    $usuario_limpio = preg_replace('/[^a-zA-Z0-9_]/', '_', $nombre_empresa);
    $usuario_limpio = preg_replace('/_{2,}/', '_', $usuario_limpio);
    $usuario_limpio = trim($usuario_limpio, '_');
    $usuario_limpio = strtolower($usuario_limpio);
    if (strlen($usuario_limpio) > 10) {
        $usuario_limpio = substr($usuario_limpio, 0, 10);
    }
    if (empty($usuario_limpio)) {
        $usuario_limpio = 'user';
    }
    return $usuario_limpio;
}

function generarPasswordSeguro()
{
    $length = 16;
    $chars = [
        'abcdefghijklmnopqrstuvwxyz',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        '0123456789',
        '!@#$%^&*()_+-=[]{}|;:,.<>?'
    ];
    $password = '';
    foreach ($chars as $charSet) {
        $password .= $charSet[random_int(0, strlen($charSet) - 1)];
    }
    $allChars = implode('', $chars);
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }
    $password = str_shuffle($password);
    return $password;
}

function validarArchivo($archivo)
{
    $max_size = 5 * 1024 * 1024;
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];

    $file_size = $archivo['size'];
    $file_type = mime_content_type($archivo['tmp_name']);
    $file_extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if ($file_size > $max_size) {
        return ['valido' => false, 'mensaje' => 'El archivo excede el tamaño máximo de 5MB'];
    }
    if (!in_array($file_type, $allowed_types)) {
        return ['valido' => false, 'mensaje' => 'Tipo de archivo no permitido. Solo PDF, JPG, JPEG, PNG'];
    }
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['valido' => false, 'mensaje' => 'Extensión de archivo no permitida. Solo .pdf, .jpg, .jpeg, .png'];
    }
    return ['valido' => true, 'mensaje' => 'Archivo válido'];
}

function subirArchivo($archivo, $nombre_empresa, $tipo, $upload_dir)
{
    if ($tipo === 'constancia') {
        $target_dir = $upload_dir . 'constancias/';
    } elseif ($tipo === 'credencial') {
        $target_dir = $upload_dir . 'credenciales/';
    } else {
        throw new Exception("Tipo de documento no válido");
    }

    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $timestamp = date('Ymd_His');
    $nombre_limpio = preg_replace('/[^a-zA-Z0-9_]/', '_', $nombre_empresa);
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $nombre_archivo = $tipo . '_' . $nombre_limpio . '_' . $timestamp . '.' . $extension;
    $ruta_completa = $target_dir . $nombre_archivo;

    if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
        return $nombre_archivo;
    } else {
        throw new Exception("Error al subir el archivo " . $tipo);
    }
}

function getDatabaseScript()
{
    return "
    CREATE TABLE `sucursales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `responsable` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `es_matriz` tinyint(1) DEFAULT '0',
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sucursales_nombre` (`nombre`)
);

    CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `rol` enum('admin','cajero','inventario') COLLATE utf8_unicode_ci DEFAULT 'cajero',
  `sucursal_id` int(11) NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_usuarios_sucursal` (`sucursal_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
);

    CREATE TABLE `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8_unicode_ci,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

    CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `rfc` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `tipo` enum('normal','frecuente','corporativo') COLLATE utf8_unicode_ci DEFAULT 'normal',
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ;

    CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `contacto` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `rfc` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proveedores_nombre` (`nombre`)
);

    CREATE TABLE `sistema_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_empresa` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'Mi Empresa',
  `rfc` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `logo` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `iva` decimal(5,2) DEFAULT '16.00',
  `moneda` varchar(10) COLLATE utf8_unicode_ci DEFAULT 'MXN',
  `notificaciones_stock` tinyint(1) DEFAULT '1',
  `stock_minimo_global` int(11) DEFAULT '5',
  `backup_automatico` tinyint(1) DEFAULT '0',
  `frecuencia_backup` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'diario',
  `ticket_empresa` tinyint(1) DEFAULT '1',
  `ticket_leyenda` text COLLATE utf8_unicode_ci,
  `color_primario` varchar(7) COLLATE utf8_unicode_ci DEFAULT '#27ae60',
  `color_secundario` varchar(7) COLLATE utf8_unicode_ci DEFAULT '#2ecc71',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

    CREATE TABLE `tipos_movimiento_caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `tipo` enum('ingreso','egreso') COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8_unicode_ci,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

    CREATE TABLE `caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha_apertura` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_cierre` timestamp NULL DEFAULT NULL,
  `monto_apertura` decimal(10,2) NOT NULL,
  `monto_cierre` decimal(10,2) DEFAULT '0.00',
  `monto_esperado` decimal(10,2) DEFAULT '0.00',
  `diferencia` decimal(10,2) DEFAULT '0.00',
  `estado` enum('abierta','cerrada') COLLATE utf8_unicode_ci DEFAULT 'abierta',
  `observaciones` text COLLATE utf8_unicode_ci,
  `ventas_efectivo` decimal(10,2) DEFAULT '0.00',
  `ventas_tarjeta` decimal(10,2) DEFAULT '0.00',
  `ventas_transferencia` decimal(10,2) DEFAULT '0.00',
  `total_ventas` decimal(10,2) DEFAULT '0.00',
  `otros_ingresos` decimal(10,2) DEFAULT '0.00',
  `otros_egresos` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `caja_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`),
  CONSTRAINT `caja_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
);
CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8_unicode_ci,
  `marca` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `subprecio` decimal(10,2) DEFAULT '0.00',
  `costo` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `stock` decimal(10,3) DEFAULT '0.000',
  `stock_minimo` decimal(10,3) DEFAULT '0.000',
  `categoria_id` int(11) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `imagen` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `unidad_medida` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'pieza',
  `tipo_producto` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'Estandar',
  `porcentaje_merma_danado` decimal(5,2) DEFAULT '0.00',
  `porcentaje_merma_deshidratacion` decimal(5,2) DEFAULT '0.00',
  `aplicar_merma_venta` tinyint(1) DEFAULT '0',
  `aplicar_merma_compra` tinyint(1) DEFAULT '0',
  `peso_kg` decimal(10,3) DEFAULT '1.000',
  `permite_fracciones` tinyint(1) DEFAULT '0',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fecha_caducidad` date DEFAULT NULL,
  `facturapi_producto_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `utilidad` decimal(5,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `categoria_id` (`categoria_id`),
  KEY `proveedor_id` (`proveedor_id`),
  KEY `idx_productos_codigo` (`codigo`),
  KEY `idx_productos_nombre` (`nombre`),
  KEY `idx_productos_marca` (`marca`),
  KEY `idx_productos_activo` (`activo`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `productos_ibfk_2` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`)
) ;

    CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo_venta` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `caja_id` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `iva` decimal(10,2) DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','tarjeta','transferencia') COLLATE utf8_unicode_ci DEFAULT 'efectivo',
  `estado` enum('pendiente','completada','cancelada') COLLATE utf8_unicode_ci DEFAULT 'completada',
  `observaciones` text COLLATE utf8_unicode_ci,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cambio` decimal(10,2) DEFAULT '0.00',
  `efectivo_recibido` decimal(10,2) DEFAULT '0.00',
  `urlfacturacion` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `facturapi_receipt_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_venta` (`codigo_venta`),
  KEY `cliente_id` (`cliente_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `idx_ventas_fecha` (`fecha`),
  KEY `idx_ventas_codigo` (`codigo_venta`),
  KEY `caja_id` (`caja_id`),
  CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `ventas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ventas_ibfk_3` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`),
  CONSTRAINT `ventas_ibfk_4` FOREIGN KEY (`caja_id`) REFERENCES `caja` (`id`)
);

CREATE TABLE `compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `numero_factura` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `iva` decimal(10,2) DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `estado` enum('pendiente','recibida','cancelada') COLLATE utf8_unicode_ci DEFAULT 'pendiente',
  `fecha_compra` date DEFAULT NULL,
  `fecha_recibo` date DEFAULT NULL,
  `observaciones` text COLLATE utf8_unicode_ci,
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `proveedor_id` (`proveedor_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `idx_compras_fecha` (`fecha_compra`),
  CONSTRAINT `compras_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compras_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `compras_ibfk_3` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
) ;

CREATE TABLE `compra_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `costo_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `compra_id` (`compra_id`),
  KEY `producto_id` (`producto_id`),
  KEY `idx_detalles_compra` (`compra_id`),
  KEY `idx_detalles_producto` (`producto_id`),
  KEY `idx_detalles_compra_producto` (`compra_id`,`producto_id`),
  CONSTRAINT `compra_detalles_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compra_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
);

    CREATE TABLE `producto_sucursal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `stock` decimal(10,3) DEFAULT '0.000',
  `stock_minimo` decimal(10,3) DEFAULT '0.000',
  `activo` tinyint(4) DEFAULT '1',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_producto_sucursal` (`producto_id`,`sucursal_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `idx_stock_sucursal` (`producto_id`,`sucursal_id`),
  CONSTRAINT `producto_sucursal_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `producto_sucursal_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
);

    CREATE TABLE `venta_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `cambio` decimal(10,2) DEFAULT '0.00',
  `unidad_medida` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'unidad',
  `efectivo_recibido` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `venta_detalles_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `venta_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
);

CREATE TABLE `compra_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `costo_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `compra_id` (`compra_id`),
  KEY `producto_id` (`producto_id`),
  KEY `idx_detalles_compra` (`compra_id`),
  KEY `idx_detalles_producto` (`producto_id`),
  KEY `idx_detalles_compra_producto` (`compra_id`,`producto_id`),
  CONSTRAINT `compra_detalles_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compra_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ;

CREATE TABLE `movimientos_caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `caja_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `tipo` enum('ingreso','egreso') COLLATE utf8_unicode_ci NOT NULL,
  `concepto` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','tarjeta','transferencia') COLLATE utf8_unicode_ci DEFAULT 'efectivo',
  `referencia_id` int(11) DEFAULT NULL,
  `referencia_tipo` enum('venta','compra','gasto','otros') COLLATE utf8_unicode_ci DEFAULT 'otros',
  `observaciones` text COLLATE utf8_unicode_ci,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `caja_id` (`caja_id`),
  KEY `sucursal_id` (`sucursal_id`),
  CONSTRAINT `movimientos_caja_ibfk_1` FOREIGN KEY (`caja_id`) REFERENCES `caja` (`id`),
  CONSTRAINT `movimientos_caja_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
);

    CREATE TABLE `movimientos_inventario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `tipo` enum('entrada','salida','ajuste') COLLATE utf8_unicode_ci NOT NULL,
  `cantidad` int(11) NOT NULL,
  `cantidad_anterior` int(11) NOT NULL,
  `cantidad_nueva` int(11) NOT NULL,
  `referencia_id` int(11) DEFAULT NULL,
  `referencia_tipo` enum('venta','compra','ajuste') COLLATE utf8_unicode_ci NOT NULL,
  `observaciones` text COLLATE utf8_unicode_ci,
  `usuario_id` int(11) NOT NULL,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_movimientos_fecha` (`fecha`),
  CONSTRAINT `movimientos_inventario_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `movimientos_inventario_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`),
  CONSTRAINT `movimientos_inventario_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
);

CREATE TABLE `producto_imagenes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `ruta_imagen` varchar(500) NOT NULL,
  `orden` int(11) DEFAULT '0',
  `es_principal` tinyint(1) DEFAULT '0',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_producto` (`producto_id`),
  CONSTRAINT `producto_imagenes_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
);

CREATE TABLE `producto_precios_mayoreo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `cantidad_minima` decimal(10,2) NOT NULL,
  `precio_especial` decimal(10,2) NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `producto_precios_mayoreo_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
);

    CREATE VIEW vista_usuarios AS
    SELECT 
        u.*,
        s.nombre as sucursal_nombre,
        s.es_matriz as sucursal_es_matriz
    FROM usuarios u
    LEFT JOIN sucursales s ON u.sucursal_id = s.id;

    CREATE VIEW vista_productos AS
    SELECT 
        p.*,
        c.nombre as categoria_nombre,
        pr.nombre as proveedor_nombre,
        pr.telefono as proveedor_telefono
    FROM productos p
    LEFT JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN proveedores pr ON p.proveedor_id = pr.id;

    CREATE VIEW vista_ventas AS
    SELECT 
        v.*,
        c.nombre as cliente_nombre,
        c.telefono as cliente_telefono,
        u.nombre as usuario_nombre,
        s.nombre as sucursal_nombre
    FROM ventas v
    LEFT JOIN clientes c ON v.cliente_id = c.id
    LEFT JOIN usuarios u ON v.usuario_id = u.id
    LEFT JOIN sucursales s ON v.sucursal_id = s.id;

    CREATE VIEW vista_inventario_bajo AS
    SELECT 
        p.id,
        p.codigo,
        p.nombre,
        p.stock,
        p.stock_minimo,
        c.nombre as categoria,
        pr.nombre as proveedor
    FROM productos p
    LEFT JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
    WHERE p.stock <= p.stock_minimo AND p.activo = TRUE;
    ";
}

function ejecutarScriptSQL($connection, $sql_script)
{
    $queries = array_filter(array_map('trim', explode(';', $sql_script)));

    foreach ($queries as $query) {
        if (!empty($query)) {
            if (stripos($query, 'CREATE INDEX') === 0) {
                if (!$connection->query($query)) {
                    if ($connection->errno != 1061) {
                        error_log("Error creando índice: " . $connection->error . " - Query: " . $query);
                    }
                }
            } else {
                if (!$connection->query($query)) {
                    error_log("Error en consulta: " . $connection->error . " - Query: " . $query);
                    return false;
                }
            }
        }
    }
    return true;
}

// ============================================
// FUNCIÓN PARA ENVIAR CORREO CON CREDENCIALES
// ============================================

function enviarCorreoCredenciales($destinatario, $nombre_empresa, $nombre_contacto, $usuario_admin, $password_admin, $email_admin, $nombre_base_datos, $usuario_base_datos, $password_bd)
{
    try {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $phpmailer_path = __DIR__ . '/vendor/autoload.php';
            if (file_exists($phpmailer_path)) {
                require_once $phpmailer_path;
            } else {
                $phpmailer_path = __DIR__ . '/PHPMailer/src/PHPMailer.php';
                if (file_exists($phpmailer_path)) {
                    require_once $phpmailer_path;
                    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
                    require_once __DIR__ . '/PHPMailer/src/Exception.php';
                } else {
                    error_log("PHPMailer no encontrado");
                    return false;
                }
            }
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $GLOBALS['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $GLOBALS['smtp_username'];
        $mail->Password = $GLOBALS['smtp_password'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $GLOBALS['smtp_port'];

        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom('notificaciones@libertyfin.com.mx', 'LibertyFin');
        $mail->addAddress($email_admin, $nombre_contacto);

        $logo_path = __DIR__ . '/images/LibertyfinBlanco.png';
        if (file_exists($logo_path)) {
            $mail->addEmbeddedImage($logo_path, 'logo', 'LibertyfinBlanco.png');
        }

        $mail->Subject = '¡Bienvenido a LibertyFin! - Tus credenciales de acceso';

        $mail->isHTML(true);
        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #27ae60; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .credentials { background: white; padding: 15px; border: 1px solid #ddd; margin: 15px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>¡Bienvenido a LibertyFin!</h1>
                    <p>Tu sistema de gestión empresarial</p>
                </div>
                <div class="content">
                    <h2>Hola ' . htmlspecialchars($nombre_contacto) . ',</h2>
                    <p>Tu empresa <strong>' . htmlspecialchars($nombre_empresa) . '</strong> ha sido registrada exitosamente.</p>
                    
                    <div class="credentials">
                        <h3>🔐 Tus credenciales de acceso:</h3>
                        <p><strong>URL de acceso:</strong> https://libertyfin.com.mx/login.php</p>
                        <p><strong>Email:</strong> ' . htmlspecialchars($email_admin) . '</p>
                        <p><strong>Contraseña:</strong> ' . htmlspecialchars($password_admin) . '</p>
                        <p><strong>Periodo de prueba:</strong> 15 días</p>
                    </div>
                    
                    <p><strong>Recomendaciones de seguridad:</strong></p>
                    <ul>
                        <li>Guarda estas credenciales en un lugar seguro</li>
                        <li>Cambia tu contraseña después del primer acceso</li>
                        <li>No compartas tus datos de acceso</li>
                    </ul>
                    
                    <p><strong>Soporte técnico:</strong></p>
                    <p>Email: contacto@libertyfin.com.mx<br>
                    Teléfono: (55) 1234 5678</p>
                    
                    <p><a href="https://libertyfin.com.mx/Login" style="background: #27ae60; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Iniciar sesión ahora</a></p>
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' LibertyFin - Todos los derechos reservados</p>
                    <p>Este es un correo automático, por favor no respondas a este mensaje.</p>
                </div>
            </div>
        </body>
        </html>';

        $mail->AltBody = "¡BIENVENIDO A LIBERTYFIN!\n\n" .
            "Hola " . $nombre_contacto . ",\n\n" .
            "Tu empresa " . $nombre_empresa . " ha sido registrada exitosamente.\n\n" .
            "TUS CREDENCIALES DE ACCESO:\n" .
            "============================\n" .
            "URL: https://libertyfin.com.mx/login.php\n" .
            "Email: " . $email_admin . "\n" .
            "Contraseña: " . $password_admin . "\n\n" .
            "Periodo de prueba: 15 días\n\n" .
            "SOPORTE:\n" .

            "Email: soporte@libertyfin.com.mx\n" .
            "Teléfono: (55) 1234-5678\n\n" .
            "© " . date('Y') . " LibertyFin";

        if ($mail->send()) {
            error_log("✅ Correo enviado exitosamente a: " . $email_admin);
            return true;
        } else {
            error_log("❌ Error al enviar correo: " . $mail->ErrorInfo);
            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Excepción al enviar correo: " . $e->getMessage());
        return false;
    }
}

// ============================================
// PROCESAMIENTO DEL FORMULARIO DE REGISTRO
// ============================================

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_empresa = isset($_POST['nombre_empresa']) ? trim($_POST['nombre_empresa']) : '';
    $giro_comercial = isset($_POST['giro_comercial']) ? trim($_POST['giro_comercial']) : '';
    $rfc = '';
    $no_distribuidor = '';
    $telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
    $direccion = '';
    $nombre_contacto = isset($_POST['nombre_contacto']) ? trim($_POST['nombre_contacto']) : '';
    $apellido_contacto = isset($_POST['apellido_contacto']) ? trim($_POST['apellido_contacto']) : '';
    $email_admin = isset($_POST['email']) ? trim($_POST['email']) : '';
    $cantidad_empleados = isset($_POST['cantidad_empleados']) ? trim($_POST['cantidad_empleados']) : '';

    $nombre_contacto_completo = $nombre_contacto . ' ' . $apellido_contacto;

    // CONTRASEÑA FIJA PARA EL ADMINISTRADOR
    $password = '1bertyf1n@2026.#';

    $declaracion_veracidad = true;

    $constancia_fiscal = null;
    $credencial_identificacion = null;

    $usuario_admin = generarNombreUsuarioBD($nombre_empresa) . "_admin";
    $nombre_base_datos = $cpanel_user . "_" . generarNombreBD($nombre_empresa);
    $usuario_base_datos = $cpanel_user . "_" . generarNombreUsuarioBD($nombre_empresa);

    // Contraseña para la base de datos (puedes mantener aleatoria o fijarla también)
    $password_base_datos = generarPasswordSeguro(); // O pon otra fija si lo prefieres

    $password_admin_hash = password_hash($password, PASSWORD_DEFAULT);
    $fecha_vencimiento = date('Y-m-d', strtotime('+15 days'));

    if (
        empty($nombre_empresa) || empty($giro_comercial) ||
        empty($nombre_contacto) || empty($apellido_contacto) || empty($email_admin) ||
        empty($telefono) || empty($cantidad_empleados)
    ) {
        $mensaje = "Todos los campos obligatorios deben ser completados.";
        $tipo_mensaje = "danger";
    } elseif (!filter_var($email_admin, FILTER_VALIDATE_EMAIL)) {
        $mensaje = "Por favor, ingrese una dirección de correo electrónico válida.";
        $tipo_mensaje = "danger";
    } else {
        try {
            $conn_main->begin_transaction();

            $sql_check = "SELECT id FROM empresas WHERE nombre_empresa = ? OR email_admin = ?";
            $stmt_check = $conn_main->prepare($sql_check);
            $stmt_check->bind_param("ss", $nombre_empresa, $email_admin);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                throw new Exception("El nombre de empresa o correo electrónico ya está registrado.");
            }
            $stmt_check->close();

            $now = date('Y-m-d H:i:s');

            // Archivos dummy para cumplir con la estructura (opcionales en tu proceso actual)
            $credencial_identificacion = 'pendiente_validacion_' . time() . '.jpg';
            $fecha_credencial = $now;

            error_log("Creando base de datos: " . $nombre_base_datos);
            $result_db = call_uapi($cpanel_host, $cpanel_user, $cpanel_api_token, "Mysql", "create_database", [
                "name" => $nombre_base_datos
            ]);

            if (!$result_db || $result_db["status"] != 1) {
                $error_msg = isset($result_db["errors"]) ? json_encode($result_db["errors"]) : ($result_db["error"] ?? "Error desconocido");
                throw new Exception("Error creando base de datos: " . $error_msg);
            }

            error_log("Creando usuario MySQL: " . $usuario_base_datos);
            $result_user = call_uapi($cpanel_host, $cpanel_user, $cpanel_api_token, "Mysql", "create_user", [
                "name" => $usuario_base_datos,
                "password" => $password_base_datos
            ]);

            if (!$result_user || $result_user["status"] != 1) {
                $error_msg = isset($result_user["errors"]) ? json_encode($result_user["errors"]) : ($result_user["error"] ?? "Error desconocido");
                throw new Exception("Error creando usuario MySQL: " . $error_msg);
            }

            error_log("Asignando privilegios para usuario: " . $usuario_base_datos . " en BD: " . $nombre_base_datos);
            $result_priv = call_uapi($cpanel_host, $cpanel_user, $cpanel_api_token, "Mysql", "set_privileges_on_database", [
                "user" => $usuario_base_datos,
                "database" => $nombre_base_datos,
                "privileges" => "ALL PRIVILEGES"
            ]);

            if (!$result_priv || $result_priv["status"] != 1) {
                $error_msg = isset($result_priv["errors"]) ? json_encode($result_priv["errors"]) : ($result_priv["error"] ?? "Error desconocido");
                throw new Exception("Error asignando privilegios al usuario específico: " . $error_msg);
            }

            error_log("Asignando privilegios para usuario principal: juanc141_alexis en BD: " . $nombre_base_datos);
            $result_priv_principal = call_uapi($cpanel_host, $cpanel_user, $cpanel_api_token, "Mysql", "set_privileges_on_database", [
                "user" => "juanc141_alexis",
                "database" => $nombre_base_datos,
                "privileges" => "ALL PRIVILEGES"
            ]);

            if (!$result_priv_principal || $result_priv_principal["status"] != 1) {
                $error_msg = isset($result_priv_principal["errors"]) ? json_encode($result_priv_principal["errors"]) : ($result_priv_principal["error"] ?? "Error desconocido");
                throw new Exception("Error asignando privilegios al usuario principal: " . $error_msg);
            }

            $sql_insert_empresa = "INSERT INTO empresas (
                nombre_empresa, giro_comercial, rfc, no_distribuidor, telefono, direccion, 
                nombre_contacto, email_admin, password_admin, usuario_admin, nombre_base_datos, 
                usuario_base_datos, password_bd, constancia_fiscal, credencial_identificacion, 
                fecha_subida_constancia, fecha_subida_credencial, declaracion_veracidad, fecha_vencimiento
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn_main->prepare($sql_insert_empresa);

            $direccion_vacia = '';
            $rfc_vacio = '';
            $no_distribuidor_vacio = '';
            $fecha_constancia = NULL;

            $stmt->bind_param(
                "sssssssssssssssssss",
                $nombre_empresa,
                $giro_comercial,
                $rfc_vacio,
                $no_distribuidor_vacio,
                $telefono,
                $direccion_vacia,
                $nombre_contacto_completo,
                $email_admin,
                $password_admin_hash,
                $usuario_admin,
                $nombre_base_datos,
                $usuario_base_datos,
                $password_base_datos,
                $constancia_fiscal,
                $credencial_identificacion,
                $fecha_constancia,
                $fecha_credencial,
                $declaracion_veracidad,
                $fecha_vencimiento
            );

            if (!$stmt->execute()) {
                throw new Exception("Error al registrar la empresa: " . $conn_main->error);
            }

            $empresa_id = $stmt->insert_id;

            $conn_empresa = new mysqli($servername, $usuario_base_datos, $password_base_datos, $nombre_base_datos);

            if ($conn_empresa->connect_error) {
                throw new Exception("Error conectando a la nueva base de datos: " . $conn_empresa->connect_error);
            }

            $script_sql = getDatabaseScript();
            if (!ejecutarScriptSQL($conn_empresa, $script_sql)) {
                throw new Exception("Error al crear las tablas en la base de datos.");
            }

            $sql_config = "INSERT INTO sistema_config (nombre_empresa, telefono) VALUES (?, ?)";
            $stmt_config = $conn_empresa->prepare($sql_config);
            $stmt_config->bind_param("ss", $nombre_empresa, $telefono);
            $stmt_config->execute();
            $stmt_config->close();

            $nombre_sucursal = "Matriz";
            $sql_sucursal = "INSERT INTO sucursales (nombre, es_matriz, activo) VALUES (?, TRUE, TRUE)";
            $stmt_sucursal = $conn_empresa->prepare($sql_sucursal);
            $stmt_sucursal->bind_param("s", $nombre_sucursal);
            $stmt_sucursal->execute();
            $sucursal_id = $stmt_sucursal->insert_id;
            $stmt_sucursal->close();

            $sql_usuario = "INSERT INTO usuarios (username, password, nombre, email, rol, sucursal_id) VALUES (?, ?, ?, ?, 'admin', ?)";
            $stmt_usuario = $conn_empresa->prepare($sql_usuario);
            $stmt_usuario->bind_param("ssssi", $usuario_admin, $password_admin_hash, $nombre_contacto_completo, $email_admin, $sucursal_id);
            $stmt_usuario->execute();
            $stmt_usuario->close();

            $conn_empresa->close();

            $correo_enviado = enviarCorreoCredenciales($email_admin, $nombre_empresa, $nombre_contacto_completo, $usuario_admin, $password, $email_admin, $nombre_base_datos, $usuario_base_datos, $password_base_datos);

            if ($correo_enviado) {
                $sql_update_correo = "UPDATE empresas SET correo_enviado = TRUE, fecha_envio_correo = NOW() WHERE id = ?";
                $stmt_update = $conn_main->prepare($sql_update_correo);
                $stmt_update->bind_param("i", $empresa_id);
                $stmt_update->execute();
                $stmt_update->close();
            }

            $conn_main->commit();
            $conn_main->close();

            // ============================================
            // REDIRIGIR A LA PÁGINA DE REGISTRO CON ÉXITO
            // ============================================
            header("Location: pages/registro.php?success=1");
            exit();
        } catch (Exception $e) {
            $conn_main->rollback();
            $mensaje = $e->getMessage();
            $tipo_mensaje = "danger";
            error_log("Error en registro de empresa: " . $e->getMessage());

            header("Location: pages/registro.php?error=" . urlencode($mensaje));
            exit();
        }
    }

    if (!empty($mensaje) && $tipo_mensaje == "danger") {
        header("Location: pages/registro.php?error=" . urlencode($mensaje));
        exit();
    }
}

$conn_main->close();
