<?php

$host       = "libertyfin.com.mx";
$cpanelUser = "juanc141";
$apiToken   = "4KGLQYQZ3E7A52QI7EK20HFZCE7UD7S9";

// Crear conexión cURL básica
function api_call($funcion, $params = [])
{
    global $host, $cpanelUser, $apiToken;
    
    $url = "https://{$host}:2083/execute/Mysql/{$funcion}";
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

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

echo "<h3>📋 TUS BASES DE DATOS:</h3>";
$bases = api_call("list_databases");
echo "<pre>";

// FORMA CORRECTA de mostrar las bases de datos
if (isset($bases['data']) && is_array($bases['data'])) {
    foreach ($bases['data'] as $bd) {
        // Cada $bd es un array, necesitamos el nombre correcto
        if (is_array($bd) && isset($bd['database'])) {
            echo $bd['database'] . "\n";
        } 
        // O si es un string directo
        elseif (is_string($bd)) {
            echo $bd . "\n";
        }
        // Si es otro formato, mostrarlo para debug
        else {
            echo "Formato: ";
            print_r($bd);
        }
    }
} else {
    echo "Respuesta completa para debug:\n";
    print_r($bases);
}
echo "</pre>";

echo "<h3>👤 TUS USUARIOS MYSQL:</h3>";
$usuarios = api_call("list_users");
echo "<pre>";

// FORMA CORRECTA de mostrar los usuarios
if (isset($usuarios['data']) && is_array($usuarios['data'])) {
    foreach ($usuarios['data'] as $user) {
        // Cada $user es un array, necesitamos el nombre correcto
        if (is_array($user) && isset($user['user'])) {
            echo $user['user'] . "\n";
        } 
        // O si es un string directo
        elseif (is_string($user)) {
            echo $user . "\n";
        }
        // Si es otro formato
        else {
            echo "Formato: ";
            print_r($user);
        }
    }
} else {
    echo "Respuesta completa para debug:\n";
    print_r($usuarios);
}
echo "</pre>";

?>