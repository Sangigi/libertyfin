<?php

$url = "https://ws.terecargamos.com:8448/soap/servlet/rpcrouter";

$xml = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope 
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:urn="urn:debisys-soap-services">
   <soapenv:Header/>
   <soapenv:Body>
      <urn:executeCommand soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
         <command xsi:type="xsd:string">ProductFlowInfoService</command>
         <parameters xsi:type="xsd:string">{
            "version":"1",
            "terminalId":"9478382",
            "invoiceNo":"00005",
            "language":"1",
            "clerkId":"UFJ45M"
         }</parameters>
      </urn:executeCommand>
   </soapenv:Body>
</soapenv:Envelope>';

// 1. User-Agent como Postman
$userAgent = "PostmanRuntime/7.28.4";

// 2. SOAPAction con el mismo valor que usa Postman (prueba con "urn:executeCommand")
$soapAction = "urn:executeCommand";  // o "urn:debisys-soap-services#executeCommand"

$headers = [
    "Content-Type: text/xml; charset=utf-8",
    "SOAPAction: \"$soapAction\"",
    "Content-Length: " . strlen($xml),
    "Accept: */*",
    "Accept-Encoding: gzip, deflate, br"
];

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

// 3. Habilita la verificación SSL (igual que Postman)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// 4. Opcional: si tu servidor no tiene el CA bundle actualizado, usa el de Mozilla
// curl_setopt($ch, CURLOPT_CAINFO, "/path/to/cacert.pem");

curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "Error CURL: " . curl_error($ch);
} else {
    // Muestra también el código de respuesta HTTP para depuración
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "<h3>HTTP Code: $httpCode</h3>";
    echo "<h3>Respuesta del servidor:</h3>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

curl_close($ch);
?>