<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Procesar formulario cuando se envía
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitizar y validar datos
    $nombre = filter_var(trim($_POST['nombre']), FILTER_SANITIZE_STRING);
    $apellidos = filter_var(trim($_POST['apellidos']), FILTER_SANITIZE_STRING);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $rol_usuario = $_POST['rol_usuario'];
    $activo = isset($_POST['activo']) ? 1 : 0;
    $mostrar_password = isset($_POST['mostrar_password_final']) ? 1 : 0;
    
    // Validaciones básicas
    $errores = [];
    
    if (empty($nombre)) {
        $errores[] = "El nombre es obligatorio.";
    }
    
    if (empty($apellidos)) {
        $errores[] = "Los apellidos son obligatorios.";
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El email no es válido.";
    }
    
    if (strlen($password) < 8) {
        $errores[] = "La contraseña debe tener al menos 8 caracteres.";
    }
    
    // Verificar requisitos de contraseña
    if (!preg_match('/[A-Z]/', $password)) {
        $errores[] = "La contraseña debe contener al menos una letra mayúscula.";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errores[] = "La contraseña debe contener al menos una letra minúscula.";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errores[] = "La contraseña debe contener al menos un número.";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errores[] = "La contraseña debe contener al menos un carácter especial.";
    }
    
    if ($password !== $_POST['confirm_password']) {
        $errores[] = "Las contraseñas no coinciden.";
    }
    
    if (empty($rol_usuario)) {
        $errores[] = "Debe seleccionar un rol para el usuario.";
    }
    
    // Si no hay errores, proceder con la inserción
    if (empty($errores)) {
        try {
            // Crear conexión
            $conn = new mysqli($servername, $username, $password, $dbname);
            
            // Verificar conexión
            if ($conn->connect_error) {
                throw new Exception("Error de conexión: " . $conn->connect_error);
            }
            
            // Verificar si el email ya existe
            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $errores[] = "El email ya está registrado.";
            } else {
                // Encriptar contraseña
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Insertar nuevo usuario
                $stmt = $conn->prepare("INSERT INTO usuarios (nombre, apellidos, email, password, rol_usuario, activo) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssi", $nombre, $apellidos, $email, $password_hash, $rol_usuario, $activo);
                
                if ($stmt->execute()) {
                    $usuario_id = $stmt->insert_id;
                    
                    // Guardar en sesión para mostrar mensaje de éxito
                    $_SESSION['nuevo_usuario_creado'] = true;
                    $_SESSION['usuario_email'] = $email;
                    $_SESSION['usuario_nombre'] = $nombre . ' ' . $apellidos;
                    $_SESSION['usuario_rol'] = $rol_usuario;
                    
                    // Si se debe mostrar la contraseña, guardarla en sesión
                    if ($mostrar_password) {
                        $_SESSION['usuario_password_mostrar'] = $password;
                    }
                    
                    $mensaje = "Usuario creado exitosamente!";
                    $tipo_mensaje = "success";
                    
                    // Redireccionar o limpiar formulario
                    echo "<script>
                        setTimeout(function() {
                            window.location.href = 'lista_usuarios.php';
                        }, 2000);
                    </script>";
                    
                } else {
                    throw new Exception("Error al crear el usuario: " . $stmt->error);
                }
            }
            
            $stmt->close();
            $conn->close();
            
        } catch (Exception $e) {
            $errores[] = "Error en el sistema: " . $e->getMessage();
        }
    }
    
    // Si hay errores, mostrar mensajes
    if (!empty($errores)) {
        $mensaje = implode("<br>", $errores);
        $tipo_mensaje = "danger";
    }
}

// Función para mostrar el rol con formato
function mostrarRol($rol) {
    $roles = [
        'administrador' => '<span class="role-badge role-admin">Administrador</span>',
        'supervisor' => '<span class="role-badge role-supervisor">Supervisor</span>',
        'contador' => '<span class="role-badge role-contador">Contador</span>',
        'empleado' => '<span class="role-badge role-empleado">Empleado</span>'
    ];
    
    return isset($roles[$rol]) ? $roles[$rol] : $rol;
}
?>