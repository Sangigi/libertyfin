<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

// usuarios.php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';
$usuarios = [];

// Procesar operaciones CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        die("Error de conexión: " . $conn->connect_error);
    }
    
    // Crear nuevo usuario
    if (isset($_POST['crear_usuario'])) {
        $nombre = $conn->real_escape_string(trim($_POST['nombre']));
        $apellidos = $conn->real_escape_string(trim($_POST['apellidos']));
        $email = $conn->real_escape_string(trim($_POST['email']));
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $rol_usuario = $conn->real_escape_string($_POST['rol_usuario']);
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        $sql = "INSERT INTO usuarios (nombre, apellidos, email, password, rol_usuario, activo) 
                VALUES ('$nombre', '$apellidos', '$email', '$password', '$rol_usuario', $activo)";
        
        if ($conn->query($sql) === TRUE) {
            $mensaje = "Usuario creado exitosamente";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al crear usuario: " . $conn->error;
            $tipo_mensaje = "danger";
        }
    }
    
    // Actualizar usuario
    if (isset($_POST['actualizar_usuario'])) {
        $id = intval($_POST['id']);
        $nombre = $conn->real_escape_string(trim($_POST['nombre']));
        $apellidos = $conn->real_escape_string(trim($_POST['apellidos']));
        $email = $conn->real_escape_string(trim($_POST['email']));
        $rol_usuario = $conn->real_escape_string($_POST['rol_usuario']);
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        $sql = "UPDATE usuarios SET 
                nombre = '$nombre',
                apellidos = '$apellidos',
                email = '$email',
                rol_usuario = '$rol_usuario',
                activo = $activo
                WHERE id = $id";
        
        // Si se proporcionó nueva contraseña
        if (!empty($_POST['password'])) {
            $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
            $sql = "UPDATE usuarios SET 
                    nombre = '$nombre',
                    apellidos = '$apellidos',
                    email = '$email',
                    password = '$password',
                    rol_usuario = '$rol_usuario',
                    activo = $activo
                    WHERE id = $id";
        }
        
        if ($conn->query($sql) === TRUE) {
            $mensaje = "Usuario actualizado exitosamente";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al actualizar usuario: " . $conn->error;
            $tipo_mensaje = "danger";
        }
    }
    
    // Eliminar usuario
    if (isset($_POST['eliminar_usuario'])) {
        $id = intval($_POST['id']);
        
        // No permitir eliminar al propio usuario
        if ($id == $_SESSION['usuario_id']) {
            $mensaje = "No puedes eliminar tu propio usuario";
            $tipo_mensaje = "warning";
        } else {
            $sql = "DELETE FROM usuarios WHERE id = $id";
            
            if ($conn->query($sql) === TRUE) {
                $mensaje = "Usuario eliminado exitosamente";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al eliminar usuario: " . $conn->error;
                $tipo_mensaje = "danger";
            }
        }
    }
    
    // Bloquear/Desbloquear usuario
    if (isset($_POST['toggle_bloqueo'])) {
        $id = intval($_POST['id']);
        
        // No permitir bloquear al propio usuario
        if ($id == $_SESSION['usuario_id']) {
            $mensaje = "No puedes bloquear tu propio usuario";
            $tipo_mensaje = "warning";
        } else {
            // Obtener estado actual
            $sql = "SELECT bloqueado FROM usuarios WHERE id = $id";
            $result = $conn->query($sql);
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $nuevo_estado = $row['bloqueado'] ? 0 : 1;
                
                $sql = "UPDATE usuarios SET bloqueado = $nuevo_estado WHERE id = $id";
                if ($conn->query($sql) === TRUE) {
                    $mensaje = $nuevo_estado ? "Usuario bloqueado" : "Usuario desbloqueado";
                    $tipo_mensaje = "success";
                }
            }
        }
    }
    
    // Resetear intentos de login
    if (isset($_POST['reset_intentos'])) {
        $id = intval($_POST['id']);
        
        $sql = "UPDATE usuarios SET intentos_login = 0, bloqueado = 0 WHERE id = $id";
        if ($conn->query($sql) === TRUE) {
            $mensaje = "Intentos de login reseteados";
            $tipo_mensaje = "success";
        }
    }
    
    $conn->close();
}

// Obtener lista de usuarios
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Filtros
$filtro_rol = isset($_GET['rol']) ? $_GET['rol'] : '';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 10;

// Construir consulta con filtros
$sql_where = "WHERE 1=1";
$params = [];
$types = "";

if (!empty($filtro_rol) && $filtro_rol !== 'todos') {
    $sql_where .= " AND rol_usuario = ?";
    $params[] = $filtro_rol;
    $types .= "s";
}

if (!empty($filtro_estado) && $filtro_estado !== 'todos') {
    if ($filtro_estado === 'activo') {
        $sql_where .= " AND activo = 1";
    } elseif ($filtro_estado === 'inactivo') {
        $sql_where .= " AND activo = 0";
    } elseif ($filtro_estado === 'bloqueado') {
        $sql_where .= " AND bloqueado = 1";
    }
}

if (!empty($filtro_busqueda)) {
    $sql_where .= " AND (nombre LIKE ? OR apellidos LIKE ? OR email LIKE ?)";
    $busqueda_param = "%" . $filtro_busqueda . "%";
    $params[] = $busqueda_param;
    $params[] = $busqueda_param;
    $params[] = $busqueda_param;
    $types .= "sss";
}

// Contar total de registros
$sql_count = "SELECT COUNT(*) as total FROM usuarios $sql_where";
$stmt_count = $conn->prepare($sql_count);

if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}

$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_registros = $result_count->fetch_assoc()['total'];
$stmt_count->close();

// Calcular paginación
$total_paginas = ceil($total_registros / $registros_por_pagina);
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Consulta principal
$sql = "SELECT * FROM usuarios $sql_where ORDER BY fecha_registro DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $types .= "ii";
    $params[] = $registros_por_pagina;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param("ii", $registros_por_pagina, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $usuarios[] = $row;
}

$stmt->close();
$conn->close();

// Funciones auxiliares
function formatearFecha($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'Nunca';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

function getRolNombre($rol) {
    $roles = [
        'administrador' => 'Administrador',
        'empleado' => 'Empleado',
        'supervisor' => 'Supervisor',
        'contador' => 'Contador'
    ];
    return $roles[$rol] ?? $rol;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Panel de Administración</title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            will-change: transform;
        }

        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        /* Botón hamburguesa para móvil */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem;
            margin-right: 1rem;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-backdrop.show {
            display: block;
            opacity: 1;
        }

        /* Mejoras para el sidebar táctil */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 56px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            }

            .sidebar.show {
                transform: translateX(0);
                box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
                transition: transform 0.3s ease-out;
            }

            body.sidebar-open main {
                transform: translateX(280px);
            }
        }

        .badge-rol {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .badge-administrador { background-color: #dc3545; color: white; }
        .badge-supervisor { background-color: #fd7e14; color: white; }
        .badge-empleado { background-color: #6f42c1; color: white; }
        .badge-contador { background-color: #20c997; color: white; }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch; /* Scroll suave en iOS */
            overscroll-behavior-x: contain; /* Previene scroll en el body cuando se llega al límite */
            scrollbar-width: thin;
            scrollbar-color: #27ae60 #f1f1f1;
        }
        
        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #27ae60;
            border-radius: 4px;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }

        .form-group {
            position: relative;
        }

        /* Mejoras para estadísticas en móvil */
        @media (max-width: 575.98px) {
            .col-md-2 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .col-md-4 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }

        /* Para el logo en el navbar */
        .navbar-brand img {
            height: 30px;
            width: auto;
        }

        /* Estilos para imágenes responsivas */
        .img-logo-navbar {
            height: 30px;
            width: auto;
            max-height: 100%;
        }

        /* Estilos para scroll táctil en tabla */
        .table-responsive {
            position: relative;
            scroll-behavior: smooth;
        }
        
        /* Feedback visual durante el scroll */
        .table-responsive.touch-active {
            cursor: grab;
        }
        
        .table-responsive.touch-scrolling {
            cursor: grabbing;
        }
        
        /* Botones de navegación para scroll */
        .scroll-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(39, 174, 96, 0.8);
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            opacity: 0;
            transition: opacity 0.3s, background 0.3s;
        }
        
        .scroll-btn:hover {
            background: rgba(39, 174, 96, 1);
        }
        
        .scroll-btn-left {
            left: 10px;
        }
        
        .scroll-btn-right {
            right: 10px;
        }
        
        .table-responsive:hover .scroll-btn {
            opacity: 1;
        }
        
        /* Solo mostrar botones en escritorio */
        @media (max-width: 767.98px) {
            .scroll-btn {
                display: none;
            }
            
            .table-responsive {
                touch-action: pan-x pan-y; /* Permite scroll horizontal y vertical */
            }
        }
        
        /* Para móviles */
        @media (max-width: 767.98px) {
            .img-logo-navbar {
                height: 25px;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../images/LibertyfinBlanco.png" alt="Logo" class="me-2" style="height: 30px;">
                <span>Panel de Administración</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="empresas.php">
                                <i class="fas fa-building"></i>
                                Empresas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="activaciones.php">
                                <i class="fas fa-history"></i>
                                Activaciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="usuarios.php">
                                <i class="fas fa-user-cog"></i>
                                Usuarios Admin
                            </a>
                        </li>
                        
                          <li class="nav-item">
                            <a class="nav-link" href="solicitudes.php">
                                <i class="fas fa-user-cog"></i>
                               Solicitudes
                            </a>
                        </li>
                         <li class="nav-item">
                            <a class="nav-link" href="distribuidores.php">
                                <i class="fas fa-users"></i>
                                Distribuidores
                            </a>
                        </li>
                         <li class="nav-item">
                            <a class="nav-link" href="pagos.php">
                                <i class="fas fa-money-bill-wave"></i>
                                Pagos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ActivacionesCaracteristicas.php">
                                <i class="fas fa-sliders-h"></i>
                                Características
                            </a>
                        </li>
                        <!-- <li class="nav-item">
                            <a class="nav-link" href="#">
                                <i class="fas fa-cogs"></i>
                                Configuración
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li> -->
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-user-cog me-2"></i>Gestión de Usuarios
                    </h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
                        <i class="fas fa-plus-circle me-1"></i>Nuevo Usuario
                    </button>
                </div>
                
                <!-- Mensajes -->
                <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Rol:</label>
                                <select name="rol" class="form-select">
                                    <option value="todos" <?php echo $filtro_rol === 'todos' ? 'selected' : ''; ?>>Todos los roles</option>
                                    <option value="administrador" <?php echo $filtro_rol === 'administrador' ? 'selected' : ''; ?>>Administrador</option>
                                    <option value="supervisor" <?php echo $filtro_rol === 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                                    <option value="empleado" <?php echo $filtro_rol === 'empleado' ? 'selected' : ''; ?>>Empleado</option>
                                    <option value="contador" <?php echo $filtro_rol === 'contador' ? 'selected' : ''; ?>>Contador</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado:</label>
                                <select name="estado" class="form-select">
                                    <option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
                                    <option value="activo" <?php echo $filtro_estado === 'activo' ? 'selected' : ''; ?>>Activo</option>
                                    <option value="inactivo" <?php echo $filtro_estado === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                                    <option value="bloqueado" <?php echo $filtro_estado === 'bloqueado' ? 'selected' : ''; ?>>Bloqueado</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Buscar:</label>
                                <input type="text" name="busqueda" class="form-control" 
                                       placeholder="Nombre, apellidos o email..." 
                                       value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-grid gap-2 d-md-flex">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="fas fa-search me-1"></i>Filtrar
                                    </button>
                                    <a href="usuarios.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i>Limpiar
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Tabla de Usuarios -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Usuarios del Sistema</h5>
                        <span class="badge bg-primary"><?php echo $total_registros; ?> usuarios</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Rol</th>
                                        <th>Estado</th>
                                        <th>Registro</th>
                                        <th>Último Acceso</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($usuarios)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <div class="text-muted">
                                                <i class="fas fa-users display-6 d-block mb-3"></i>
                                                No se encontraron usuarios
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($usuarios as $usuario): ?>
                                        <tr>
                                            <td><?php echo $usuario['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellidos']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $usuario['rol_usuario']; ?>">
                                                    <?php echo getRolNombre($usuario['rol_usuario']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($usuario['bloqueado']): ?>
                                                    <span class="badge bg-danger">Bloqueado</span>
                                                <?php elseif (!$usuario['activo']): ?>
                                                    <span class="badge bg-secondary">Inactivo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Activo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo formatearFecha($usuario['fecha_registro']); ?></td>
                                            <td><?php echo formatearFecha($usuario['ultimo_acceso']); ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-info" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#modalEditarUsuario"
                                                            data-id="<?php echo $usuario['id']; ?>"
                                                            data-nombre="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                                                            data-apellidos="<?php echo htmlspecialchars($usuario['apellidos']); ?>"
                                                            data-email="<?php echo htmlspecialchars($usuario['email']); ?>"
                                                            data-rol="<?php echo $usuario['rol_usuario']; ?>"
                                                            data-activo="<?php echo $usuario['activo']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <?php if ($usuario['id'] != $_SESSION['usuario_id']): ?>
                                                        <?php if ($usuario['bloqueado']): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                                            <button type="submit" name="toggle_bloqueo" class="btn btn-sm btn-warning">
                                                                <i class="fas fa-unlock"></i>
                                                            </button>
                                                        </form>
                                                        <?php else: ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                                            <button type="submit" name="toggle_bloqueo" class="btn btn-sm btn-warning">
                                                                <i class="fas fa-lock"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($usuario['intentos_login'] > 0): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                                            <button type="submit" name="reset_intentos" class="btn btn-sm btn-secondary" 
                                                                    title="Resetear intentos de login">
                                                                <i class="fas fa-redo"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                        
                                                        <button class="btn btn-sm btn-danger" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#modalEliminarUsuario"
                                                                data-id="<?php echo $usuario['id']; ?>"
                                                                data-nombre="<?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellidos']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Paginación -->
                        <?php if ($total_paginas > 1): ?>
                        <nav aria-label="Paginación">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" 
                                       href="?pagina=<?php echo $pagina_actual - 1; ?>&rol=<?php echo $filtro_rol; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                    <a class="page-link" 
                                       href="?pagina=<?php echo $i; ?>&rol=<?php echo $filtro_rol; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                    <a class="page-link" 
                                       href="?pagina=<?php echo $pagina_actual + 1; ?>&rol=<?php echo $filtro_rol; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal Nuevo Usuario -->
    <div class="modal fade" id="modalNuevoUsuario" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre *</label>
                            <input type="text" name="nombre" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Apellidos *</label>
                            <input type="text" name="apellidos" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contraseña *</label>
                            <div class="form-group">
                                <input type="password" name="password" id="password" class="form-control" required 
                                       minlength="6">
                                <span class="password-toggle" onclick="togglePassword('password')">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <small class="text-muted">Mínimo 6 caracteres</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rol *</label>
                            <select name="rol_usuario" class="form-select" required>
                                <option value="empleado">Empleado</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="contador">Contador</option>
                                <option value="administrador">Administrador</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="activo" class="form-check-input" id="activo" checked>
                            <label class="form-check-label" for="activo">Usuario activo</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="crear_usuario" class="btn btn-primary">Crear Usuario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Usuario -->
    <div class="modal fade" id="modalEditarUsuario" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre *</label>
                            <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Apellidos *</label>
                            <input type="text" name="apellidos" id="edit_apellidos" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nueva Contraseña (dejar vacío para no cambiar)</label>
                            <div class="form-group">
                                <input type="password" name="password" id="edit_password" class="form-control"
                                       minlength="6">
                                <span class="password-toggle" onclick="togglePassword('edit_password')">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <small class="text-muted">Mínimo 6 caracteres</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rol *</label>
                            <select name="rol_usuario" id="edit_rol" class="form-select" required>
                                <option value="empleado">Empleado</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="contador">Contador</option>
                                <option value="administrador">Administrador</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="activo" class="form-check-input" id="edit_activo">
                            <label class="form-check-label" for="edit_activo">Usuario activo</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="actualizar_usuario" class="btn btn-primary">Actualizar Usuario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Eliminar Usuario -->
    <div class="modal fade" id="modalEliminarUsuario" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-body">
                        <p>¿Está seguro de eliminar al usuario <strong id="delete_nombre"></strong>?</p>
                        <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Esta acción no se puede deshacer.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="eliminar_usuario" class="btn btn-danger">Eliminar Usuario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // =============================================
        // FUNCIONALIDAD DE SWIPE AUTOMÁTICO PARA SIDEBAR
        // =============================================

        // Variables para controlar el swipe
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        let isSidebarTouch = false;
        const SWIPE_THRESHOLD = 50; // Mínimo de píxeles para considerar un swipe
        const SWIPE_EDGE_ZONE = 30; // Zona del borde donde se activa el swipe
        const VERTICAL_THRESHOLD = 30; // Máxima desviación vertical permitida

        // Función para abrir el sidebar automáticamente
        function openSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.add('show');
                sidebarBackdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        // Función para cerrar el sidebar automáticamente
        function closeSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        // =============================================
        // FUNCIONALIDAD DE SWIPE HORIZONTAL PARA SCROLL DE TABLA
        // =============================================

        let tableTouchStartX = 0;
        let tableTouchStartY = 0;
        let tableTouchEndX = 0;
        let tableTouchEndY = 0;
        let tableIsScrolling = false;
        let tableTouchStartTime = 0;
        let currentTableContainer = null;

        // Función para detectar si estamos dentro de la tabla
        function isInsideTable(element) {
            while (element) {
                if (element.classList && element.classList.contains('table-responsive')) {
                    return true;
                }
                if (element.classList && element.classList.contains('table')) {
                    return true;
                }
                if (element.classList && element.classList.contains('table-hover')) {
                    return true;
                }
                if (element.tagName === 'TD' || element.tagName === 'TH' || element.tagName === 'TR' || 
                    element.tagName === 'TBODY' || element.tagName === 'THEAD') {
                    return true;
                }
                element = element.parentElement;
            }
            return false;
        }

        // Detectar inicio del touch en cualquier parte
        document.addEventListener('touchstart', function(e) {
            // Solo en dispositivos móviles
            if (window.innerWidth >= 768) return;
            
            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;
            
            // Verificar si es para el sidebar (tocar cerca del borde izquierdo y NO en la tabla)
            if (touchX <= SWIPE_EDGE_ZONE && !isInsideTable(e.target)) {
                isSidebarTouch = true;
                touchStartX = touchX;
                touchStartY = touchY;
                touchEndX = touchStartX;
                touchEndY = touchStartY;
            }
            
            // Verificar si es para la tabla
            if (isInsideTable(e.target)) {
                tableTouchStartX = touchX;
                tableTouchStartY = touchY;
                tableTouchEndX = tableTouchStartX;
                tableTouchEndY = tableTouchStartY;
                tableIsScrolling = false;
                tableTouchStartTime = Date.now();
                
                // Obtener el contenedor de tabla
                currentTableContainer = e.target.closest('.table-responsive');
                if (currentTableContainer) {
                    currentTableContainer.classList.add('touch-active');
                }
            }
        }, { passive: true });

        // Detectar movimiento del touch - SIN preventDefault
        document.addEventListener('touchmove', function(e) {
            // Solo procesar en dispositivos móviles
            if (window.innerWidth >= 768) return;
            
            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;
            
            // Procesar movimiento para tabla
            if (tableTouchStartX > 0 && isInsideTable(e.target) && currentTableContainer) {
                tableTouchEndX = touchX;
                tableTouchEndY = touchY;
                
                const deltaX = tableTouchEndX - tableTouchStartX;
                const deltaY = tableTouchEndY - tableTouchStartY;
                
                // Determinar si es movimiento horizontal o vertical
                const isHorizontalScroll = Math.abs(deltaX) > Math.abs(deltaY);
                
                if (isHorizontalScroll && Math.abs(deltaX) > 5) {
                    tableIsScrolling = true;
                    
                    // Agregar clase para feedback visual
                    currentTableContainer.classList.add('touch-scrolling');
                    
                    // Calcular nueva posición de scroll basada en el movimiento
                    const scrollAmount = -deltaX * 0.8;
                    const newScrollLeft = currentTableContainer.scrollLeft + scrollAmount;
                    
                    // Verificar límites
                    const maxScroll = currentTableContainer.scrollWidth - currentTableContainer.clientWidth;
                    const boundedScrollLeft = Math.max(0, Math.min(maxScroll, newScrollLeft));
                    
                    // Aplicar el scroll
                    currentTableContainer.scrollLeft = boundedScrollLeft;
                    
                    // Actualizar posición inicial para movimiento continuo
                    tableTouchStartX = touchX;
                    tableTouchStartY = touchY;
                }
            }
            
            // Procesar movimiento para sidebar
            if (isSidebarTouch) {
                touchEndX = touchX;
                touchEndY = touchY;
            }
        }, { passive: true });

        // Detectar fin del touch
        document.addEventListener('touchend', function(e) {
            // Solo en dispositivos móviles
            if (window.innerWidth >= 768) return;
            
            // Procesar fin de touch para sidebar
            if (isSidebarTouch) {
                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;

                // Verificar que sea un swipe horizontal válido
                if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
                    // Limpiar variables
                    isSidebarTouch = false;
                    touchStartX = 0;
                    touchStartY = 0;
                    touchEndX = 0;
                    touchEndY = 0;
                    return;
                }

                const sidebar = document.getElementById('sidebar');
                const isSidebarOpen = sidebar && sidebar.classList.contains('show');

                // SWIPE DE IZQUIERDA A DERECHA (para abrir)
                if (deltaX > SWIPE_THRESHOLD) {
                    // Solo abrir si empezó cerca del borde izquierdo
                    if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                        openSidebarAuto();
                    }
                }
                // SWIPE DE DERECHA A IZQUIERDA (para cerrar)
                else if (deltaX < -SWIPE_THRESHOLD) {
                    // Cerrar si el sidebar está abierto
                    if (isSidebarOpen) {
                        closeSidebarAuto();
                    }
                }
                
                // Limpiar variables
                isSidebarTouch = false;
                touchStartX = 0;
                touchStartY = 0;
                touchEndX = 0;
                touchEndY = 0;
            }
            
            // Procesar fin de touch para tabla
            if (tableTouchStartX > 0 && tableIsScrolling && currentTableContainer) {
                // Calcular velocidad para inercia
                const timeDelta = Date.now() - tableTouchStartTime;
                const deltaX = tableTouchEndX - tableTouchStartX;
                const velocity = deltaX / Math.max(timeDelta, 1);
                
                // Aplicar inercia solo si hay velocidad significativa
                if (Math.abs(velocity) > 0.3) {
                    const inertiaDistance = velocity * 80;
                    const currentScroll = currentTableContainer.scrollLeft;
                    const maxScroll = currentTableContainer.scrollWidth - currentTableContainer.clientWidth;
                    
                    // Calcular scroll final con límites
                    const targetScroll = currentScroll - inertiaDistance;
                    const boundedTargetScroll = Math.max(0, Math.min(maxScroll, targetScroll));
                    
                    // Aplicar scroll con animación suave
                    currentTableContainer.scrollTo({
                        left: boundedTargetScroll,
                        behavior: 'smooth'
                    });
                }
                
                // Remover clases después de un delay
                const containerRef = currentTableContainer;
                setTimeout(() => {
                    if (containerRef && containerRef.classList) {
                        containerRef.classList.remove('touch-scrolling');
                        containerRef.classList.remove('touch-active');
                    }
                }, 300);
                
                // Limpiar variables después de un delay adicional
                setTimeout(() => {
                    currentTableContainer = null;
                }, 350);
            }
            
            // Resetear variables de tabla
            tableTouchStartX = 0;
            tableTouchStartY = 0;
            tableTouchEndX = 0;
            tableTouchEndY = 0;
            tableIsScrolling = false;
            tableTouchStartTime = 0;
        }, { passive: true });

        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            // Función para mostrar/ocultar sidebar
            function toggleSidebar() {
                if (sidebar.classList.contains('show')) {
                    closeSidebarAuto();
                } else {
                    openSidebarAuto();
                }
            }

            // Event listeners para el botón hamburguesa
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebarAuto);
            }

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebarAuto();
                    }
                });
            });

            // Agregar botones de navegación para scroll en la tabla
            function addTableNavigationButtons() {
                const tableResponsives = document.querySelectorAll('.table-responsive');
                
                tableResponsives.forEach(container => {
                    // Verificar si ya tiene botones
                    if (container.querySelector('.scroll-btn')) return;
                    
                    // Crear botones
                    const leftBtn = document.createElement('button');
                    leftBtn.className = 'scroll-btn scroll-btn-left';
                    leftBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
                    leftBtn.setAttribute('aria-label', 'Desplazar izquierda');
                    leftBtn.setAttribute('type', 'button');
                    
                    const rightBtn = document.createElement('button');
                    rightBtn.className = 'scroll-btn scroll-btn-right';
                    rightBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
                    rightBtn.setAttribute('aria-label', 'Desplazar derecha');
                    rightBtn.setAttribute('type', 'button');
                    
                    // Funcionalidad de los botones
                    leftBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        container.scrollBy({ left: -200, behavior: 'smooth' });
                    });
                    
                    rightBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        container.scrollBy({ left: 200, behavior: 'smooth' });
                    });
                    
                    // Agregar botones al contenedor
                    container.style.position = 'relative';
                    container.appendChild(leftBtn);
                    container.appendChild(rightBtn);
                    
                    // Actualizar visibilidad de botones según scroll
                    function updateButtonVisibility() {
                        const scrollLeft = container.scrollLeft;
                        const maxScroll = container.scrollWidth - container.clientWidth;
                        
                        // Botón izquierdo
                        if (scrollLeft <= 10) {
                            leftBtn.style.opacity = '0';
                            leftBtn.style.pointerEvents = 'none';
                        } else {
                            leftBtn.style.opacity = '0.8';
                            leftBtn.style.pointerEvents = 'auto';
                        }
                        
                        // Botón derecho
                        if (scrollLeft >= maxScroll - 10) {
                            rightBtn.style.opacity = '0';
                            rightBtn.style.pointerEvents = 'none';
                        } else {
                            rightBtn.style.opacity = '0.8';
                            rightBtn.style.pointerEvents = 'auto';
                        }
                    }
                    
                    container.addEventListener('scroll', updateButtonVisibility);
                    
                    // Actualizar visibilidad al pasar el mouse
                    container.addEventListener('mouseenter', () => {
                        updateButtonVisibility();
                    });
                    
                    // Inicializar visibilidad
                    updateButtonVisibility();
                });
            }
            
            // Solo agregar botones en escritorio
            if (window.innerWidth > 767.98) {
                addTableNavigationButtons();
            }
            
            // Volver a agregar botones si se cambia el tamaño a escritorio
            window.addEventListener('resize', function() {
                if (window.innerWidth > 767.98) {
                    addTableNavigationButtons();
                }
            });

            // Mejorar la experiencia táctil del sidebar
            let sidebarStartX = 0;
            let sidebarCurrentX = 0;

            sidebar.addEventListener('touchstart', (e) => {
                sidebarStartX = e.touches[0].clientX;
            }, {
                passive: true
            });

            sidebar.addEventListener('touchmove', (e) => {
                sidebarCurrentX = e.touches[0].clientX;
                const diff = sidebarStartX - sidebarCurrentX;

                if (diff > 50) { // Deslizar hacia la izquierda para cerrar
                    closeSidebarAuto();
                }
            }, {
                passive: true
            });

            // Mostrar/ocultar contraseña
            window.togglePassword = function(inputId) {
                const input = document.getElementById(inputId);
                if (!input) return;
                
                const icon = input.nextElementSibling ? input.nextElementSibling.querySelector('i') : null;
                if (!icon) return;
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }

            // Modal Editar Usuario
            const modalEditar = document.getElementById('modalEditarUsuario');
            if (modalEditar) {
                modalEditar.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    const editId = document.getElementById('edit_id');
                    const editNombre = document.getElementById('edit_nombre');
                    const editApellidos = document.getElementById('edit_apellidos');
                    const editEmail = document.getElementById('edit_email');
                    const editRol = document.getElementById('edit_rol');
                    const editActivo = document.getElementById('edit_activo');
                    
                    if (editId) editId.value = button.getAttribute('data-id');
                    if (editNombre) editNombre.value = button.getAttribute('data-nombre') || '';
                    if (editApellidos) editApellidos.value = button.getAttribute('data-apellidos') || '';
                    if (editEmail) editEmail.value = button.getAttribute('data-email') || '';
                    if (editRol) editRol.value = button.getAttribute('data-rol') || 'empleado';
                    
                    const activo = button.getAttribute('data-activo');
                    if (editActivo) editActivo.checked = activo === '1';
                });
            }
            
            // Modal Eliminar Usuario
            const modalEliminar = document.getElementById('modalEliminarUsuario');
            if (modalEliminar) {
                modalEliminar.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    const deleteId = document.getElementById('delete_id');
                    const deleteNombre = document.getElementById('delete_nombre');
                    
                    if (deleteId) deleteId.value = button.getAttribute('data-id');
                    if (deleteNombre) deleteNombre.textContent = button.getAttribute('data-nombre') || '';
                });
            }
        });

        // Función simple para scroll táctil que funciona mejor
        function setupBetterTableTouchScrolling() {
            const tableContainers = document.querySelectorAll('.table-responsive');
            
            tableContainers.forEach(container => {
                let startX, startY, scrollLeft;
                let isScrolling = false;
                
                container.addEventListener('touchstart', function(e) {
                    if (window.innerWidth >= 768) return;
                    
                    startX = e.touches[0].pageX;
                    startY = e.touches[0].pageY;
                    scrollLeft = container.scrollLeft;
                    isScrolling = false;
                    
                    // Feedback visual
                    container.classList.add('touch-active');
                }, { passive: true });
                
                container.addEventListener('touchmove', function(e) {
                    if (window.innerWidth >= 768) return;
                    if (!startX) return;
                    
                    const x = e.touches[0].pageX;
                    const y = e.touches[0].pageY;
                    
                    const walkX = startX - x;
                    const walkY = startY - y;
                    
                    // Determinar si el movimiento es principalmente horizontal
                    if (Math.abs(walkX) > Math.abs(walkY)) {
                        isScrolling = true;
                        container.classList.add('touch-scrolling');
                        
                        // Aplicar scroll horizontal
                        container.scrollLeft = scrollLeft + walkX;
                    }
                }, { passive: true });
                
                container.addEventListener('touchend', function() {
                    if (isScrolling) {
                        setTimeout(() => {
                            container.classList.remove('touch-scrolling');
                            container.classList.remove('touch-active');
                        }, 300);
                    }
                    
                    startX = null;
                    startY = null;
                    isScrolling = false;
                }, { passive: true });
            });
        }
        
        // Inicializar cuando el DOM esté listo
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupBetterTableTouchScrolling);
        } else {
            setupBetterTableTouchScrolling();
        }
    </script>
</body>
</html>