<?php
// env_loader.php - Cargador de variables de entorno

class EnvLoader {
    private $envFile;
    private $loaded = false;
    
    public function __construct($envFile = '.env') {
        $this->envFile = $envFile;
    }
    
    public function load() {
        if ($this->loaded) {
            return true;
        }
        
        if (!file_exists($this->envFile)) {
            error_log("⚠️ Archivo .env no encontrado en: " . __DIR__ . '/' . $this->envFile);
            return false;
        }
        
        $lines = file($this->envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parsear variable
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                
                // Remover comillas si existen
                $value = trim($value, '"\'');
                
                // Establecer variable de entorno si no está ya definida
                if (!getenv($key)) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
        
        $this->loaded = true;
        error_log("✅ Variables de entorno cargadas correctamente");
        return true;
    }
}

// Cargar variables de entorno
$envLoader = new EnvLoader(__DIR__ . '/.env');
$envLoader->load();

// Función helper para obtener variables de entorno
function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}
?>