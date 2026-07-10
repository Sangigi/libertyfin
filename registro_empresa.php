<?php
// Configuración de la base de datos principal
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$db_main = "juanc141_ventas";

// Configuración cPanel API
$cpanel_host = "libertyfin.com.mx";
$cpanel_user = "juanc141";
$cpanel_api_token = "4KGLQYQZ3E7A52QI7EK20HFZCE7UD7S9";

// Crear conexión a la base de datos principal
$conn_main = new mysqli($servername, $username, $password);

// Verificar conexión
if ($conn_main->connect_error) {
    die("Error de conexión: " . $conn_main->connect_error);
}

// Crear base de datos principal si no existe
$sql_create_main_db = "CREATE DATABASE IF NOT EXISTS $db_main";
if ($conn_main->query($sql_create_main_db) === TRUE) {
    // Seleccionar la base de datos principal
    $conn_main->select_db($db_main);

    // Crear tabla de empresas si no existe
    $sql_create_table = "CREATE TABLE IF NOT EXISTS empresas (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nombre_empresa VARCHAR(255) NOT NULL,
        rfc VARCHAR(13) DEFAULT NULL,
        telefono VARCHAR(20),
        email VARCHAR(100) NOT NULL,
        direccion TEXT,
        nombre_contacto VARCHAR(100) NOT NULL,
        usuario_admin VARCHAR(50) NOT NULL,
        password_admin VARCHAR(255) NOT NULL,
        nombre_base_datos VARCHAR(100) NOT NULL UNIQUE,
        usuario_base_datos VARCHAR(100) NOT NULL,
        password_bd VARCHAR(255) NOT NULL,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        activo BOOLEAN DEFAULT TRUE
    )";

    if (!$conn_main->query($sql_create_table)) {
        die("Error creando tabla: " . $conn_main->error);
    }

    // Verificar y agregar columnas faltantes si es necesario
    $required_columns = [
        'usuario_base_datos' => "ALTER TABLE empresas ADD COLUMN usuario_base_datos VARCHAR(100) NOT NULL AFTER nombre_base_datos",
        'password_bd' => "ALTER TABLE empresas ADD COLUMN password_bd VARCHAR(255) NOT NULL AFTER usuario_base_datos"
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

// Función para llamar a la API de cPanel
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

// Procesar formulario de registro
$mensaje = "";
$tipo_mensaje = "";
$registro_exitoso = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Recoger datos del formulario
    $nombre_empresa = isset($_POST['nombre_empresa']) ? trim($_POST['nombre_empresa']) : '';
    $rfc = isset($_POST['rfc']) ? strtoupper(trim($_POST['rfc'])) : '';
    $telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $direccion = isset($_POST['direccion']) ? trim($_POST['direccion']) : '';
    $nombre_contacto = isset($_POST['nombre_contacto']) ? trim($_POST['nombre_contacto']) : '';
    $usuario_admin = isset($_POST['usuario_admin']) ? trim($_POST['usuario_admin']) : '';
    $password = isset($_POST['password_admin']) ? $_POST['password_admin'] : '';

    // Generar nombres CON el prefijo requerido
    $nombre_base_datos = $cpanel_user . "_" . generarNombreBD($nombre_empresa);

    // Crear un usuario específico para cada empresa
    $usuario_base_datos = $cpanel_user . "_" . generarNombreUsuarioBD($nombre_empresa);

    // Generar contraseña segura para la base de datos
    $password_base_datos = generarPasswordSeguro();

    // Aplicar hash a la contraseña del administrador del sistema
    $password_admin_hash = password_hash($password, PASSWORD_DEFAULT);

    // Validar datos
    if (empty($nombre_empresa) || empty($email) || empty($nombre_contacto) || empty($usuario_admin) || empty($password)) {
        $mensaje = "Todos los campos obligatorios deben ser completados.";
        $tipo_mensaje = "danger";
    } elseif (!empty($rfc) && !validarRFC($rfc)) {
        $mensaje = "El RFC ingresado no es válido.";
        $tipo_mensaje = "danger";
    } else {
        try {
            // Iniciar transacción
            $conn_main->begin_transaction();

            // Verificar si la empresa ya existe
            $sql_check = "SELECT id FROM empresas WHERE nombre_empresa = ? OR usuario_admin = ? OR nombre_base_datos = ?";
            $stmt_check = $conn_main->prepare($sql_check);
            $stmt_check->bind_param("sss", $nombre_empresa, $usuario_admin, $nombre_base_datos);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                throw new Exception("El nombre de empresa, usuario o base de datos ya está registrado.");
            }
            $stmt_check->close();

            // PASO 1: Crear base de datos via cPanel API
            error_log("Creando base de datos: " . $nombre_base_datos);
            $result_db = call_uapi($cpanel_host, $cpanel_user, $cpanel_api_token, "Mysql", "create_database", [
                "name" => $nombre_base_datos
            ]);

            if (!$result_db || $result_db["status"] != 1) {
                $error_msg = isset($result_db["errors"]) ? json_encode($result_db["errors"]) : ($result_db["error"] ?? "Error desconocido");
                throw new Exception("Error creando base de datos: " . $error_msg);
            }

            // PASO 2: Crear usuario MySQL específico para esta empresa
            error_log("Creando usuario MySQL: " . $usuario_base_datos);
            $result_user = call_uapi($cpanel_host, $cpanel_user, $cpanel_api_token, "Mysql", "create_user", [
                "name" => $usuario_base_datos,
                "password" => $password_base_datos
            ]);

            if (!$result_user || $result_user["status"] != 1) {
                $error_msg = isset($result_user["errors"]) ? json_encode($result_user["errors"]) : ($result_user["error"] ?? "Error desconocido");
                throw new Exception("Error creando usuario MySQL: " . $error_msg);
            }

            // PASO 3: Asignar privilegios al usuario específico de la empresa via cPanel API
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

            // PASO 4: Asignar privilegios al usuario principal juanc141_alexis via cPanel API
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

            // Insertar empresa en la base de datos principal CON LA CONTRASEÑA DE BD SIN HASHEAR
            $sql_insert_empresa = "INSERT INTO empresas (nombre_empresa, rfc, telefono, email, direccion, nombre_contacto, usuario_admin, password_admin, nombre_base_datos, usuario_base_datos, password_bd) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn_main->prepare($sql_insert_empresa);
            $stmt->bind_param("sssssssssss", $nombre_empresa, $rfc, $telefono, $email, $direccion, $nombre_contacto, $usuario_admin, $password_admin_hash, $nombre_base_datos, $usuario_base_datos, $password_base_datos);

            if (!$stmt->execute()) {
                throw new Exception("Error al registrar la empresa: " . $conn_main->error);
            }

            // Conectar usando el usuario específico de la empresa
            $conn_empresa = new mysqli($servername, $usuario_base_datos, $password_base_datos, $nombre_base_datos);

            if ($conn_empresa->connect_error) {
                throw new Exception("Error conectando a la nueva base de datos: " . $conn_empresa->connect_error);
            }

            // Ejecutar script para crear tablas
            $script_sql = getDatabaseScript();
            if (!ejecutarScriptSQL($conn_empresa, $script_sql)) {
                throw new Exception("Error al crear las tablas en la base de datos.");
            }

            // Insertar configuración de la empresa
            $sql_config = "INSERT INTO sistema_config (nombre_empresa, rfc, telefono, email, direccion) VALUES (?, ?, ?, ?, ?)";
            $stmt_config = $conn_empresa->prepare($sql_config);
            $stmt_config->bind_param("sssss", $nombre_empresa, $rfc, $telefono, $email, $direccion);
            $stmt_config->execute();
            $stmt_config->close();

            // Insertar sucursal matriz automáticamente
            $nombre_sucursal = "Matriz";
            $direccion_sucursal = $direccion;
            $telefono_sucursal = $telefono;

            $sql_sucursal = "INSERT INTO sucursales (nombre, direccion, telefono, es_matriz, activo) VALUES (?, ?, ?, TRUE, TRUE)";
            $stmt_sucursal = $conn_empresa->prepare($sql_sucursal);
            $stmt_sucursal->bind_param("sss", $nombre_sucursal, $direccion_sucursal, $telefono_sucursal);
            $stmt_sucursal->execute();
            $sucursal_id = $stmt_sucursal->insert_id;
            $stmt_sucursal->close();

            // Crear usuario administrador asignado a la sucursal matriz (CON HASH)
            $sql_usuario = "INSERT INTO usuarios (username, password, nombre, email, rol, sucursal_id) VALUES (?, ?, ?, ?, 'admin', ?)";
            $stmt_usuario = $conn_empresa->prepare($sql_usuario);
            $stmt_usuario->bind_param("ssssi", $usuario_admin, $password_admin_hash, $nombre_contacto, $email, $sucursal_id);
            $stmt_usuario->execute();
            $stmt_usuario->close();

            // Cerrar conexión a la base de datos de la empresa
            $conn_empresa->close();

            // Confirmar transacción
            $conn_main->commit();

            $mensaje = "¡Empresa registrada exitosamente!<br>
                       <strong>Base de datos:</strong> $nombre_base_datos<br>
                       <strong>Usuario BD específico:</strong> $usuario_base_datos<br>
                       <strong>Contraseña BD específico:</strong> $password_base_datos<br>
                       <strong>Usuario BD principal:</strong> juanc141_alexis (con todos los privilegios)<br>
                       <strong>Usuario Admin Sistema:</strong> $usuario_admin<br>
                       <strong>Contraseña Admin Sistema:</strong> [Protegida con hash]<br>
                       <strong>Contraseña BD guardada:</strong> En campo password_bd (sin hashear)<br>
                       Se ha creado la sucursal matriz y todas las tablas necesarias.";
            $tipo_mensaje = "success";
            $registro_exitoso = true;

            // Limpiar POST después de registro exitoso
            $_POST = array();

            $stmt->close();
        } catch (Exception $e) {
            // Revertir transacción en caso de error
            $conn_main->rollback();
            $mensaje = $e->getMessage();
            $tipo_mensaje = "danger";

            // Log del error para debugging
            error_log("Error en registro de empresa: " . $e->getMessage());
        }
    }
}

// Funciones auxiliares
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

    // Limitar longitud y SIN código aleatorio
    if (strlen($nombre_limpio) > 30) {
        $nombre_limpio = substr($nombre_limpio, 0, 30);
    }

    return $nombre_limpio;
}

function generarNombreUsuarioBD($nombre_empresa)
{
    if (empty($nombre_empresa)) return 'admin';

    // Limpiar el nombre para usuario de BD
    $usuario_limpio = preg_replace('/[^a-zA-Z0-9_]/', '_', $nombre_empresa);
    $usuario_limpio = preg_replace('/_{2,}/', '_', $usuario_limpio);
    $usuario_limpio = trim($usuario_limpio, '_');
    $usuario_limpio = strtolower($usuario_limpio);

    // Limitar a 10 caracteres máximo (considerando que el prefijo "juanc141_" ya tiene 9 caracteres)
    if (strlen($usuario_limpio) > 10) {
        $usuario_limpio = substr($usuario_limpio, 0, 10);
    }

    // Asegurar que el nombre de usuario no esté vacío después de la limpieza
    if (empty($usuario_limpio)) {
        $usuario_limpio = 'user';
    }

    return $usuario_limpio;
}

function generarPasswordSeguro()
{
    // Generar una contraseña más segura que cumpla con los requisitos de cPanel
    $length = 16;
    $chars = [
        'abcdefghijklmnopqrstuvwxyz',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        '0123456789',
        '!@#$%^&*()_+-=[]{}|;:,.<>?'
    ];

    $password = '';

    // Asegurar al menos un carácter de cada tipo
    foreach ($chars as $charSet) {
        $password .= $charSet[random_int(0, strlen($charSet) - 1)];
    }

    // Completar el resto de la contraseña
    $allChars = implode('', $chars);
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }

    // Mezclar los caracteres
    $password = str_shuffle($password);

    return $password;
}

// Función para obtener el script SQL de la base de datos (CORREGIDO - SIN IF NOT EXISTS EN ÍNDICES)
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
);

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

-- Tablas con dependencias básicas
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
    `stock` int(11) DEFAULT '0',
    `stock_minimo` int(11) DEFAULT '5',
    `categoria_id` int(11) DEFAULT NULL,
    `proveedor_id` int(11) DEFAULT NULL,
    `imagen` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
    `activo` tinyint(1) DEFAULT '1',
    `unidad_medida` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'pieza',
    `peso_kg` decimal(10,3) DEFAULT '1.000',
    `permite_fracciones` tinyint(1) DEFAULT '0',
    `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `fecha_caducidad` date DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `codigo` (`codigo`),
    KEY `categoria_id` (`categoria_id`),
    KEY `proveedor_id` (`proveedor_id`),
    KEY `idx_productos_codigo` (`codigo`),
    KEY `idx_productos_nombre` (`nombre`),
    KEY `idx_productos_marca` (`marca`),
    CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
    CONSTRAINT `productos_ibfk_2` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`)
);

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
    CONSTRAINT `compras_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`),
    CONSTRAINT `compras_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
    CONSTRAINT `compras_ibfk_3` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
);

-- Tablas con múltiples dependencias
CREATE TABLE `producto_sucursal` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `producto_id` int(11) NOT NULL,
    `sucursal_id` int(11) NOT NULL,
    `stock` int(11) DEFAULT '0',
    `stock_minimo` int(11) DEFAULT '0',
    `activo` tinyint(4) DEFAULT '1',
    `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_producto_sucursal` (`producto_id`,`sucursal_id`),
    KEY `sucursal_id` (`sucursal_id`),
    CONSTRAINT `producto_sucursal_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
    CONSTRAINT `producto_sucursal_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
);

CREATE TABLE `venta_detalles` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `venta_id` int(11) NOT NULL,
    `producto_id` int(11) NOT NULL,
    `cantidad` int(11) NOT NULL,
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
    `cantidad` int(11) NOT NULL,
    `costo_unitario` decimal(10,2) NOT NULL,
    `subtotal` decimal(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `compra_id` (`compra_id`),
    KEY `producto_id` (`producto_id`),
    CONSTRAINT `compra_detalles_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
    CONSTRAINT `compra_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
);

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

-- Vistas del sistema

-- Vista para usuarios con información de sucursal
CREATE VIEW vista_usuarios AS
SELECT 
    u.*,
    s.nombre as sucursal_nombre,
    s.es_matriz as sucursal_es_matriz
FROM usuarios u
LEFT JOIN sucursales s ON u.sucursal_id = s.id;

-- Vista para productos con información de categoría, proveedor y sucursal
CREATE VIEW vista_productos AS
SELECT 
    p.*,
    c.nombre as categoria_nombre,
    pr.nombre as proveedor_nombre,
    pr.telefono as proveedor_telefono
FROM productos p
LEFT JOIN categorias c ON p.categoria_id = c.id
LEFT JOIN proveedores pr ON p.proveedor_id = pr.id;

-- Vista para ventas con información de cliente, usuario y sucursal
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

-- Vista para inventario bajo por sucursal
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

// Función para ejecutar el script SQL
function ejecutarScriptSQL($connection, $sql_script)
{
    // Dividir el script en consultas individuales
    $queries = array_filter(array_map('trim', explode(';', $sql_script)));

    foreach ($queries as $query) {
        if (!empty($query)) {
            // Si es un CREATE INDEX, intentamos ejecutarlo y si falla (porque ya existe), continuamos
            if (stripos($query, 'CREATE INDEX') === 0) {
                if (!$connection->query($query)) {
                    // Si el error es por índice duplicado, continuamos silenciosamente
                    if ($connection->errno != 1061) { // 1061 = Duplicate key name
                        error_log("Error creando índice: " . $connection->error . " - Query: " . $query);
                        // No retornamos false aquí, continuamos con las demás consultas
                    }
                }
            } else {
                // Para otras consultas, manejamos normalmente
                if (!$connection->query($query)) {
                    error_log("Error en consulta: " . $connection->error . " - Query: " . $query);
                    return false;
                }
            }
        }
    }
    return true;
}

$conn_main->close();
