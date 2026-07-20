<?php
// includes/PayPalPayment.php
require_once __DIR__ . '/../config/databasepaypal.php';

class PayPalPayment {
    private $pdo;
    private $table = 'pagos_paypal';
    
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * Guardar un nuevo pago (inicial)
     */
    public function savePayment($paymentData, $cartData = [], $webhookData = null) {
        try {
            $sql = "INSERT INTO {$this->table} 
                    (payment_id, amount, currency, status, payment_data, cart_data, webhook_data) 
                    VALUES 
                    (:payment_id, :amount, :currency, :status, :payment_data, :cart_data, :webhook_data)";
            
            $stmt = $this->pdo->prepare($sql);
            
            // Extraer el monto correctamente
            $amount = 0;
            if (isset($paymentData['transactions'][0]['amount']['total'])) {
                $amount = $paymentData['transactions'][0]['amount']['total'];
            }
            
            $params = [
                ':payment_id' => $paymentData['id'] ?? null,
                ':amount' => $amount,
                ':currency' => $paymentData['transactions'][0]['amount']['currency'] ?? 'MXN',
                ':status' => $paymentData['state'] ?? 'created',
                ':payment_data' => json_encode($paymentData, JSON_UNESCAPED_UNICODE),
                ':cart_data' => json_encode($cartData, JSON_UNESCAPED_UNICODE),
                ':webhook_data' => $webhookData ? json_encode($webhookData, JSON_UNESCAPED_UNICODE) : null
            ];
            
            $stmt->execute($params);
            return $this->pdo->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Error guardando pago PayPal: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Actualizar pago con todos los detalles después de la ejecución
     * ¡ESTE ES EL MÉTODO QUE FALTABA!
     */
    public function updatePaymentWithDetails($paymentId, $updatedPaymentData, $executionResult = null) {
        try {
            // Extraer datos del pagador
            $payerInfo = [];
            $transactionId = null;
            
            // Intentar obtener datos de diferentes fuentes posibles
            if (isset($updatedPaymentData['payer']['payer_info'])) {
                $payerInfo = $updatedPaymentData['payer']['payer_info'];
            } elseif (isset($executionResult['payer']['payer_info'])) {
                $payerInfo = $executionResult['payer']['payer_info'];
            }
            
            // Obtener transaction_id
            if (isset($updatedPaymentData['transactions'][0]['related_resources'][0]['sale']['id'])) {
                $transactionId = $updatedPaymentData['transactions'][0]['related_resources'][0]['sale']['id'];
            } elseif (isset($executionResult['transactions'][0]['related_resources'][0]['sale']['id'])) {
                $transactionId = $executionResult['transactions'][0]['related_resources'][0]['sale']['id'];
            }
            
            // Construir nombre completo
            $payerName = '';
            if (isset($payerInfo['first_name']) || isset($payerInfo['last_name'])) {
                $payerName = trim(($payerInfo['first_name'] ?? '') . ' ' . ($payerInfo['last_name'] ?? ''));
            }
            
            // Obtener email
            $payerEmail = $payerInfo['email'] ?? null;
            
            // Obtener payer_id
            $payerId = $payerInfo['payer_id'] ?? null;
            
            // Preparar datos combinados para payment_data
            $combinedPaymentData = array_merge(
                json_decode(json_encode($updatedPaymentData), true) ?? [],
                $executionResult ? ['execution_result' => $executionResult] : []
            );
            
            $sql = "UPDATE {$this->table} 
                    SET payer_id = :payer_id,
                        payer_email = :payer_email,
                        payer_name = :payer_name,
                        transaction_id = :transaction_id,
                        status = :status,
                        payment_data = :payment_data,
                        updated_at = NOW()
                    WHERE payment_id = :payment_id";
            
            $stmt = $this->pdo->prepare($sql);
            
            $result = $stmt->execute([
                ':payment_id' => $paymentId,
                ':payer_id' => $payerId,
                ':payer_email' => $payerEmail,
                ':payer_name' => $payerName,
                ':transaction_id' => $transactionId,
                ':status' => 'completed',
                ':payment_data' => json_encode($combinedPaymentData, JSON_UNESCAPED_UNICODE)
            ]);
            
            if ($result) {
                error_log("Pago PayPal actualizado correctamente: $paymentId");
            }
            
            return $result;
            
        } catch (PDOException $e) {
            error_log("Error actualizando pago PayPal con detalles: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Actualizar estado del pago (método simple)
     */
    public function updatePaymentStatus($paymentId, $status, $webhookData = null) {
        try {
            $sql = "UPDATE {$this->table} 
                    SET status = :status, 
                        webhook_data = COALESCE(:webhook_data, webhook_data),
                        updated_at = NOW()
                    WHERE payment_id = :payment_id";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':payment_id' => $paymentId,
                ':status' => $status,
                ':webhook_data' => $webhookData ? json_encode($webhookData, JSON_UNESCAPED_UNICODE) : null
            ]);
            
        } catch (PDOException $e) {
            error_log("Error actualizando pago PayPal: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener pago por ID
     */
    public function getPaymentById($paymentId) {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE payment_id = :payment_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':payment_id' => $paymentId]);
            
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($payment) {
                // Decodificar JSON
                $payment['payment_data'] = json_decode($payment['payment_data'], true);
                $payment['cart_data'] = json_decode($payment['cart_data'], true);
                $payment['webhook_data'] = $payment['webhook_data'] ? json_decode($payment['webhook_data'], true) : null;
            }
            
            return $payment;
            
        } catch (PDOException $e) {
            error_log("Error obteniendo pago PayPal: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener pago por ID de transacción
     */
    public function getPaymentByTransactionId($transactionId) {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE transaction_id = :transaction_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':transaction_id' => $transactionId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error obteniendo pago por transaction_id: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener pagos por email
     */
    public function getPaymentsByEmail($email) {
        try {
            $sql = "SELECT * FROM {$this->table} 
                    WHERE payer_email = :email 
                    ORDER BY created_at DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':email' => $email]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error obteniendo pagos por email: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtener pagos por rango de fechas
     */
    public function getPaymentsByDateRange($startDate, $endDate) {
        try {
            $sql = "SELECT * FROM {$this->table} 
                    WHERE DATE(created_at) BETWEEN :start_date AND :end_date 
                    ORDER BY created_at DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error obteniendo pagos por fecha: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtener resumen de pagos
     */
    public function getPaymentSummary($period = 'today') {
        try {
            switch($period) {
                case 'today':
                    $condition = "DATE(created_at) = CURDATE()";
                    break;
                case 'week':
                    $condition = "YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
                    break;
                case 'month':
                    $condition = "MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
                    break;
                default:
                    $condition = "1=1";
            }
            
            $sql = "SELECT 
                        COUNT(*) as total_transacciones,
                        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_completado,
                        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pendiente,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completados,
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pendientes,
                        COUNT(CASE WHEN status = 'failed' THEN 1 END) as fallidos,
                        COUNT(CASE WHEN status = 'created' THEN 1 END) as creados
                    FROM {$this->table} 
                    WHERE {$condition}";
            
            $stmt = $this->pdo->query($sql);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error obteniendo resumen de pagos: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Verificar si un pago existe
     */
    public function paymentExists($paymentId) {
        try {
            $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE payment_id = :payment_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':payment_id' => $paymentId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['total'] > 0;
            
        } catch (PDOException $e) {
            error_log("Error verificando pago: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Eliminar pago (soft delete o hard delete según necesidad)
     */
    public function deletePayment($paymentId, $softDelete = true) {
        try {
            if ($softDelete) {
                // Soft delete - solo marcar como eliminado
                $sql = "UPDATE {$this->table} 
                        SET status = 'deleted', updated_at = NOW() 
                        WHERE payment_id = :payment_id";
            } else {
                // Hard delete - eliminar físicamente
                $sql = "DELETE FROM {$this->table} WHERE payment_id = :payment_id";
            }
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':payment_id' => $paymentId]);
            
        } catch (PDOException $e) {
            error_log("Error eliminando pago: " . $e->getMessage());
            return false;
        }
    }
}
?>