<?php

$host       = "libertyfin.com.mx";     // Cambia tu dominio o IP
$cpanelUser = "juanc141";                 // Tu usuario cPanel
$apiToken   = "4KGLQYQZ3E7A52QI7EK20HFZCE7UD7S9";        // Tu token API

$fullDbName = "juanc141_leo_stars";
$fullDbUser = "juanc141_leo_stars";

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

    // Solo para evitar errores SSL localmente
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        die("cURL error: $err");
    }

    return json_decode($response, true);
}


/****************************************************
 * 
 * 1. ELIMINAR BASE DE DATOS
 ****************************************************/
$r1 = call_uapi($host, $cpanelUser, $apiToken, "Mysql", "delete_database", [
    "name" => $fullDbName
]);

echo "<pre>Eliminando base de datos...\n";
print_r($r1);


/****************************************************
 * 2. ELIMINAR USUARIO MYSQL
 ****************************************************/
$r2 = call_uapi($host, $cpanelUser, $apiToken, "Mysql", "delete_user", [
    "name" => $fullDbUser
]);

echo "\nEliminando usuario...\n";
print_r($r2);
echo "</pre>";

?>
