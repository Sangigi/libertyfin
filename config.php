<?php
// config.php - Configuración centralizada del sistema

// Cargar variables de entorno
require_once __DIR__ . '/env_loader.php';

class Config {
    private static $instance = null;
    private $config = [];
    
    private function __construct() {
        // Cargar configuración desde variables de entorno
        $this->config = [
            // Database Configuration
            'db' => [
                'servername' => getenv('DB_SERVERNAME') ?: 'libertyfin.com.mx',
                'username' => getenv('DB_USERNAME') ?: 'juanc141_alexis',
                'password' => getenv('DB_PASSWORD') ?: '',
                'db_main' => getenv('DB_MAIN') ?: 'juanc141_ventas'
            ],
            
            // Facturapi Configuration
            'facturapi' => [
                'api_key' => getenv('FACTURAPI_API_KEY') ?: ''
            ],
            
            // cPanel API Configuration
            'cpanel' => [
                'host' => getenv('CPANEL_HOST') ?: 'libertyfin.com.mx',
                'user' => getenv('CPANEL_USER') ?: '',
                'api_token' => getenv('CPANEL_API_TOKEN') ?: ''
            ],
            
            // SMTP Configuration
            'smtp' => [
                'host' => getenv('SMTP_HOST') ?: 'smtp.titan.email',
                'username' => getenv('SMTP_USERNAME') ?: '',
                'password' => getenv('SMTP_PASSWORD') ?: '',
                'port' => getenv('SMTP_PORT') ?: 465
            ],
            
            // Application Configuration
            'app' => [
                'name' => getenv('APP_NAME') ?: 'LibertyFin',
                'env' => getenv('APP_ENV') ?: 'production',
                'upload_dir' => getenv('UPLOAD_DIR') ?: 'uploads/',
                'timezone' => 'America/Mexico_City'
            ],
            
            // ==========================================
            // CONFIGURACIÓN SPEI - NUEVA
            // ==========================================
            'spei' => [
                'user' => getenv('SPEI_USER') ?: '',
                'password' => getenv('SPEI_PASSWORD') ?: '',
                'integration_id' => getenv('SPEI_INTEGRATION_ID') ?: '',
                'business_id' => getenv('SPEI_BUSINESS_ID') ?: '',
                'url_generar' => getenv('SPEI_URL_GENERAR') ?: 'https://pagadetodo.mx/Pagadetodo/Service/GenerarClabeIndi',
                'url_sandbox' => getenv('SPEI_URL_SANDBOX') ?: 'https://pagadetodo.mx/Sandbox/Login.aspx',
                'timeout' => (int) (getenv('SPEI_TIMEOUT') ?: 30),
                'dias_vigencia' => (int) (getenv('SPEI_DIAS_VIGENCIA') ?: 1)
            ]
        ];
        
        // Establecer zona horaria
        date_default_timezone_set($this->config['app']['timezone']);
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get($key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    public function getDBConfig() {
        return $this->config['db'];
    }
    
    public function getFacturapiConfig() {
        return $this->config['facturapi'];
    }
    
    public function getCpanelConfig() {
        return $this->config['cpanel'];
    }
    
    public function getSmtpConfig() {
        return $this->config['smtp'];
    }
    
    public function getAppConfig() {
        return $this->config['app'];
    }
    
    // ==========================================
    // NUEVO: Obtener configuración SPEI
    // ==========================================
    public function getSpeiConfig() {
        return $this->config['spei'];
    }
}

// Función helper para acceder a la configuración fácilmente
function config($key, $default = null) {
    return Config::getInstance()->get($key, $default);
}

// Función helper para obtener configuración SPEI
function speiConfig($key = null, $default = null) {
    $config = Config::getInstance()->getSpeiConfig();
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? $default;
}
?>