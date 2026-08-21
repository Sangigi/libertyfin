<?php
// Service/PayPalService.php

class PayPalService {
    private $clientId;
    private $secret;
    private $mode;
    private $accessToken;
    private $tokenExpires;

    public function __construct($clientId, $secret, $mode = 'sandbox') {
        $this->clientId = $clientId;
        $this->secret = $secret;
        $this->mode = $mode;
        $this->accessToken = null;
        $this->tokenExpires = 0;
    }

    private function getBaseUrl() {
        return $this->mode === 'live' 
            ? 'https://api-m.paypal.com' 
            : 'https://api-m.sandbox.paypal.com';
    }

    private function getAuthHeader() {
        return 'Basic ' . base64_encode($this->clientId . ':' . $this->secret);
    }

    private function getAccessToken() {
        if ($this->accessToken && time() < $this->tokenExpires) {
            return $this->accessToken;
        }

        $ch = curl_init($this->getBaseUrl() . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->getAuthHeader(),
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Error al obtener token de PayPal: ' . $response);
        }

        $data = json_decode($response, true);
        $this->accessToken = $data['access_token'];
        $this->tokenExpires = time() + ($data['expires_in'] - 60);
        return $this->accessToken;
    }

    private function request($method, $endpoint, $data = null) {
        $token = $this->getAccessToken();
        $url = $this->getBaseUrl() . $endpoint;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception('Error en PayPal API: ' . $response . ' (HTTP ' . $httpCode . ')');
        }

        return json_decode($response, true);
    }

    /**
     * Crea una orden de pago en PayPal
     */
    public function createOrder($amount, $currency = 'MXN', $reference = null, $items = [], $returnUrl = null, $cancelUrl = null) {
        if (!$returnUrl) {
            $returnUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                         $_SERVER['HTTP_HOST'] . '/paypal_return.php';
        }
        if (!$cancelUrl) {
            $cancelUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                         $_SERVER['HTTP_HOST'] . '/paypal_cancel.php';
        }

        $data = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $reference ?: 'VENTA_' . time(),
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($amount, 2, '.', '')
                    ],
                    'description' => 'Pago en caja - ' . ($reference ?: 'Orden #' . time())
                ]
            ],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'brand_name' => 'Mi Tienda',
                'user_action' => 'PAY_NOW'
            ]
        ];

        // Agregar items si se proporcionan
        if (!empty($items)) {
            $data['purchase_units'][0]['items'] = $items;
        }

        $result = $this->request('POST', '/v2/checkout/orders', $data);
        return $result;
    }

    /**
     * Captura una orden de PayPal (cobro final)
     */
    public function captureOrder($orderId) {
        $result = $this->request('POST', '/v2/checkout/orders/' . $orderId . '/capture');
        return $result;
    }

    /**
     * Obtiene detalles de una orden
     */
    public function getOrderDetails($orderId) {
        $result = $this->request('GET', '/v2/checkout/orders/' . $orderId);
        return $result;
    }

    /**
     * Verifica el estado de una orden
     */
    public function getOrderStatus($orderId) {
        $order = $this->getOrderDetails($orderId);
        return $order['status'] ?? 'UNKNOWN';
    }

    /**
     * Cancela una orden
     */
    public function cancelOrder($orderId) {
        $result = $this->request('POST', '/v2/checkout/orders/' . $orderId . '/cancel');
        return $result;
    }
}