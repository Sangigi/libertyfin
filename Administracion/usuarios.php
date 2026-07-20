<?php
// =============================================
// USUARIOS.PHP - Gestión de Usuarios
// =============================================

// Cargar configuración de sesión personalizada
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Variables para el navbar y sidebar
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Administrador';
$usuario_rol = $_SESSION['usuario_rol'] ?? 'admin';

// Cargar configuración de base de datos
require_once __DIR__ . '../../config/database.php';
require_once __DIR__ . '../../env_loader.php';
// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';
$usuarios = [];

// Procesar operaciones CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDBConnection();
        
        // Crear nuevo usuario
        if (isset($_POST['crear_usuario'])) {
            $nombre = trim($_POST['nombre']);
            $apellidos = trim($_POST['apellidos']);
            $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
            $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
            $rol_usuario = $_POST['rol_usuario'];
            $activo = isset($_POST['activo']) ? 1 : 0;
            
            $sql = "INSERT INTO usuarios (nombre, apellidos, email, password, rol_usuario, activo) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre, $apellidos, $email, $password, $rol_usuario, $activo]);
            
            $mensaje = "Usuario creado exitosamente";
            $tipo_mensaje = "success";
        }
        
        // Actualizar usuario
        if (isset($_POST['actualizar_usuario'])) {
            $id = intval($_POST['id']);
            $nombre = trim($_POST['nombre']);
            $apellidos = trim($_POST['apellidos']);
            $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
            $rol_usuario = $_POST['rol_usuario'];
            $activo = isset($_POST['activo']) ? 1 : 0;
            
            // Si se proporcionó nueva contraseña
            if (!empty($_POST['password'])) {
                $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
                $sql = "UPDATE usuarios SET 
                        nombre = ?,
                        apellidos = ?,
                        email = ?,
                        password = ?,
                        rol_usuario = ?,
                        activo = ?
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nombre, $apellidos, $email, $password, $rol_usuario, $activo, $id]);
            } else {
                $sql = "UPDATE usuarios SET 
                        nombre = ?,
                        apellidos = ?,
                        email = ?,
                        rol_usuario = ?,
                        activo = ?
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nombre, $apellidos, $email, $rol_usuario, $activo, $id]);
            }
            
            $mensaje = "Usuario actualizado exitosamente";
            $tipo_mensaje = "success";
        }
        
        // Eliminar usuario
        if (isset($_POST['eliminar_usuario'])) {
            $id = intval($_POST['id']);
            
            // No permitir eliminar al propio usuario
            if ($id == $_SESSION['usuario_id']) {
                $mensaje = "No puedes eliminar tu propio usuario";
                $tipo_mensaje = "warning";
            } else {
                $sql = "DELETE FROM usuarios WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                
                $mensaje = "Usuario eliminado exitosamente";
                $tipo_mensaje = "success";
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
                $stmt = $pdo->prepare("SELECT bloqueado FROM usuarios WHERE id = ?");
                $stmt->execute([$id]);
                $usuario = $stmt->fetch();
                
                if ($usuario) {
                    $nuevo_estado = $usuario['bloqueado'] ? 0 : 1;
                    $stmt = $pdo->prepare("UPDATE usuarios SET bloqueado = ? WHERE id = ?");
                    $stmt->execute([$nuevo_estado, $id]);
                    
                    $mensaje = $nuevo_estado ? "Usuario bloqueado" : "Usuario desbloqueado";
                    $tipo_mensaje = "success";
                }
            }
        }
        
        // Resetear intentos de login
        if (isset($_POST['reset_intentos'])) {
            $id = intval($_POST['id']);
            
            $sql = "UPDATE usuarios SET intentos_login = 0, bloqueado = 0 WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            
            $mensaje = "Intentos de login reseteados";
            $tipo_mensaje = "success";
        }
        
    } catch (PDOException $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
        error_log("Error en usuarios.php: " . $e->getMessage());
    }
}

// Obtener lista de usuarios
try {
    $pdo = getDBConnection();
    
    // Filtros
    $filtro_rol = isset($_GET['rol']) ? $_GET['rol'] : '';
    $filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
    $filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
    $pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $registros_por_pagina = 10;

    // Construir consulta con filtros
    $sql_where = "WHERE 1=1";
    $params = [];

    if (!empty($filtro_rol) && $filtro_rol !== 'todos') {
        $sql_where .= " AND rol_usuario = ?";
        $params[] = $filtro_rol;
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
    }

    // Contar total de registros
    $sql_count = "SELECT COUNT(*) as total FROM usuarios $sql_where";
    $stmt = $pdo->prepare($sql_count);
    $stmt->execute($params);
    $total_registros = $stmt->fetchColumn();

    // Calcular paginación
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina_actual - 1) * $registros_por_pagina;

    // Consulta principal
    $sql = "SELECT * FROM usuarios $sql_where ORDER BY fecha_registro DESC LIMIT ? OFFSET ?";
    $params[] = $registros_por_pagina;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();

} catch (PDOException $e) {
    $mensaje = "Error al cargar usuarios: " . $e->getMessage();
    $tipo_mensaje = "danger";
    error_log("Error al cargar usuarios: " . $e->getMessage());
}

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
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS de componentes compartidos -->
    <link rel="stylesheet" href="assets/css/navbar.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    
    <!-- CSS específico de usuarios -->
    <link rel="stylesheet" href="assets/css/usuarios.css">
</head>
<body>

    <!-- ========================================== -->
    <!-- NAVBAR COMPONENTE -->
    <!-- ========================================== -->
    <?php include 'assets/components/navbar.php'; ?>

    <!-- ========================================== -->
    <!-- SIDEBAR COMPONENTE -->
    <!-- ========================================== -->
    <?php include 'assets/components/sidebar.php'; ?>

    <!-- ========================================== -->
    <!-- CONTENIDO PRINCIPAL -->
    <!-- ========================================== -->
    <main>
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
                <div class="mb-3 mb-md-0">
                    <h1 class="h3 mb-1">
                        <i class="fas fa-user-cog me-2"></i>Gestión de Usuarios
                    </h1>
                    <p class="text-muted mb-0">
                        <span class="d-inline-block me-2">
                            <i class="fas fa-users me-1"></i>Total: <?php echo $total_registros; ?>
                        </span>
                    </p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
                    <i class="fas fa-plus-circle me-1"></i>Nuevo Usuario
                </button>
            </div>
            
            <!-- Mensajes -->
            <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $tipo_mensaje === 'danger' ? 'exclamation-circle' : ($tipo_mensaje === 'warning' ? 'exclamation-triangle' : 'check-circle'); ?> me-2"></i>
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Filtros -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="mb-3">
                        <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                    </h5>
                    <form method="GET" class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Rol:</label>
                            <select name="rol" class="form-select">
                                <option value="todos" <?php echo $filtro_rol === 'todos' ? 'selected' : ''; ?>>Todos los roles</option>
                                <option value="administrador" <?php echo $filtro_rol === 'administrador' ? 'selected' : ''; ?>>Administrador</option>
                                <option value="supervisor" <?php echo $filtro_rol === 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                                <option value="empleado" <?php echo $filtro_rol === 'empleado' ? 'selected' : ''; ?>>Empleado</option>
                                <option value="contador" <?php echo $filtro_rol === 'contador' ? 'selected' : ''; ?>>Contador</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Estado:</label>
                            <select name="estado" class="form-select">
                                <option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
                                <option value="activo" <?php echo $filtro_estado === 'activo' ? 'selected' : ''; ?>>Activo</option>
                                <option value="inactivo" <?php echo $filtro_estado === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                                <option value="bloqueado" <?php echo $filtro_estado === 'bloqueado' ? 'selected' : ''; ?>>Bloqueado</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Buscar:</label>
                            <input type="text" name="busqueda" class="form-control" 
                                   placeholder="Nombre, apellidos o email..." 
                                   value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-end">
                            <div class="d-flex flex-column flex-md-row gap-2 w-100">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="fas fa-search me-1"></i>Filtrar
                                </button>
                                <a href="usuarios.php" class="btn btn-secondary flex-grow-1">
                                    <i class="fas fa-times me-1"></i>Limpiar
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tabla de Usuarios -->
            <div class="card">
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                    <h5 class="mb-2 mb-md-0">
                        <i class="fas fa-list-ul me-2"></i>Usuarios del Sistema
                    </h5>
                    <span class="badge bg-primary"><?php echo $total_registros; ?> usuarios</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th class="d-none d-md-table-cell">Email</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th class="d-none d-lg-table-cell">Registro</th>
                                    <th class="d-none d-lg-table-cell">Último Acceso</th>
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
                                        <td data-label="ID"><?php echo $usuario['id']; ?></td>
                                        <td data-label="Nombre">
                                            <strong><?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellidos']); ?></strong>
                                            <div class="d-md-none">
                                                <small class="text-muted">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?php echo htmlspecialchars($usuario['email']); ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo formatearFecha($usuario['fecha_registro']); ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td data-label="Email" class="d-none d-md-table-cell">
                                            <a href="mailto:<?php echo htmlspecialchars($usuario['email']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($usuario['email']); ?>
                                            </a>
                                        </td>
                                        <td data-label="Rol">
                                            <span class="badge badge-rol badge-<?php echo $usuario['rol_usuario']; ?>">
                                                <?php echo getRolNombre($usuario['rol_usuario']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Estado">
                                            <?php if ($usuario['bloqueado']): ?>
                                                <span class="badge bg-danger badge-estado">
                                                    <i class="fas fa-lock me-1"></i>Bloqueado
                                                </span>
                                            <?php elseif (!$usuario['activo']): ?>
                                                <span class="badge bg-secondary badge-estado">
                                                    <i class="fas fa-user-slash me-1"></i>Inactivo
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success badge-estado">
                                                    <i class="fas fa-user-check me-1"></i>Activo
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Registro" class="d-none d-lg-table-cell">
                                            <small><?php echo formatearFecha($usuario['fecha_registro']); ?></small>
                                        </td>
                                        <td data-label="Último Acceso" class="d-none d-lg-table-cell">
                                            <small><?php echo formatearFecha($usuario['ultimo_acceso']); ?></small>
                                        </td>
                                        <td data-label="Acciones">
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-info" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#modalEditarUsuario"
                                                        data-id="<?php echo $usuario['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                                                        data-apellidos="<?php echo htmlspecialchars($usuario['apellidos']); ?>"
                                                        data-email="<?php echo htmlspecialchars($usuario['email']); ?>"
                                                        data-rol="<?php echo $usuario['rol_usuario']; ?>"
                                                        data-activo="<?php echo $usuario['activo']; ?>"
                                                        title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <?php if ($usuario['id'] != $_SESSION['usuario_id']): ?>
                                                    <?php if ($usuario['bloqueado']): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                                        <button type="submit" name="toggle_bloqueo" class="btn btn-sm btn-warning" title="Desbloquear">
                                                            <i class="fas fa-unlock"></i>
                                                        </button>
                                                    </form>
                                                    <?php else: ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                                        <button type="submit" name="toggle_bloqueo" class="btn btn-sm btn-warning" title="Bloquear">
                                                            <i class="fas fa-lock"></i>
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($usuario['intentos_login'] > 0): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                                        <button type="submit" name="reset_intentos" class="btn btn-sm btn-secondary" 
                                                                title="Resetear intentos de login (<?php echo $usuario['intentos_login']; ?> intentos)">
                                                            <i class="fas fa-redo"></i>
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <button class="btn btn-sm btn-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#modalEliminarUsuario"
                                                            data-id="<?php echo $usuario['id']; ?>"
                                                            data-nombre="<?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellidos']); ?>"
                                                            title="Eliminar">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge bg-info text-white align-middle" title="Este es tu usuario">
                                                        <i class="fas fa-user-check me-1"></i> Tú
                                                    </span>
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
                    <div class="card-footer">
                        <nav aria-label="Paginación">
                            <ul class="pagination justify-content-center mb-0">
                                <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" 
                                       href="?pagina=<?php echo $pagina_actual - 1; ?>&rol=<?php echo $filtro_rol; ?>&estado=<?php echo $filtro_estado; ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php 
                                $inicio = max(1, $pagina_actual - 2);
                                $fin = min($total_paginas, $pagina_actual + 2);
                                for ($i = $inicio; $i <= $fin; $i++): ?>
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
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- ========================================== -->
    <!-- MODALES -->
    <!-- ========================================== -->
    
    <!-- Modal Nuevo Usuario -->
    <div class="modal fade" id="modalNuevoUsuario" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre *</label>
                            <input type="text" name="nombre" class="form-control" required placeholder="Ingrese el nombre">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Apellidos *</label>
                            <input type="text" name="apellidos" class="form-control" required placeholder="Ingrese los apellidos">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required placeholder="correo@ejemplo.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contraseña *</label>
                            <div class="form-group">
                                <input type="password" name="password" id="password" class="form-control" required 
                                       minlength="6" placeholder="Mínimo 6 caracteres">
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
                        <button type="submit" name="crear_usuario" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Crear Usuario
                        </button>
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
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Editar Usuario</h5>
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
                            <label class="form-label">Nueva Contraseña <small class="text-muted">(dejar vacío para no cambiar)</small></label>
                            <div class="form-group">
                                <input type="password" name="password" id="edit_password" class="form-control"
                                       minlength="6" placeholder="Nueva contraseña">
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
                        <button type="submit" name="actualizar_usuario" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Actualizar Usuario
                        </button>
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
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-user-times fa-3x text-danger"></i>
                        </div>
                        <p class="text-center">¿Está seguro de eliminar al usuario <strong id="delete_nombre"></strong>?</p>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <strong>¡Advertencia!</strong> Esta acción no se puede deshacer y eliminará todos los datos asociados al usuario.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="eliminar_usuario" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>Eliminar Usuario
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SCRIPTS -->
    <!-- ========================================== -->

    <!-- jQuery (necesario para AJAX) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- JS de navbar y sidebar (separados) -->
    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/sidebar.js"></script>
    
    <!-- JS específico de usuarios -->
    <script src="assets/js/usuarios.js"></script>
</body>
</html>