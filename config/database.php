<?php
// config/database.php

require_once __DIR__ . '/../env_loader.php';
require_once __DIR__ . '/../config.php';
function getDBConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . env('DB_SERVERNAME', 'localhost') . 
            ";dbname=" . env('DB_MAIN', 'juanc141_ventas') . 
            ";charset=utf8mb4",
            env('DB_USERNAME', 'root'),
            env('DB_PASSWORD', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Error de conexión a BD: " . $e->getMessage());
        throw new Exception("Error de conexión a la base de datos: " . $e->getMessage());
    }
}

// Función para obtener conexión a la base de datos de una empresa específica
function getEmpresaDBConnection($dbname) {
    try {
        $pdo = new PDO(
            "mysql:host=" . env('DB_SERVERNAME', 'localhost') . 
            ";dbname=" . $dbname . 
            ";charset=utf8mb4",
            env('DB_USERNAME', 'root'),
            env('DB_PASSWORD', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Error de conexión a BD empresa: " . $e->getMessage());
        throw new Exception("Error de conexión a la base de datos de la empresa: " . $e->getMessage());
    }
}

function logSpeiTransaction($pdo, $tipo, $clabe, $account, $request, $response, $codigo = null, $httpCode = null) {
    try {
        
        
        $stmt = $pdo->prepare("
            INSERT INTO spei_transacciones_log 
            (tipo, clabe, account, request_json, response_json, codigo_respuesta, http_code, ip_origen)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tipo,
            $clabe,
            $account,
            json_encode($request, JSON_UNESCAPED_UNICODE),
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $codigo,
            $httpCode,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Error guardando log SPEI: " . $e->getMessage());
    }
}
?>