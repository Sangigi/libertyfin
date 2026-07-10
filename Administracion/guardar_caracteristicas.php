<?php

$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

header('Content-Type: application/json');

// Configuración de la base de datos principal
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname_main = "juanc141_ventas";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

$empresa_id = isset($_POST['empresa_id']) ? intval($_POST['empresa_id']) : 0;

if ($empresa_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de empresa inválido']);
    exit();
}

// Obtener valores del formulario
$precio_compra = isset($_POST['precio_compra']) ? 1 : 0;
$unidad_medida = isset($_POST['unidad_medida']) ? 1 : 0;
$proveedor = isset($_POST['proveedor']) ? 1 : 0;
$fecha_caducidad = isset($_POST['fecha_caducidad']) ? 1 : 0;
$categoria = isset($_POST['categoria']) ? 1 : 0;

// Obtener tipos de unidad seleccionados
$tipos_unidad = isset($_POST['tipos_unidad']) ? $_POST['tipos_unidad'] : [];
if (empty($tipos_unidad)) {
    $tipos_unidad = ['pieza']; // Valor por defecto
}
$configuracion_extra = json_encode($tipos_unidad);

// Conectar a la base de datos
$conn = new mysqli($servername, $username, $password, $dbname_main);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]);
    exit();
}

try {
    // Iniciar transacción
    $conn->begin_transaction();
    
    // Verificar si la tabla existe, si no, crearla
    $check_table = "SHOW TABLES LIKE 'empresa_caracteristicas'";
    $table_exists = $conn->query($check_table);
    
    if ($table_exists->num_rows == 0) {
        // Crear la tabla si no existe
        $create_table = "CREATE TABLE IF NOT EXISTS `empresa_caracteristicas` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `empresa_id` int(11) NOT NULL,
            `caracteristica` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
            `habilitado` tinyint(1) DEFAULT '1',
            `configuracion_extra` text COLLATE utf8_unicode_ci,
            `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_empresa_caracteristica` (`empresa_id`, `caracteristica`),
            KEY `idx_empresa` (`empresa_id`),
            KEY `idx_caracteristica` (`caracteristica`),
            KEY `idx_habilitado` (`habilitado`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        
        if (!$conn->query($create_table)) {
            throw new Exception("Error al crear la tabla: " . $conn->error);
        }
    }
    
    // Definir las características a guardar
    $caracteristicas = [
        ['precio_compra', $precio_compra, null],
        ['unidad_medida', $unidad_medida, $configuracion_extra],
        ['proveedor', $proveedor, null],
        ['fecha_caducidad', $fecha_caducidad, null],
        ['categoria', $categoria, null]
    ];
    
    // Preparar la consulta de upsert (insert or update)
    $sql = "INSERT INTO empresa_caracteristicas (empresa_id, caracteristica, habilitado, configuracion_extra) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
            habilitado = VALUES(habilitado), 
            configuracion_extra = VALUES(configuracion_extra),
            fecha_actualizacion = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . $conn->error);
    }
    
    foreach ($caracteristicas as $caract) {
        $caracteristica = $caract[0];
        $habilitado = $caract[1];
        $config_extra = $caract[2];
        
        $stmt->bind_param("isss", $empresa_id, $caracteristica, $habilitado, $config_extra);
        
        if (!$stmt->execute()) {
            throw new Exception("Error al guardar '$caracteristica': " . $stmt->error);
        }
    }
    
    $stmt->close();
    
    // Commit de la transacción
    $conn->commit();
    
    // Registrar en bitácora (opcional)
    $usuario = $_SESSION['usuario_nombre'] ?? 'admin';
    $log_sql = "INSERT INTO logs_actividades (usuario, accion, tabla_afectada, registro_id, detalles, ip_usuario, fecha) 
                VALUES (?, 'Actualización de características', 'empresa_caracteristicas', ?, ?, ?, NOW())";
    
    $detalles = json_encode([
        'precio_compra' => $precio_compra,
        'unidad_medida' => $unidad_medida,
        'proveedor' => $proveedor,
        'fecha_caducidad' => $fecha_caducidad,
        'categoria' => $categoria,
        'tipos_unidad' => $tipos_unidad
    ]);
    
    $ip_usuario = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $log_stmt->bind_param("siss", $usuario, $empresa_id, $detalles, $ip_usuario);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Configuración guardada exitosamente',
        'data' => [
            'precio_compra' => $precio_compra,
            'unidad_medida' => $unidad_medida,
            'proveedor' => $proveedor,
            'fecha_caducidad' => $fecha_caducidad,
            'categoria' => $categoria,
            'tipos_unidad' => $tipos_unidad
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>