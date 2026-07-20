<?php
// /ruta/completa/scripts/verificar_vencimiento.php
// O donde tengas tus scripts

require_once __DIR__ . '/../../config/database.php';

try {
    $conn = getDBConnection(); 
    

    $sql_update = "UPDATE empresas 
                   SET activo = 0 
                   WHERE activo = 1 
                   AND fecha_vencimiento IS NOT NULL 
                   AND fecha_vencimiento < CURDATE()";
    
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->execute();
    $cantidad_actualizadas = $stmt_update->rowCount();
    
    // --- REGISTRO EN LOG ---
    $log_dir = __DIR__ . '/../../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/vencimiento_' . date('Y-m-d') . '.log';
    $log_message = date('Y-m-d H:i:s') . " - Empresas desactivadas: " . $cantidad_actualizadas . "\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
    
    echo "Proceso completado: " . $cantidad_actualizadas . " empresas desactivadas.\n";
    
} catch (PDOException $e) {
    // Log de error
    $error_log = __DIR__ . '/../../logs/error_vencimiento.log';
    $error_dir = dirname($error_log);
    if (!is_dir($error_dir)) {
        mkdir($error_dir, 0755, true);
    }
    
    file_put_contents($error_log, date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    // Log de error general
    $error_log = __DIR__ . '/../../logs/error_vencimiento.log';
    file_put_contents($error_log, date('Y-m-d H:i:s') . " - ERROR GENERAL: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Error General: " . $e->getMessage() . "\n";
}
?>