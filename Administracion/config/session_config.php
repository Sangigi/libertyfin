<?php
// =============================================
// CONFIGURACIÓN DE SESIÓN - REUTILIZABLE
// =============================================

// Definir la ruta personalizada para sesiones
function iniciarSesionPersonalizada() {
    $custom_session_path = '/home2/juanc141/tmp_sessions';
    
    // Crear el directorio si no existe
    if (!is_dir($custom_session_path)) {
        mkdir($custom_session_path, 0777, true);
    }
    
    // Establecer la ruta de sesión
    session_save_path($custom_session_path);
    
    // Iniciar la sesión si no está activa
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// =============================================
// FUNCIONES DE VERIFICACIÓN DE SESIÓN
// =============================================

function verificarSesion() {
    // Verificar si el usuario está logueado
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: login.php");
        exit();
    }
}

function obtenerUsuario() {
    return [
        'nombre' => $_SESSION['usuario_nombre'] ?? 'Administrador',
        'rol' => $_SESSION['usuario_rol'] ?? 'admin',
        'id' => $_SESSION['usuario_id'] ?? null,
        'email' => $_SESSION['usuario_email'] ?? null
    ];
}

function esAdmin() {
    return ($_SESSION['usuario_rol'] ?? '') === 'administrador';
}

function tienePermiso($rol_requerido) {
    $usuario_rol = $_SESSION['usuario_rol'] ?? '';
    $roles_permisos = [
        'administrador' => ['administrador'],
        'supervisor' => ['administrador', 'supervisor'],
        'empleado' => ['administrador', 'supervisor', 'empleado'],
        'contador' => ['administrador', 'supervisor', 'contador']
    ];
    
    return in_array($usuario_rol, $roles_permisos[$rol_requerido] ?? []);
}

// =============================================
// FUNCIONES DE CIERRE DE SESIÓN
// =============================================

function cerrarSesion() {
    // Limpiar todas las variables de sesión
    $_SESSION = array();
    
    // Eliminar la cookie de sesión
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destruir la sesión
    session_destroy();
    
    // Redirigir al login
    header("Location: Login");
    exit();
}

// =============================================
// FUNCIONES DE SEGURIDAD ADICIONAL
// =============================================

function regenerarSesion() {
    session_regenerate_id(true);
}

function establecerTiempoSesion($segundos = 3600) {
    ini_set('session.gc_maxlifetime', $segundos);
    ini_set('session.cookie_lifetime', $segundos);
}

function validarCSRF() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Error de seguridad: Token CSRF inválido');
    }
    return true;
}

function generarCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// =============================================
// FUNCIONES DE ACTIVIDAD DE USUARIO
// =============================================

function registrarActividad($accion, $detalles = null) {
    $usuario_id = $_SESSION['usuario_id'] ?? null;
    $usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Desconocido';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $log = sprintf(
        "[%s] Usuario: %s (ID: %s) - IP: %s - Acción: %s - Detalles: %s",
        date('Y-m-d H:i:s'),
        $usuario_nombre,
        $usuario_id ?? 'N/A',
        $ip,
        $accion,
        $detalles ?? 'Sin detalles'
    );
    
    error_log($log);
}

// =============================================
// FUNCIÓN DE INICIALIZACIÓN COMPLETA
// =============================================

function inicializarSesion() {
    // Iniciar sesión personalizada
    iniciarSesionPersonalizada();
    
    // Establecer tiempo de sesión (8 horas)
    establecerTiempoSesion(28800);
    
    // Verificar que la sesión sea válida
    verificarSesion();
    
    // Regenerar ID periódicamente (por seguridad)
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
        regenerarSesion();
        $_SESSION['last_regeneration'] = time();
    }
}