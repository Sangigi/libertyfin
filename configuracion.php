<?php
session_start();

// Verificar si el usuario está logueado y es admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$conn_main = getDBConnection();

// Valores por defecto
$empresa_plan = "prueba";
$timbres_totales = 0;
$timbres_disponibles = 0;

if ($conn_main) {
    $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([$_SESSION['empresa_id']]);
    $result_empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);

    if ($result_empresa) {
        $empresa_plan = $result_empresa['plan'];
        $timbres_totales = $result_empresa['timbres_totales'] ?? 0;
        $timbres_disponibles = $result_empresa['timbres_disponibles'] ?? 0;
    }
    $stmt_empresa = null;
    $conn_main = null;
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

$mensaje = '';
$tipo_mensaje = '';

// Conectar a la base de datos de la empresa
try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Verificar y actualizar la estructura de la tabla sistema_config
    $sql_check_columns = "SHOW COLUMNS FROM sistema_config";
    $result_columns = $conn->query($sql_check_columns);
    $existing_columns = [];
    while ($row = $result_columns->fetch(PDO::FETCH_ASSOC)) {
        $existing_columns[] = $row['Field'];
    }

    // Columnas que necesitamos agregar
    $new_columns = [
        "notificaciones_stock" => "ALTER TABLE sistema_config ADD COLUMN notificaciones_stock BOOLEAN DEFAULT 1",
        "stock_minimo_global" => "ALTER TABLE sistema_config ADD COLUMN stock_minimo_global INT DEFAULT 5",
        "backup_automatico" => "ALTER TABLE sistema_config ADD COLUMN backup_automatico BOOLEAN DEFAULT 0",
        "frecuencia_backup" => "ALTER TABLE sistema_config ADD COLUMN frecuencia_backup VARCHAR(20) DEFAULT 'diario'",
        "ticket_empresa" => "ALTER TABLE sistema_config ADD COLUMN ticket_empresa BOOLEAN DEFAULT 1",
        "ticket_leyenda" => "ALTER TABLE sistema_config ADD COLUMN ticket_leyenda TEXT",
        "color_primario" => "ALTER TABLE sistema_config ADD COLUMN color_primario VARCHAR(7) DEFAULT '#27ae60'",
        "color_secundario" => "ALTER TABLE sistema_config ADD COLUMN color_secundario VARCHAR(7) DEFAULT '#2ecc71'"
    ];

    // Agregar columnas faltantes
    foreach ($new_columns as $column_name => $alter_sql) {
        if (!in_array($column_name, $existing_columns)) {
            try {
                $conn->exec($alter_sql);
            } catch (Exception $e) {
                throw new Exception("Error al agregar columna $column_name: " . $e->getMessage());
            }
        }
    }

    // Obtener configuración actual
    $sql_config = "SELECT * FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $config = $result_config->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        // Insertar configuración por defecto si no existe
        $sql_insert = "INSERT INTO sistema_config (nombre_empresa) VALUES ('Mi Empresa')";
        $conn->exec($sql_insert);
        $config = [
            'nombre_empresa' => 'Mi Empresa',
            'rfc' => '',
            'telefono' => '',
            'email' => '',
            'direccion' => '',
            'logo' => '',
            'iva' => '16.00',
            'moneda' => 'MXN'
        ];
        // Recargar la configuración
        $result_config = $conn->query($sql_config);
        $config = $result_config->fetch(PDO::FETCH_ASSOC);
    }

    // Función segura para obtener valores de configuración
    function getConfigValue($config, $key, $default = '')
    {
        return isset($config[$key]) ? $config[$key] : $default;
    }

    // Procesar actualización de configuración general
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_config'])) {
        $nombre_empresa = $_POST['nombre_empresa'];
        $rfc = $_POST['rfc'];
        $telefono = $_POST['telefono'];
        $email = $_POST['email'];
        $direccion = $_POST['direccion'];
        $iva = floatval($_POST['iva']);
        $moneda = $_POST['moneda'];

        $sql_update = "UPDATE sistema_config SET 
                      nombre_empresa = ?,
                      rfc = ?,
                      telefono = ?,
                      email = ?,
                      direccion = ?,
                      iva = ?,
                      moneda = ?";

        $stmt = $conn->prepare($sql_update);
        $stmt->execute([$nombre_empresa, $rfc, $telefono, $email, $direccion, $iva, $moneda]);

        if ($stmt->rowCount() >= 0) {
            $mensaje = "Configuración actualizada correctamente";
            $tipo_mensaje = "success";
            // Actualizar variable de configuración
            $config['nombre_empresa'] = $nombre_empresa;
            $config['rfc'] = $rfc;
            $config['telefono'] = $telefono;
            $config['email'] = $email;
            $config['direccion'] = $direccion;
            $config['iva'] = $iva;
            $config['moneda'] = $moneda;
        } else {
            $mensaje = "Error al actualizar la configuración";
            $tipo_mensaje = "danger";
        }
        $stmt = null;
    }

    // Procesar configuración de inventario
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_inventario'])) {
        $notificaciones_stock = isset($_POST['notificaciones_stock']) ? 1 : 0;
        $stock_minimo_global = intval($_POST['stock_minimo_global']);

        // Verificar si las columnas existen antes de actualizar
        if (in_array('notificaciones_stock', $existing_columns) && in_array('stock_minimo_global', $existing_columns)) {
            $sql_update = "UPDATE sistema_config SET 
                          notificaciones_stock = ?,
                          stock_minimo_global = ?";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute([$notificaciones_stock, $stock_minimo_global]);

            if ($stmt->rowCount() >= 0) {
                $mensaje = "Configuración de inventario actualizada";
                $tipo_mensaje = "success";
                $config['notificaciones_stock'] = $notificaciones_stock;
                $config['stock_minimo_global'] = $stock_minimo_global;
            } else {
                $mensaje = "Error al actualizar la configuración de inventario";
                $tipo_mensaje = "danger";
            }
            $stmt = null;
        } else {
            $mensaje = "Las columnas de configuración de inventario no están disponibles";
            $tipo_mensaje = "warning";
        }
    }

    // Procesar configuración de tickets
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_ticket'])) {
        if (in_array('ticket_empresa', $existing_columns) && in_array('ticket_leyenda', $existing_columns)) {
            $ticket_empresa = isset($_POST['ticket_empresa']) ? 1 : 0;
            $ticket_leyenda = $_POST['ticket_leyenda'];

            $sql_update = "UPDATE sistema_config SET 
                          ticket_empresa = ?,
                          ticket_leyenda = ?";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute([$ticket_empresa, $ticket_leyenda]);

            if ($stmt->rowCount() >= 0) {
                $mensaje = "Configuración de tickets actualizada";
                $tipo_mensaje = "success";
                $config['ticket_empresa'] = $ticket_empresa;
                $config['ticket_leyenda'] = $ticket_leyenda;
            } else {
                $mensaje = "Error al actualizar la configuración de tickets";
                $tipo_mensaje = "danger";
            }
            $stmt = null;
        } else {
            $mensaje = "Las columnas de configuración de tickets no están disponibles";
            $tipo_mensaje = "warning";
        }
    }

    // Procesar configuración de apariencia
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_apariencia'])) {
        if (in_array('color_primario', $existing_columns) && in_array('color_secundario', $existing_columns)) {
            $color_primario = $_POST['color_primario'];
            $color_secundario = $_POST['color_secundario'];

            // Validar formato de color
            if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color_primario)) {
                $color_primario = '#27ae60';
            }
            if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color_secundario)) {
                $color_secundario = '#2ecc71';
            }

            $sql_update = "UPDATE sistema_config SET 
                          color_primario = ?,
                          color_secundario = ?";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute([$color_primario, $color_secundario]);

            if ($stmt->rowCount() >= 0) {
                $mensaje = "Configuración de apariencia actualizada";
                $tipo_mensaje = "success";
                $config['color_primario'] = $color_primario;
                $config['color_secundario'] = $color_secundario;

                // Actualizar también en la sesión si es necesario
                $_SESSION['color_primario'] = $color_primario;
                $_SESSION['color_secundario'] = $color_secundario;
            } else {
                $mensaje = "Error al actualizar la apariencia";
                $tipo_mensaje = "danger";
            }
            $stmt = null;
        } else {
            $mensaje = "Las columnas de configuración de apariencia no están disponibles";
            $tipo_mensaje = "warning";
        }
    }

    // Procesar configuración de backup
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_backup'])) {
        if (in_array('backup_automatico', $existing_columns) && in_array('frecuencia_backup', $existing_columns)) {
            $backup_automatico = isset($_POST['backup_automatico']) ? 1 : 0;
            $frecuencia_backup = $_POST['frecuencia_backup'];

            $sql_update = "UPDATE sistema_config SET 
                          backup_automatico = ?,
                          frecuencia_backup = ?";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute([$backup_automatico, $frecuencia_backup]);

            if ($stmt->rowCount() >= 0) {
                $mensaje = "Configuración de backup actualizada";
                $tipo_mensaje = "success";
                $config['backup_automatico'] = $backup_automatico;
                $config['frecuencia_backup'] = $frecuencia_backup;
            } else {
                $mensaje = "Error al actualizar la configuración de backup";
                $tipo_mensaje = "danger";
            }
            $stmt = null;
        } else {
            $mensaje = "Las columnas de configuración de backup no están disponibles";
            $tipo_mensaje = "warning";
        }
    }

    // Procesar subida de logo
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logo']) && $_FILES['logo']['error'] === 0) {
        $directorio_logos = "logos/";

        if (!is_dir($directorio_logos)) {
            mkdir($directorio_logos, 0755, true);
        }

        $nombre_archivo = uniqid() . '_' . basename($_FILES['logo']['name']);
        $ruta_archivo = $directorio_logos . $nombre_archivo;

        $tipo_permitido = ['image/jpeg', 'image/png', 'image/gif'];
        $tipo_archivo = $_FILES['logo']['type'];

        if (in_array($tipo_archivo, $tipo_permitido)) {
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $ruta_archivo)) {
                if (!empty($config['logo']) && file_exists($config['logo'])) {
                    unlink($config['logo']);
                }

                $sql_logo = "UPDATE sistema_config SET logo = ?";
                $stmt = $conn->prepare($sql_logo);
                $stmt->execute([$ruta_archivo]);

                if ($stmt->rowCount() >= 0) {
                    $config['logo'] = $ruta_archivo;
                    $mensaje = "Logo actualizado correctamente";
                    $tipo_mensaje = "success";
                }
                $stmt = null;
            }
        }
    }

    // Función para crear backup manual
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_backup'])) {
        $backup_result = crearBackupManual($conn);
        $mensaje = $backup_result['message'];
        $tipo_mensaje = $backup_result['type'];
    }

    // Obtener estadísticas del sistema
    $sql_stats = "
        SELECT 
            (SELECT COUNT(*) FROM productos WHERE activo = 1) as total_productos,
            (SELECT COUNT(*) FROM clientes WHERE activo = 1) as total_clientes,
            (SELECT COUNT(*) FROM usuarios WHERE activo = 1) as total_usuarios,
            (SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()) as ventas_hoy,
            (SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo) as productos_bajo_stock,
            (SELECT COUNT(*) FROM sucursales WHERE activo = 1) as total_sucursales
    ";
    $result_stats = $conn->query($sql_stats);
    $estadisticas = $result_stats->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

/**
 * Función para crear backup manual con múltiples métodos
 */
function crearBackupManual($conn)
{
    $backup_dir = 'backups/';
    $backup_file = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';

    // Crear directorio si no existe
    if (!is_dir($backup_dir)) {
        if (!mkdir($backup_dir, 0755, true)) {
            return [
                'type' => 'danger',
                'message' => 'Error: No se pudo crear el directorio de backups.'
            ];
        }
    }

    // Verificar si el directorio es escribible
    if (!is_writable($backup_dir)) {
        return [
            'type' => 'danger',
            'message' => 'Error: El directorio de backups no tiene permisos de escritura.'
        ];
    }

    // Método 1: Usar mysqldump (más eficiente)
    $mysqldump_path = encontrarMysqldump();
    if ($mysqldump_path) {
        // Obtener credenciales de las variables de entorno
        $servername = env('DB_SERVERNAME', 'libertyfin.com.mx');
        $username = env('DB_USERNAME', 'juanc141_alexis');
        $password = env('DB_PASSWORD', 'Alexis1997');
        $dbname = env('DB_MAIN', 'juanc141_ventas');

        $command = '"' . $mysqldump_path . '" -h ' . escapeshellarg($servername) .
            ' -u ' . escapeshellarg($username) .
            ' -p' . escapeshellarg($password) .
            ' ' . escapeshellarg($dbname) .
            ' > "' . $backup_file . '" 2>&1';

        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);

        if ($return_var === 0 && file_exists($backup_file) && filesize($backup_file) > 0) {
            $tamaño = round(filesize($backup_file) / 1024, 2);
            return [
                'type' => 'success',
                'message' => "Backup creado exitosamente (mysqldump): " . basename($backup_file) . " ($tamaño KB)"
            ];
        }
    }

    // Método 2: Backup usando PHP puro
    try {
        if (crearBackupPHP($conn, $backup_file)) {
            $tamaño = round(filesize($backup_file) / 1024, 2);
            return [
                'type' => 'success',
                'message' => "Backup creado exitosamente (PHP): " . basename($backup_file) . " ($tamaño KB)"
            ];
        } else {
            throw new Exception("No se pudo crear el archivo de backup.");
        }
    } catch (Exception $e) {
        return [
            'type' => 'danger',
            'message' => "Error al crear backup: " . $e->getMessage()
        ];
    }
}

/**
 * Encuentra la ruta de mysqldump
 */
function encontrarMysqldump()
{
    $possible_paths = [
        'mysqldump',
        '/usr/bin/mysqldump',
        '/usr/local/mysql/bin/mysqldump',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.30\\bin\\mysqldump.exe',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.21\\bin\\mysqldump.exe',
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    ];

    foreach ($possible_paths as $path) {
        if (is_executable($path)) {
            return $path;
        }

        // En Windows, verificar con where
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            exec('where ' . $path . ' 2>nul', $output);
            if (!empty($output) && file_exists(trim($output[0]))) {
                return trim($output[0]);
            }
        }
    }

    return false;
}

/**
 * Crear backup usando PHP puro - Versión robusta
 */
function crearBackupPHP($conn, $backup_file)
{
    try {
        // Obtener todas las tablas
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        if ($result) {
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
        }

        $sql_script = "-- MySQL Backup\n";
        // Intentar obtener el nombre de la base de datos
        $db_result = $conn->query("SELECT DATABASE()");
        if ($db_result) {
            $db_name = $db_result->fetch_row()[0];
            $sql_script .= "-- Base de datos: " . $db_name . "\n";
        } else {
            $sql_script .= "-- Base de datos: " . session('empresa_db', 'desconocida') . "\n";
        }
        $sql_script .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
        $sql_script .= "-- PHP Version: " . PHP_VERSION . "\n\n";
        $sql_script .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        $sql_script .= "SET NAMES utf8mb4;\n\n";

        foreach ($tables as $table) {
            // Obtener estructura de la tabla
            $sql_script .= "-- --------------------------------------------------------\n";
            $sql_script .= "-- Estructura de tabla: `$table`\n";
            $sql_script .= "-- --------------------------------------------------------\n\n";
            $sql_script .= "DROP TABLE IF EXISTS `$table`;\n";

            $create_result = $conn->query("SHOW CREATE TABLE `$table`");
            if ($create_result) {
                $create_row = $create_result->fetch_array();
                // Usar el índice 1 que generalmente contiene el SQL de creación
                if (isset($create_row[1])) {
                    $sql_script .= $create_row[1] . ";\n\n";
                } else {
                    $sql_script .= "-- Error: No se pudo obtener la estructura de la tabla $table\n\n";
                    continue;
                }
            } else {
                $sql_script .= "-- Error: No se pudo obtener la estructura de la tabla $table\n\n";
                continue;
            }

            // Obtener datos de la tabla
            $data_result = $conn->query("SELECT * FROM `$table`");
            if ($data_result && $data_result->rowCount() > 0) {
                $sql_script .= "-- \n";
                $sql_script .= "-- Volcado de datos para tabla `$table`\n";
                $sql_script .= "-- \n\n";

                while ($row = $data_result->fetch(PDO::FETCH_NUM)) {
                    $sql_script .= "INSERT INTO `$table` VALUES (";

                    for ($i = 0; $i < count($row); $i++) {
                        if ($row[$i] === null) {
                            $sql_script .= "NULL";
                        } else {
                            $value = addslashes($row[$i]);
                            $sql_script .= "'$value'";
                        }

                        if ($i < count($row) - 1) {
                            $sql_script .= ", ";
                        }
                    }
                    $sql_script .= ");\n";
                }
                $sql_script .= "\n";
            } else {
                $sql_script .= "-- La tabla `$table` está vacía\n\n";
            }
        }

        $sql_script .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $sql_script .= "-- Fin del backup\n";

        return file_put_contents($backup_file, $sql_script) !== false;
    } catch (Exception $e) {
        error_log("Error en crearBackupPHP: " . $e->getMessage());
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo getConfigValue($config, 'color_primario', '#27ae60'); ?>;
            --secondary-color: <?php echo getConfigValue($config, 'color_secundario', '#2ecc71'); ?>;
            --preview-primary: <?php echo getConfigValue($config, 'color_primario', '#27ae60'); ?>;
            --preview-secondary: <?php echo getConfigValue($config, 'color_secundario', '#2ecc71'); ?>;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
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
            transition: all 0.3s ease;
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
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .config-section .card-header {
            border-bottom: 2px solid var(--primary-color);
        }

        .logo-preview {
            max-width: 200px;
            max-height: 200px;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 10px;
        }

        .nav-tabs .nav-link.active {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .stat-card {
            border-left: 4px solid var(--primary-color);
        }

        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            display: inline-block;
            margin-right: 10px;
            border: 1px solid #ddd;
        }

        .backup-list {
            max-height: 200px;
            overflow-y: auto;
        }

        /* Estilos adicionales para la personalización de colores */
        .paleta-option {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .paleta-option:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .paleta-option.border-primary {
            border-width: 2px !important;
        }

        .form-control-color {
            width: 3rem;
            height: 2.5rem;
            padding: 0.1rem;
        }

        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            display: inline-block;
            border: 1px solid #ddd;
        }

        #previewNavbar {
            min-height: 60px;
        }

        #previewAlert {
            border: none;
            border-radius: 0.375rem;
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
        }

        /* Responsive */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 56px;
                left: -100%;
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
            }

            .sidebar.show {
                left: 0;
            }

            .sidebar-backdrop.show {
                display: block;
            }

            main {
                margin-left: 0 !important;
            }
        }

        @media (max-width: 575.98px) {
            .btn-group-actions .btn {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
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

            <a class="navbar-brand" href="#">
                <?php if (!empty($config['logo']) && file_exists($config['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($config['logo']); ?>"
                        alt="Logo"
                        style="height: 40px; max-width: 150px; object-fit: contain; margin-right: 10px;">
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </a>
                    <ul class="dropdown-menu">
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
                                Inicio
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
                            <a class="nav-link" href="Productos">
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
                        <?php if ($empresa_plan !== 'basico'  && $_SESSION['usuario_rol'] === 'admin' ): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Sucursales">
                                    <i class="fas fa-store"></i>
                                    Sucursales
                                </a>
                            </li>
                        <?php endif; ?>
                         <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles > 0) : ?>
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
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link active" href="Configuracion">
                                    <i class="fas fa-cogs"></i>
                                    Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-cogs me-2"></i>Configuración del Sistema</h2>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Navegación por pestañas -->
                <ul class="nav nav-tabs mb-4" id="configTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
                            <i class="fas fa-building me-1"></i>General
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="inventario-tab" data-bs-toggle="tab" data-bs-target="#inventario" type="button" role="tab">
                            <i class="fas fa-boxes me-1"></i>Inventario
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tickets-tab" data-bs-toggle="tab" data-bs-target="#tickets" type="button" role="tab">
                            <i class="fas fa-receipt me-1"></i>Tickets
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="apariencia-tab" data-bs-toggle="tab" data-bs-target="#apariencia" type="button" role="tab">
                            <i class="fas fa-palette me-1"></i>Apariencia
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="configTabsContent">

                    <!-- Pestaña General -->
                    <div class="tab-pane fade show active" id="general" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="card-title mb-0"><i class="fas fa-building me-2"></i>Información de la Empresa</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Nombre de la Empresa *</label>
                                                    <input type="text" class="form-control" name="nombre_empresa"
                                                        value="<?php echo htmlspecialchars($config['nombre_empresa']); ?>" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">RFC</label>
                                                    <input type="text" class="form-control" name="rfc"
                                                        value="<?php echo htmlspecialchars(getConfigValue($config, 'rfc')); ?>" maxlength="20">
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Teléfono</label>
                                                    <input type="tel" class="form-control" name="telefono"
                                                        value="<?php echo htmlspecialchars(getConfigValue($config, 'telefono')); ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Email</label>
                                                    <input type="email" class="form-control" name="email"
                                                        value="<?php echo htmlspecialchars(getConfigValue($config, 'email')); ?>">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Dirección</label>
                                                <textarea class="form-control" name="direccion" rows="3"><?php echo htmlspecialchars(getConfigValue($config, 'direccion')); ?></textarea>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">IVA (%) *</label>
                                                    <input type="number" class="form-control" name="iva"
                                                        value="<?php echo getConfigValue($config, 'iva', '16.00'); ?>" step="0.01" min="0" max="100" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Moneda *</label>
                                                    <select class="form-select" name="moneda" required>
                                                        <option value="MXN" <?php echo getConfigValue($config, 'moneda') === 'MXN' ? 'selected' : ''; ?>>MXN - Peso Mexicano</option>
                                                        <option value="USD" <?php echo getConfigValue($config, 'moneda') === 'USD' ? 'selected' : ''; ?>>USD - Dólar Americano</option>
                                                        <option value="EUR" <?php echo getConfigValue($config, 'moneda') === 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button type="submit" name="actualizar_config" class="btn btn-primary">
                                                <i class="fas fa-save me-2"></i>Guardar Configuración
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header bg-success text-white">
                                        <h5 class="card-title mb-0"><i class="fas fa-image me-2"></i>Logo de la Empresa</h5>
                                    </div>
                                    <div class="card-body text-center">
                                        <?php if (!empty($config['logo']) && file_exists($config['logo'])): ?>
                                            <img src="<?php echo $config['logo']; ?>" alt="Logo" class="logo-preview img-fluid mb-3">
                                        <?php else: ?>
                                            <div class="logo-preview d-flex align-items-center justify-content-center mb-3">
                                                <i class="fas fa-building fa-3x text-muted"></i>
                                            </div>
                                        <?php endif; ?>

                                        <form method="POST" action="" enctype="multipart/form-data">
                                            <div class="mb-3">
                                                <input type="file" class="form-control" name="logo" accept="image/jpeg,image/png,image/gif">
                                            </div>
                                            <button type="submit" class="btn btn-success w-100">
                                                <i class="fas fa-upload me-2"></i>Subir Logo
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pestaña Inventario -->
                    <div class="tab-pane fade" id="inventario" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="card">
                                    <div class="card-header bg-warning text-dark">
                                        <h5 class="card-title mb-0"><i class="fas fa-boxes me-2"></i>Configuración de Inventario</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="">
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="notificaciones_stock"
                                                        id="notificaciones_stock" <?php echo getConfigValue($config, 'notificaciones_stock', 1) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="notificaciones_stock">
                                                        Activar notificaciones de stock bajo
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Stock mínimo global</label>
                                                <input type="number" class="form-control" name="stock_minimo_global"
                                                    value="<?php echo getConfigValue($config, 'stock_minimo_global', 5); ?>" min="1" required>
                                                <div class="form-text">
                                                    Este valor se usará como stock mínimo por defecto para nuevos productos.
                                                </div>
                                            </div>
                                            <button type="submit" name="actualizar_inventario" class="btn btn-warning">
                                                <i class="fas fa-save me-2"></i>Guardar Configuración
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pestaña Tickets -->
                    <div class="tab-pane fade" id="tickets" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="card">
                                    <div class="card-header bg-secondary text-white">
                                        <h5 class="card-title mb-0"><i class="fas fa-receipt me-2"></i>Configuración de Tickets</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="">
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="ticket_empresa"
                                                        id="ticket_empresa" <?php echo getConfigValue($config, 'ticket_empresa', 1) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="ticket_empresa">
                                                        Mostrar información de la empresa en el ticket
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Leyenda del ticket</label>
                                                <textarea class="form-control" name="ticket_leyenda" rows="3"
                                                    placeholder="Ej: ¡Gracias por su compra!"><?php echo htmlspecialchars(getConfigValue($config, 'ticket_leyenda', '')); ?></textarea>
                                                <div class="form-text">
                                                    Este texto aparecerá al final del ticket de venta.
                                                </div>
                                            </div>
                                            <button type="submit" name="actualizar_ticket" class="btn btn-secondary">
                                                <i class="fas fa-save me-2"></i>Guardar Configuración
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pestaña Apariencia - Versión Mejorada -->
                    <div class="tab-pane fade" id="apariencia" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="card">
                                    <div class="card-header bg-purple text-white" style="background-color: #6f42c1;">
                                        <h5 class="card-title mb-0"><i class="fas fa-palette me-2"></i>Personalización de Colores</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="">
                                            <!-- Selección de Colores Personalizados -->
                                            <div class="row mb-4">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">Color Primario</label>
                                                    <p class="text-muted small">Color principal para botones, encabezados y elementos destacados</p>
                                                    <div class="input-group mb-2">
                                                        <span class="input-group-text">
                                                            <div class="color-preview" id="previewPrimario" style="background-color: <?php echo getConfigValue($config, 'color_primario', '#27ae60'); ?>"></div>
                                                        </span>
                                                        <input type="text" class="form-control color-hex" name="color_primario"
                                                            value="<?php echo getConfigValue($config, 'color_primario', '#27ae60'); ?>"
                                                            placeholder="#27ae60" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$">
                                                        <input type="color" class="form-control form-control-color color-picker"
                                                            value="<?php echo getConfigValue($config, 'color_primario', '#27ae60'); ?>"
                                                            title="Elige el color primario">
                                                    </div>
                                                    <div class="form-text">
                                                        Introduce un código hexadecimal o usa el selector de color
                                                    </div>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">Color Secundario</label>
                                                    <p class="text-muted small">Color para botones secundarios, hover states y elementos complementarios</p>
                                                    <div class="input-group mb-2">
                                                        <span class="input-group-text">
                                                            <div class="color-preview" id="previewSecundario" style="background-color: <?php echo getConfigValue($config, 'color_secundario', '#2ecc71'); ?>"></div>
                                                        </span>
                                                        <input type="text" class="form-control color-hex" name="color_secundario"
                                                            value="<?php echo getConfigValue($config, 'color_secundario', '#2ecc71'); ?>"
                                                            placeholder="#2ecc71" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$">
                                                        <input type="color" class="form-control form-control-color color-picker"
                                                            value="<?php echo getConfigValue($config, 'color_secundario', '#2ecc71'); ?>"
                                                            title="Elige el color secundario">
                                                    </div>
                                                    <div class="form-text">
                                                        Introduce un código hexadecimal o usa el selector de color
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Paletas Predefinidas -->
                                            <div class="mb-4">
                                                <label class="form-label fw-bold">Paletas Predefinidas</label>
                                                <p class="text-muted small">Selecciona una combinación de colores predefinida</p>
                                                <div class="row g-2" id="paletasPredefinidas">
                                                    <!-- Paleta Verde (por defecto) -->
                                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                                        <div class="paleta-option border rounded p-2" data-primario="#27ae60" data-secundario="#2ecc71">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="color-swatch me-2" style="background-color: #27ae60; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                                <div class="color-swatch" style="background-color: #2ecc71; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                            </div>
                                                            <small class="d-block">Verde Natural</small>
                                                        </div>
                                                    </div>
                                                    <!-- Paleta Azul -->
                                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                                        <div class="paleta-option border rounded p-2" data-primario="#3498db" data-secundario="#5dade2">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="color-swatch me-2" style="background-color: #3498db; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                                <div class="color-swatch" style="background-color: #5dade2; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                            </div>
                                                            <small class="d-block">Azul Profesional</small>
                                                        </div>
                                                    </div>
                                                    <!-- Paleta Morado -->
                                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                                        <div class="paleta-option border rounded p-2" data-primario="#9b59b6" data-secundario="#bb8fce">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="color-swatch me-2" style="background-color: #9b59b6; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                                <div class="color-swatch" style="background-color: #bb8fce; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                            </div>
                                                            <small class="d-block">Morado Creativo</small>
                                                        </div>
                                                    </div>
                                                    <!-- Paleta Naranja -->
                                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                                        <div class="paleta-option border rounded p-2" data-primario="#e67e22" data-secundario="#f39c12">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="color-swatch me-2" style="background-color: #e67e22; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                                <div class="color-swatch" style="background-color: #f39c12; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                            </div>
                                                            <small class="d-block">Naranja Energético</small>
                                                        </div>
                                                    </div>
                                                    <!-- Paleta Rojo -->
                                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                                        <div class="paleta-option border rounded p-2" data-primario="#e74c3c" data-secundario="#ec7063">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="color-swatch me-2" style="background-color: #e74c3c; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                                <div class="color-swatch" style="background-color: #ec7063; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                            </div>
                                                            <small class="d-block">Rojo Intenso</small>
                                                        </div>
                                                    </div>
                                                    <!-- Paleta Gris -->
                                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                                        <div class="paleta-option border rounded p-2" data-primario="#34495e" data-secundario="#5d6d7e">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="color-swatch me-2" style="background-color: #34495e; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                                <div class="color-swatch" style="background-color: #5d6d7e; width: 20px; height: 20px; border-radius: 3px;"></div>
                                                            </div>
                                                            <small class="d-block">Gris Elegante</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Vista Previa Mejorada -->
                                            <div class="mb-4">
                                                <label class="form-label fw-bold">Vista Previa</label>
                                                <p class="text-muted small">Así se verá tu sistema con los colores seleccionados</p>
                                                <div class="border rounded p-4 bg-light">
                                                    <!-- Barra de navegación de vista previa -->
                                                    <div class="navbar navbar-dark rounded mb-4" id="previewNavbar"
                                                        style="background: linear-gradient(135deg, var(--preview-primary, #27ae60), var(--preview-secondary, #2ecc71));">
                                                        <div class="container-fluid">
                                                            <a class="navbar-brand" href="#">
                                                                <i class="fas fa-cash-register me-2"></i>
                                                                Mi Empresa
                                                            </a>
                                                            <div class="navbar-nav ms-auto">
                                                                <span class="nav-link">
                                                                    <i class="fas fa-user-circle me-1"></i>
                                                                    Usuario Ejemplo
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Botones de vista previa -->
                                                    <div class="d-flex gap-2 flex-wrap mb-4">
                                                        <button class="btn" id="previewBtnPrimary">Botón Primario</button>
                                                        <button class="btn" id="previewBtnSecondary">Botón Secundario</button>
                                                        <button class="btn btn-outline-secondary">Botón Outline</button>
                                                    </div>

                                                    <!-- Tarjeta de vista previa -->
                                                    <div class="card mb-4">
                                                        <div class="card-header" id="previewCardHeader">
                                                            <h5 class="card-title mb-0">Ejemplo de Tarjeta</h5>
                                                        </div>
                                                        <div class="card-body">
                                                            <p class="card-text">Esta es una tarjeta de ejemplo con los colores seleccionados.</p>
                                                            <div class="progress mb-3">
                                                                <div class="progress-bar" id="previewProgressBar" role="progressbar" style="width: 65%"></div>
                                                            </div>
                                                            <div class="alert" id="previewAlert">
                                                                <i class="fas fa-info-circle me-2"></i>
                                                                Este es un mensaje de alerta de ejemplo
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Badges de vista previa -->
                                                    <div class="d-flex gap-2 flex-wrap">
                                                        <span class="badge" id="previewBadgePrimary">Etiqueta 1</span>
                                                        <span class="badge" id="previewBadgeSecondary">Etiqueta 2</span>
                                                        <span class="badge bg-secondary">Etiqueta 3</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <button type="submit" name="actualizar_apariencia" class="btn btn-primary">
                                                <i class="fas fa-save me-2"></i>Guardar Colores
                                            </button>
                                            <button type="button" id="resetColors" class="btn btn-outline-secondary ms-2">
                                                <i class="fas fa-undo me-2"></i>Restablecer Valores por Defecto
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                   

                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            // Función para mostrar/ocultar sidebar
            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
            }

            // Event listeners
            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarBackdrop.addEventListener('click', toggleSidebar);

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        toggleSidebar();
                    }
                });
            });

            // Funcionalidad mejorada para la personalización de colores
            // Elementos de entrada de color
            const colorPrimarioInput = document.querySelector('input[name="color_primario"]');
            const colorSecundarioInput = document.querySelector('input[name="color_secundario"]');
            const colorPickers = document.querySelectorAll('.color-picker');
            const colorHexInputs = document.querySelectorAll('.color-hex');

            // Elementos de vista previa
            const previewPrimario = document.getElementById('previewPrimario');
            const previewSecundario = document.getElementById('previewSecundario');
            const previewNavbar = document.getElementById('previewNavbar');
            const previewBtnPrimary = document.getElementById('previewBtnPrimary');
            const previewBtnSecondary = document.getElementById('previewBtnSecondary');
            const previewCardHeader = document.getElementById('previewCardHeader');
            const previewProgressBar = document.getElementById('previewProgressBar');
            const previewAlert = document.getElementById('previewAlert');
            const previewBadgePrimary = document.getElementById('previewBadgePrimary');
            const previewBadgeSecondary = document.getElementById('previewBadgeSecondary');

            // Paletas predefinidas
            const paletas = document.querySelectorAll('.paleta-option');

            // Botón de restablecimiento
            const resetButton = document.getElementById('resetColors');

            // Función para actualizar todas las vistas previas
            function actualizarVistaPrevia() {
                const primario = colorPrimarioInput.value;
                const secundario = colorSecundarioInput.value;

                // Actualizar variables CSS para la vista previa
                document.documentElement.style.setProperty('--preview-primary', primario);
                document.documentElement.style.setProperty('--preview-secondary', secundario);

                // Actualizar elementos de vista previa individuales
                previewPrimario.style.backgroundColor = primario;
                previewSecundario.style.backgroundColor = secundario;

                previewBtnPrimary.style.backgroundColor = primario;
                previewBtnPrimary.style.borderColor = primario;
                previewBtnPrimary.style.color = getContrastColor(primario);

                previewBtnSecondary.style.backgroundColor = secundario;
                previewBtnSecondary.style.borderColor = secundario;
                previewBtnSecondary.style.color = getContrastColor(secundario);

                previewCardHeader.style.backgroundColor = primario;
                previewCardHeader.style.color = getContrastColor(primario);

                previewProgressBar.style.backgroundColor = primario;

                previewAlert.style.backgroundColor = primario;
                previewAlert.style.color = getContrastColor(primario);

                previewBadgePrimary.style.backgroundColor = primario;
                previewBadgePrimary.style.color = getContrastColor(primario);

                previewBadgeSecondary.style.backgroundColor = secundario;
                previewBadgeSecondary.style.color = getContrastColor(secundario);

                // Actualizar selectores de color
                document.querySelectorAll('.color-picker').forEach((picker, index) => {
                    if (index === 0) picker.value = primario;
                    if (index === 1) picker.value = secundario;
                });
            }

            // Función para determinar el color de texto contrastante
            function getContrastColor(hexcolor) {
                // Eliminar el # si está presente
                hexcolor = hexcolor.replace("#", "");

                // Convertir a RGB
                const r = parseInt(hexcolor.substr(0, 2), 16);
                const g = parseInt(hexcolor.substr(2, 2), 16);
                const b = parseInt(hexcolor.substr(4, 2), 16);

                // Calcular luminosidad
                const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

                // Devolver blanco o negro según la luminosidad
                return luminance > 0.5 ? '#000000' : '#FFFFFF';
            }

            // Eventos para inputs de texto (hexadecimal)
            colorHexInputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Validar formato hexadecimal
                    if (this.value.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                        actualizarVistaPrevia();

                        // Actualizar el selector de color correspondiente
                        const pickerIndex = Array.from(colorHexInputs).indexOf(this);
                        if (pickerIndex !== -1 && colorPickers[pickerIndex]) {
                            colorPickers[pickerIndex].value = this.value;
                        }
                    }
                });
            });

            // Eventos para selectores de color
            colorPickers.forEach((picker, index) => {
                picker.addEventListener('input', function() {
                    // Actualizar el input de texto correspondiente
                    if (colorHexInputs[index]) {
                        colorHexInputs[index].value = this.value;
                        actualizarVistaPrevia();
                    }
                });
            });

            // Eventos para paletas predefinidas
            paletas.forEach(paleta => {
                paleta.addEventListener('click', function() {
                    const primario = this.getAttribute('data-primario');
                    const secundario = this.getAttribute('data-secundario');

                    // Actualizar inputs
                    colorPrimarioInput.value = primario;
                    colorSecundarioInput.value = secundario;

                    // Actualizar vista previa
                    actualizarVistaPrevia();

                    // Resaltar paleta seleccionada
                    paletas.forEach(p => p.classList.remove('border-primary'));
                    this.classList.add('border-primary');
                });
            });

            // Evento para botón de restablecimiento
            resetButton.addEventListener('click', function() {
                if (confirm('¿Estás seguro de que quieres restablecer los colores a los valores por defecto?')) {
                    colorPrimarioInput.value = '#27ae60';
                    colorSecundarioInput.value = '#2ecc71';
                    actualizarVistaPrevia();

                    // Quitar resaltado de paletas
                    paletas.forEach(p => p.classList.remove('border-primary'));
                }
            });

            // Inicializar vista previa
            actualizarVistaPrevia();
        });
    </script>
</body>

</html>