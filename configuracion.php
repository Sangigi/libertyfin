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

// OBTENER DATOS DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$conn_main = getDBConnection();

// Valores por defecto (para que el sidebar nunca falle)
$empresa_plan        = "prueba";
$timbres_totales     = 0;
$timbres_disponibles = 0;
$terminal_emida      = null;   // <-- FALTABA
$notification_status = null;   // <-- FALTABA

if ($conn_main) {
    $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles, terminal_emida 
                    FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([$_SESSION['empresa_id']]);
    $result_empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);

    if ($result_empresa) {
        $empresa_plan        = $result_empresa['plan'];
        $timbres_totales     = $result_empresa['timbres_totales'] ?? 0;
        $timbres_disponibles = $result_empresa['timbres_disponibles'] ?? 0;
        $terminal_emida      = $result_empresa['terminal_emida'] ?? null;
    }

    // Notificaciones Emida
    if (file_exists(__DIR__ . '/../EmidaServicios/config.php')) {
        require_once __DIR__ . '/../EmidaServicios/config.php';
        if (function_exists('getNotificationStatus')) {
            $notification_status = getNotificationStatus($conn_main);
        }
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
        <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Tema unificado LibertyFin (estilo landing) -->
    <link rel="stylesheet" href="css/crm-theme.css">
</head>

<body>
    <!-- Navbar -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
  <?php include 'includes/sidebar.php'; ?>

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
                </ul>

                <div class="tab-content" id="configTabsContent">

                    <!-- Pestaña General -->
                    <div class="tab-pane fade show active" id="general" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="card-title text-white mb-0"><i class="fas fa-building me-2"></i>Información de la Empresa</h5>
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
                                        <h5 class="card-title text-white mb-0"><i class="fas fa-image me-2"></i>Logo de la Empresa</h5>
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
                                        <h5 class="card-title text-white mb-0"><i class="fas fa-boxes me-2"></i>Configuración de Inventario</h5>
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
                                        <h5 class="card-title text-white mb-0"><i class="fas fa-receipt me-2"></i>Configuración de Tickets</h5>
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

        });
    </script>
</body>

</html>