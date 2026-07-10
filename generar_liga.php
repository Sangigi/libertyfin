<?php
// ========================================
// GENERAR LIGA DE PAGO - PAGADETODO
// ========================================

// CONFIGURACIÓN proporcionada por CobrosConTarjeta
$USER = "iDcM4520HY";
$PASSWORD = 'f#1,f54$Jh';
$INTEGRATION_ID = "124";
$BUSINESS_ID = "000002";

// Tipo de pago (401 = crédito/débito contado)
$PAYMENT_TYPE = "401";

// ========================================
// DATOS ENVIADOS POR TU SISTEMA / API
// ========================================
$Id             = "0000000001";                     // Identificador interno (10 dígitos)
$Description    = "Pago de ejemplo Alexis";         // Máximo 50 caracteres
$Amount         = "15000";                          // 150.00 → 15000
$Reference      = "000000000000001";                // 15 dígitos, única
$ExpirationDate = date("Y-m-d", strtotime("+2 days")); // Vigencia mínima hoy

// ========================================
// ARMAR JSON A ENVIAR
// ========================================
$data = [
    "User"          => $USER,
    "Password"      => $PASSWORD,
    "IntegrationID" => $INTEGRATION_ID,
    "BusinessID"    => $BUSINESS_ID,
    "PaymentTypes"  => $PAYMENT_TYPE,
    "Id"            => $Id,
    "Description"   => $Description,
    "Amount"        => $Amount,
    "Reference"     => $Reference,
    "ExpirationDate"=> $ExpirationDate
];

$jsonData = json_encode($data);

// ========================================
// INICIAR CONEXIÓN CURL
// ========================================
$url = "https://pagadetodo.mx/Pagadetodo/Service/GenerarLigaIndi";

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// ENCABEZADOS
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Content-Length: " . strlen($jsonData) 
]);

// EJECUTAR
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

// ========================================
// VALIDAR RESPUESTA
// ========================================
if ($curlError) {
    die("Error en cURL: " . $curlError);
}

echo "<h2>Respuesta del servidor</h2>";
echo "<pre>";
echo "HTTP CODE: " . $httpcode . "\n\n";
print_r(json_decode($response, true));
echo "</pre>";

?>
