<?php
// =============================================
// SERVICE: OBTENER ESTADÍSTICAS (AJAX)
// =============================================
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    
    $sql_stats = "SELECT 
        COUNT(*) as total_empresas,
        SUM(CASE WHEN estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobadas,
        SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as desactivadas,
        SUM(CASE WHEN plan = 'prueba' THEN 1 ELSE 0 END) as plan_prueba,
        SUM(CASE WHEN plan = 'basico' THEN 1 ELSE 0 END) as plan_basico,
        SUM(CASE WHEN plan = 'starter' THEN 1 ELSE 0 END) as plan_starter,
        SUM(CASE WHEN plan = 'emprendedor' THEN 1 ELSE 0 END) as plan_emprendedor,
        SUM(CASE WHEN plan = 'premium' THEN 1 ELSE 0 END) as plan_premium
        FROM empresas";

    $stmt = $pdo->query($sql_stats);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // CONVERTIR TODOS LOS VALORES A NÚMEROS ENTEROS
    if ($stats) {
        foreach ($stats as $key => $value) {
            $stats[$key] = (int)$value;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $stats ?: []
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos: ' . $e->getMessage()
    ]);
}
?>