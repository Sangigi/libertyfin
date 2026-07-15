<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Verificar si es administrador
if ($_SESSION['usuario_rol'] !== 'admin') {
    $_SESSION['error'] = "No tienes permisos para acceder a esta página";
    header("Location: Inicio");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$conn_main = getDBConnection();

// Obtener el plan de la empresa
$empresa_plan = "prueba";
$timbres_totales = 0;
$timbres_disponibles = 0;

if ($conn_main) {
    $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([$_SESSION['empresa_id']]);
    $result_empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);
    
    if ($result_empresa) {
        $empresa_plan = $result_empresa['plan'];
        $timbres_totales = $result_empresa['timbres_totales'] ?? 0;
        $timbres_disponibles = $result_empresa['timbres_disponibles'] ?? 0;
    }
    $stmt_empresa = null;
    $conn_main = null;
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Conectar a la base de datos de la empresa
try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Obtener configuración de colores
    $sql_colores = "SELECT color_primario, color_secundario FROM sistema_config LIMIT 1";
    $result_colores = $conn->query($sql_colores);
    if ($result_colores->rowCount() > 0) {
        $colores_config = $result_colores->fetch(PDO::FETCH_ASSOC);
        $color_primario = $colores_config['color_primario'] ?? '#27ae60';
        $color_secundario = $colores_config['color_secundario'] ?? '#2ecc71';
    } else {
        $color_primario = '#27ae60';
        $color_secundario = '#2ecc71';
    }

    // Obtener información de la empresa
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch(PDO::FETCH_ASSOC);

    // OBTENER LOGO DE LA EMPRESA
    $logo_empresa = null;
    $logo_src_base64 = null;

    if (!empty($empresa_info['logo'])) {
        $empresa_logo = $empresa_info['logo'];
        $logo_path = '';
        $rutas_posibles = [
            $empresa_logo,
            '../' . $empresa_logo,
            '../../' . $empresa_logo,
            'admin/' . $empresa_logo,
            '../admin/' . $empresa_logo,
            'logos/' . $empresa_logo,
            'img/' . $empresa_logo,
            'images/' . $empresa_logo,
            'assets/' . $empresa_logo,
            'uploads/' . $empresa_logo,
            '../logos/' . $empresa_logo,
            '../img/' . $empresa_logo,
            '../images/' . $empresa_logo,
            '../assets/' . $empresa_logo,
            '../uploads/' . $empresa_logo
        ];

        foreach ($rutas_posibles as $ruta) {
            if (file_exists($ruta) && is_file($ruta)) {
                $logo_path = $ruta;
                break;
            }
        }

        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
    }

    // Obtener el total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM usuarios u WHERE 1=1";
    $result_count = $conn->query($sql_count);
    $total_registros = $result_count->fetch(PDO::FETCH_ASSOC)['total'];
    $result_count = null;

    // Calcular total de páginas
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // Obtener usuarios con LIMIT para paginación
    $sql_usuarios = "
        SELECT u.*, s.nombre as sucursal_nombre 
        FROM usuarios u 
        LEFT JOIN sucursales s ON u.sucursal_id = s.id 
        ORDER BY u.fecha_creacion DESC, u.id DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql_usuarios);
    $stmt->execute([$registros_por_pagina, $offset]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null;

    // Obtener sucursales
    $sql_sucursales = "SELECT id, nombre FROM sucursales WHERE activo = 1";
    $result_sucursales = $conn->query($sql_sucursales);
    $sucursales = $result_sucursales->fetchAll(PDO::FETCH_ASSOC);

    // ESTADÍSTICAS Y LISTAS DE USUARIOS PARA MODALES
    // 1. Todos los usuarios activos (para el modal de Total Usuarios)
    $sql_todos_usuarios = "SELECT id, nombre, username, rol, activo FROM usuarios ORDER BY nombre";
    $result_todos_usuarios = $conn->query($sql_todos_usuarios);
    $todos_usuarios_lista = $result_todos_usuarios->fetchAll(PDO::FETCH_ASSOC);

    // 2. Usuarios administradores
    $sql_administradores = "SELECT id, nombre, username, email FROM usuarios WHERE rol = 'admin' ORDER BY nombre";
    $result_administradores = $conn->query($sql_administradores);
    $administradores_lista = $result_administradores->fetchAll(PDO::FETCH_ASSOC);

    // 3. Usuarios cajeros
    $sql_cajeros = "SELECT id, nombre, username, email FROM usuarios WHERE rol = 'cajero' ORDER BY nombre";
    $result_cajeros = $conn->query($sql_cajeros);
    $cajeros_lista = $result_cajeros->fetchAll(PDO::FETCH_ASSOC);

    // 4. Usuarios activos
    $sql_activos = "SELECT id, nombre, username, email, rol FROM usuarios WHERE activo = 1 ORDER BY nombre";
    $result_activos = $conn->query($sql_activos);
    $activos_lista = $result_activos->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas resumidas
    $sql_stats = "
        SELECT 
            COUNT(*) as total_usuarios,
            SUM(CASE WHEN rol = 'admin' THEN 1 ELSE 0 END) as admin_count,
            SUM(CASE WHEN rol = 'cajero' THEN 1 ELSE 0 END) as cajero_count,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos_count
        FROM usuarios
    ";
    $result_stats = $conn->query($sql_stats);
    $stats = $result_stats->fetch(PDO::FETCH_ASSOC);
    $result_stats = null;

    $total_usuarios = $stats['total_usuarios'] ?? 0;
    $admin_count = $stats['admin_count'] ?? 0;
    $cajero_count = $stats['cajero_count'] ?? 0;
    $activos_count = $stats['activos_count'] ?? 0;
    
    // Obtener ID del usuario actual para evitar que se elimine a sí mismo
    $usuario_actual_id = $_SESSION['usuario_id'] ?? 0;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// VERIFICAR LÍMITE DE USUARIOS SEGÚN EL PLAN
$limite_usuarios = 0;
switch ($empresa_plan) {
    case 'prueba':
        $limite_usuarios = 3;
        break;
    case 'basico':
        $limite_usuarios = 1;
        break;
    case 'emprendedor':
        $limite_usuarios = 5;
        break;
    case 'premium':
        $limite_usuarios = 10;
        break;
    default:
        $limite_usuarios = 3;
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear':
                if ($total_usuarios >= $limite_usuarios) {
                    $_SESSION['mensaje'] = "Has alcanzado el límite de usuarios para tu plan ($empresa_plan). Máximo permitido: $limite_usuarios usuarios";
                    $_SESSION['tipo_mensaje'] = "warning";
                    header('Location: usuarios.php');
                    exit();
                }
                crearUsuario($conn);
                break;
            case 'editar':
                editarUsuario($conn);
                break;
            case 'cambiar_estado':
                cambiarEstadoUsuario($conn);
                break;
            case 'eliminar':
                eliminarUsuario($conn);
                break;
        }
    }
}

function crearUsuario($conn)
{
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $rol = $_POST['rol'];
    $sucursal_id = intval($_POST['sucursal_id']);

    try {
        $sql = "INSERT INTO usuarios (username, password, nombre, email, rol, sucursal_id) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$username, $password, $nombre, $email, $rol, $sucursal_id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['mensaje'] = "Usuario creado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al crear usuario");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: usuarios.php');
    exit();
}

function editarUsuario($conn)
{
    $id = intval($_POST['id']);
    $username = trim($_POST['username']);
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $rol = $_POST['rol'];
    $sucursal_id = intval($_POST['sucursal_id']);

    try {
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql = "UPDATE usuarios SET username = ?, password = ?, nombre = ?, email = ?, rol = ?, sucursal_id = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$username, $password, $nombre, $email, $rol, $sucursal_id, $id]);
        } else {
            $sql = "UPDATE usuarios SET username = ?, nombre = ?, email = ?, rol = ?, sucursal_id = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$username, $nombre, $email, $rol, $sucursal_id, $id]);
        }

        if ($stmt->rowCount() >= 0) {
            $_SESSION['mensaje'] = "Usuario actualizado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al actualizar usuario");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: usuarios.php');
    exit();
}

function cambiarEstadoUsuario($conn)
{
    $id = intval($_POST['id']);
    $activo = intval($_POST['activo']);

    try {
        $sql = "UPDATE usuarios SET activo = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$activo, $id]);

        if ($stmt->rowCount() >= 0) {
            $estado = $activo ? "activado" : "desactivado";
            $_SESSION['mensaje'] = "Usuario $estado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al cambiar estado");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: usuarios.php');
    exit();
}

function eliminarUsuario($conn)
{
    $id = intval($_POST['id']);
    
    // Evitar que el usuario se elimine a sí mismo
    if ($id == $_SESSION['usuario_id']) {
        $_SESSION['mensaje'] = "No puedes eliminar tu propio usuario";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: usuarios.php');
        exit();
    }

    try {
        $sql = "DELETE FROM usuarios WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['mensaje'] = "Usuario eliminado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al eliminar usuario");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: usuarios.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Usuarios - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
     <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
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

        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
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

        /* Estilo para las tarjetas de estadísticas como clickeables */
        .stat-card {
            border-left: 4px solid var(--primary-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        /* Estilos para la lista de usuarios en el modal */
        .user-list-modal {
            max-height: 60vh;
            overflow-y: auto;
        }
        .user-list-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }
        .user-list-item:hover {
            background-color: #f8f9fa;
        }
        .user-list-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .user-list-info {
            flex: 1;
        }
        .user-list-name {
            font-weight: 600;
            margin-bottom: 2px;
            color: #2c3e50;
        }
        .user-list-username {
            font-size: 0.8rem;
            color: #7f8c8d;
        }
        .user-list-email {
            font-size: 0.7rem;
            color: #95a5a6;
        }
        .user-list-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 12px;
        }
        .badge-admin {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .badge-cajero {
            background: linear-gradient(45deg, #28a745, #1e7e34);
            color: white;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-admin {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .badge-cajero {
            background: linear-gradient(45deg, #28a745, #1e7e34);
            color: white;
        }

        .badge-inventario {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: black;
        }

        .search-box {
            position: relative;
        }

        .search-box .form-control {
            padding-left: 40px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }

        .btn-danger:hover {
            background-color: #c82333;
            border-color: #bd2130;
        }

        /* Estilo para filas clickeables */
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .clickable-row:hover {
            background-color: rgba(39, 174, 96, 0.05) !important;
        }

        /* ============================================
           ESTILOS PARA TARJETAS DE USUARIOS (MÓVIL)
        ============================================ */
        .users-cards-container {
            display: none;
        }

        .user-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 16px;
            overflow: hidden;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            cursor: pointer;
        }

        .user-card:active {
            transform: scale(0.98);
        }

        .user-card-header {
            display: flex;
            align-items: center;
            padding: 16px;
            background: white;
            border-bottom: 1px solid #f0f0f0;
        }

        .user-card-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .user-card-info {
            flex: 1;
            margin-left: 14px;
        }

        .user-card-name {
            font-weight: 600;
            font-size: 1rem;
            color: #2c3e50;
            margin-bottom: 4px;
        }

        .user-card-username {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-bottom: 2px;
        }

        .user-card-email {
            font-size: 0.75rem;
            color: #95a5a6;
            word-break: break-all;
        }

        .user-card-body {
            padding: 12px 16px;
            background: #fafbfc;
        }

        .user-card-detail {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .user-card-detail:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .detail-value {
            font-size: 0.85rem;
            font-weight: 500;
            color: #2c3e50;
        }

        /* Paginación */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding: 1rem 0;
            border-top: 1px solid #dee2e6;
        }

        .pagination-info {
            color: #6c757d;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .pagination {
            margin-bottom: 0;
        }

        .pagination .page-link {
            color: #495057;
            border: 1px solid #dee2e6;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .pagination .page-link:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
            color: #495057;
        }

        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white !important;
        }

        /* Sidebar móvil */
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

        /* Estilos para nueva sucursal */
        .nueva-sucursal-field {
            border: 2px dashed #28a745;
            background-color: #f8fff9;
        }

        .plan-limit-alert {
            background: linear-gradient(45deg, #ff6b6b, #ee5a52);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .plan-limit-alert i {
            font-size: 1.2rem;
            margin-right: 10px;
        }

        .plan-badge {
            background: linear-gradient(45deg, #6c5ce7, #a29bfe);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ============================================
           MEDIA QUERIES RESPONSIVE
        ============================================ */
        @media (max-width: 767.98px) {
            .sidebar .nav-link {
                padding: 15px 20px;
                min-height: 50px;
                display: flex;
                align-items: center;
                cursor: pointer;
            }

            .sidebar .nav-link:active {
                background: rgba(255, 255, 255, 0.2);
                transform: translateX(5px);
                transition: all 0.1s ease;
            }

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
                padding: 0.75rem !important;
                transition: transform 0.3s ease-out;
            }

            body.sidebar-open main {
                transform: translateX(280px);
            }

            .table-responsive {
                display: none !important;
            }

            .users-cards-container {
                display: block;
            }

            .stat-card .card-body {
                padding: 1rem;
            }

            .metric-value {
                font-size: 1.3rem;
            }

            .pagination-container {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .pagination {
                flex-wrap: wrap;
                justify-content: center;
            }

            .pagination .page-link {
                padding: 0.375rem 0.75rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 575.98px) {
            .col-md-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .header-actions {
                flex-direction: column;
                gap: 1rem;
            }

            .header-actions .btn {
                width: 100%;
            }
        }

        @media (min-width: 768px) {
            .users-cards-container {
                display: none;
            }
            
            .table-responsive {
                display: block !important;
            }
        }

        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            background-color: #f8f9fa;
        }

        .table td {
            vertical-align: middle;
        }

        /* Estilos para botones de acción dentro del modal */
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }
        
        .modal-actions .btn {
            flex: 1;
        }
        
        /* Estilo para el badge del estado en el modal */
        .user-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 10px;
        }
    </style>
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="Inicio">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span><?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span><?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                    <span><?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                        <span class="plan-badge ms-2">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Plan: <?php echo ucfirst($empresa_plan); ?> (Límite: <?php echo $limite_usuarios; ?> usuarios)</small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="Inicio">
                                <i class="fas fa-tachometer-alt"></i>
                                Inicio
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link active" href="Usuarios">
                                    <i class="fas fa-user-cog"></i>
                                    Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="Caja">
                                <i class="fas fa-cash-register"></i>
                                Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Productos">
                                <i class="fas fa-boxes"></i>
                                Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Clientes">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Ventas">
                                <i class="fas fa-receipt"></i>
                                Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="CortesCaja">
                                <i class="fas fa-cash-register"></i>
                                Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Proveedores">
                                <i class="fas fa-truck"></i>
                                Proveedores
                            </a>
                        </li>
                        <?php if ($empresa_plan !== 'basico' && $_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Sucursales">
                                    <i class="fas fa-store"></i>
                                    Sucursales
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles > 0) : ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Facturacion/inicio.php">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    Facturación
                                    <?php if ($timbres_disponibles > 0): ?>
                                        <span class="badge bg-success ms-2" style="font-size: 0.65rem;">
                                            <?php echo $timbres_disponibles; ?> timbres
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning ms-2" style="font-size: 0.65rem;">
                                            Sin timbres
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li>
                        <?php if ($empresa_plan === 'premium'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="../EmidaServicios/inicio.php">
                                <img src="../images/emidalogo.png" alt="" style="width: 20px; height: 20px; margin-right: 10px; object-fit: contain;">
                                Emida Servicios
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i>
                                    Comisiones
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="configuracion.php">
                                    <i class="fas fa-cogs"></i>
                                    Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 header-actions">
                    <div>
                        <h2>
                            <i class="fas fa-users me-2"></i>
                            Gestión de Usuarios
                        </h2>
                        <div class="d-flex align-items-center mt-2">
                            <span class="plan-badge me-2">
                                <i class="fas fa-crown me-1"></i>Plan <?php echo ucfirst($empresa_plan); ?>
                            </span>
                            <span class="text-muted small">
                                <i class="fas fa-users me-1"></i>
                                <?php echo $total_usuarios; ?> / <?php echo $limite_usuarios; ?> usuarios utilizados
                            </span>
                        </div>
                    </div>
                    <div>
                        <?php if ($total_usuarios >= $limite_usuarios): ?>
                            <div class="plan-limit-alert mb-2">
                                <i class="fas fa-exclamation-triangle"></i>
                                Has alcanzado el límite de usuarios para tu plan (<?php echo $limite_usuarios; ?>)
                            </div>
                        <?php endif; ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" id="newUserBtn"
                            <?php echo ($total_usuarios >= $limite_usuarios) ? 'disabled' : ''; ?>>
                            <i class="fas fa-plus me-2"></i>Nuevo Usuario
                        </button>
                    </div>
                </div>

                <!-- Mensajes -->
                <?php if (isset($_SESSION['mensaje'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show">
                        <?php echo $_SESSION['mensaje']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
                <?php endif; ?>

                <!-- Estadísticas (Ahora clickeables) -->
                <div class="row mb-4">
                    <!-- Tarjeta Total Usuarios -->
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card h-100" data-stat-type="total" data-bs-toggle="modal" data-bs-target="#modalListaUsuarios">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Usuarios</div>
                                        <div class="metric-value text-primary"><?php echo $total_usuarios; ?></div>
                                        <div class="metric-progress mt-1">
                                            <div class="progress" style="height: 5px;">
                                                <div class="progress-bar bg-primary"
                                                    style="width: <?php echo min(100, ($total_usuarios / $limite_usuarios) * 100); ?>%;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-users fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Tarjeta Administradores -->
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card h-100" data-stat-type="admin" data-bs-toggle="modal" data-bs-target="#modalListaUsuarios">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Administradores</div>
                                        <div class="metric-value text-success"><?php echo $admin_count; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-shield fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Tarjeta Cajeros -->
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card h-100" data-stat-type="cajero" data-bs-toggle="modal" data-bs-target="#modalListaUsuarios">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Cajeros</div>
                                        <div class="metric-value text-warning"><?php echo $cajero_count; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-cash-register fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Tarjeta Activos -->
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card h-100" data-stat-type="activo" data-bs-toggle="modal" data-bs-target="#modalListaUsuarios">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Activos</div>
                                        <div class="metric-value text-info"><?php echo $activos_count; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-check fa-2x text-info opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra de Búsqueda -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <div class="search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="text" class="form-control" placeholder="Buscar usuarios..." id="searchInput">
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="showInactive">
                                    <label class="form-check-label" for="showInactive">Mostrar inactivos</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Usuarios (Desktop) -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Lista de Usuarios <small class="text-muted ms-2"><i class="fas fa-hand-pointer"></i> Haz clic en cualquier usuario para ver/editar</small></h5>
                        <div class="d-flex align-items-center">
                            <small class="result-count me-3">
                                Mostrando <?php echo count($usuarios); ?> de <?php echo $total_registros; ?> usuarios
                            </small>
                            <?php if ($total_paginas > 1): ?>
                                <span class="badge bg-secondary">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- TABLA (DESKTOP) - SIN COLUMNA DE ACCIONES -->
                        <div class="table-responsive">
                            <table class="table table-hover" id="usersTable">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Rol</th>
                                        <th>Sucursal</th>
                                        <th>Estado</th>
                                        <th>Registro</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($usuarios)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                <i class="fas fa-users fa-3x mb-3"></i>
                                                <p>No se encontraron usuarios</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($usuarios as $usuario): ?>
                                            <tr class="clickable-row" data-user='<?php echo htmlspecialchars(json_encode($usuario)); ?>'>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar me-3">
                                                            <?php echo strtoupper(substr($usuario['nombre'], 0, 2)); ?>
                                                        </div>
                                                        <span><?php echo htmlspecialchars($usuario['username']); ?></span>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                                                <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $usuario['rol']; ?>">
                                                        <?php echo ucfirst($usuario['rol']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($usuario['sucursal_nombre'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $usuario['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($usuario['fecha_creacion'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- TARJETAS DE USUARIOS (MÓVIL) - TODA LA TARJETA ES CLICKEABLE -->
                        <div class="users-cards-container" id="usersCardsContainer">
                            <?php if (empty($usuarios)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-users fa-3x mb-3"></i>
                                    <p>No se encontraron usuarios</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <div class="user-card clickable-card" data-user='<?php echo htmlspecialchars(json_encode($usuario)); ?>'
                                         data-username="<?php echo strtolower(htmlspecialchars($usuario['username'])); ?>"
                                         data-nombre="<?php echo strtolower(htmlspecialchars($usuario['nombre'])); ?>"
                                         data-email="<?php echo strtolower(htmlspecialchars($usuario['email'])); ?>"
                                         data-activo="<?php echo $usuario['activo']; ?>"
                                         data-id="<?php echo $usuario['id']; ?>">
                                        
                                        <div class="user-card-header">
                                            <div class="user-card-avatar">
                                                <?php echo strtoupper(substr($usuario['nombre'], 0, 2)); ?>
                                            </div>
                                            <div class="user-card-info">
                                                <div class="user-card-name"><?php echo htmlspecialchars($usuario['nombre']); ?></div>
                                                <div class="user-card-username">@<?php echo htmlspecialchars($usuario['username']); ?></div>
                                                <div class="user-card-email"><?php echo htmlspecialchars($usuario['email']); ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="user-card-body">
                                            <div class="user-card-detail">
                                                <span class="detail-label"><i class="fas fa-tag me-1"></i> Rol</span>
                                                <span class="badge badge-<?php echo $usuario['rol']; ?>">
                                                    <?php echo ucfirst($usuario['rol']); ?>
                                                </span>
                                            </div>
                                            <div class="user-card-detail">
                                                <span class="detail-label"><i class="fas fa-store me-1"></i> Sucursal</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($usuario['sucursal_nombre'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="user-card-detail">
                                                <span class="detail-label"><i class="fas fa-calendar-alt me-1"></i> Registro</span>
                                                <span class="detail-value"><?php echo date('d/m/Y', strtotime($usuario['fecha_creacion'])); ?></span>
                                            </div>
                                            <div class="user-card-detail">
                                                <span class="detail-label"><i class="fas fa-circle me-1"></i> Estado</span>
                                                <span class="status-badge <?php echo $usuario['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Paginación -->
                        <?php if ($total_paginas > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Mostrando <?php echo count($usuarios); ?> de <?php echo $total_registros; ?> usuarios
                                </div>
                                <nav>
                                    <ul class="pagination mb-0">
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" title="Primera página">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>" title="Página anterior">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                        <?php
                                        $inicio = max(1, $pagina_actual - 2);
                                        $fin = min($total_paginas, $pagina_actual + 2);
                                        for ($i = $inicio; $i <= $fin; $i++):
                                        ?>
                                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>" title="Página siguiente">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>" title="Última página">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- MODAL PARA LISTA DE USUARIOS (Estadísticas) -->
    <div class="modal fade" id="modalListaUsuarios" tabindex="-1" aria-labelledby="modalListaUsuariosLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalListaUsuariosLabel">Lista de Usuarios</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body user-list-modal" id="modalListaUsuariosBody">
                    <!-- Aquí se cargará dinámicamente la lista de usuarios -->
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Nuevo/Editar Usuario (Ahora con botones de acción dentro) -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="userForm">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="formAction" value="crear">
                        <input type="hidden" name="id" id="userId">

                        <?php if ($total_usuarios >= $limite_usuarios): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Has alcanzado el límite de usuarios para tu plan <?php echo ucfirst($empresa_plan); ?> (<?php echo $limite_usuarios; ?> usuarios).
                                Para agregar más usuarios, actualiza tu plan.
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Usuario *</label>
                            <input type="text" class="form-control" name="username" id="username" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Contraseña <span id="passwordRequired">*</span></label>
                            <input type="password" class="form-control" name="password" id="password" required>
                            <small class="form-text text-muted" id="passwordHelp">Mínimo 6 caracteres</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nombre Completo *</label>
                            <input type="text" class="form-control" name="nombre" id="nombre" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="email">
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Rol *</label>
                                    <select class="form-select" name="rol" id="rol" required>
                                        <option value="admin">Administrador</option>
                                        <option value="cajero">Cajero</option>
                                        <option value="inventario">Inventario</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Sucursal *</label>
                                    <div class="input-group">
                                        <select class="form-select" name="sucursal_id" id="sucursal_id" required>
                                            <?php foreach ($sucursales as $sucursal): ?>
                                                <option value="<?php echo $sucursal['id']; ?>">
                                                    <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" id="btnNuevaSucursal">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row" id="nuevaSucursalRow" style="display: none;">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Nueva Sucursal *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control nueva-sucursal-field" id="nuevaSucursalNombre" placeholder="Nombre de la nueva sucursal">
                                        <button type="button" class="btn btn-success" id="btnGuardarSucursal">
                                            <i class="fas fa-check"></i> Guardar
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="btnCancelarSucursal">
                                            <i class="fas fa-times"></i> Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sección de botones de acción para usuario existente -->
                        <div class="modal-actions" id="modalActions" style="display: none;">
                            <button type="button" class="btn btn-outline-warning" id="toggleStatusBtn">
                                <i class="fas fa-ban me-2"></i><span id="toggleStatusText">Desactivar</span>
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="deleteFromModalBtn">
                                <i class="fas fa-trash-alt me-2"></i>Eliminar
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="submitUserBtn"
                            <?php echo ($total_usuarios >= $limite_usuarios) ? 'disabled' : ''; ?>>
                            <i class="fas fa-save me-2"></i>Guardar Usuario
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Pasar datos de PHP a JavaScript
        const usuariosData = {
            total: <?php echo json_encode($todos_usuarios_lista); ?>,
            admin: <?php echo json_encode($administradores_lista); ?>,
            cajero: <?php echo json_encode($cajeros_lista); ?>,
            activo: <?php echo json_encode($activos_lista); ?>
        };

        // Variable para almacenar el usuario actual en edición
        let currentEditUser = null;

        // Función para generar el HTML de la lista de usuarios
        function generarListaUsuarios(tipo) {
            let usuarios = [];
            let titulo = '';
            
            switch(tipo) {
                case 'total':
                    usuarios = usuariosData.total;
                    titulo = 'Todos los Usuarios';
                    break;
                case 'admin':
                    usuarios = usuariosData.admin;
                    titulo = 'Usuarios Administradores';
                    break;
                case 'cajero':
                    usuarios = usuariosData.cajero;
                    titulo = 'Usuarios Cajeros';
                    break;
                case 'activo':
                    usuarios = usuariosData.activo;
                    titulo = 'Usuarios Activos';
                    break;
                default:
                    usuarios = [];
                    titulo = 'Usuarios';
            }
            
            // Actualizar título del modal
            document.getElementById('modalListaUsuariosLabel').textContent = titulo;
            
            if (!usuarios || usuarios.length === 0) {
                return `
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-user-slash fa-3x mb-3"></i>
                        <p>No hay usuarios en esta categoría</p>
                    </div>
                `;
            }
            
            let html = '<div class="list-group list-group-flush">';
            usuarios.forEach(usuario => {
                let rolBadge = '';
                if (usuario.rol) {
                    let badgeClass = usuario.rol === 'admin' ? 'badge-admin' : (usuario.rol === 'cajero' ? 'badge-cajero' : 'badge-inventario');
                    rolBadge = `<span class="user-list-badge ${badgeClass} ms-2">${usuario.rol.charAt(0).toUpperCase() + usuario.rol.slice(1)}</span>`;
                }
                
                html += `
                    <div class="user-list-item">
                        <div class="user-list-avatar">
                            ${usuario.nombre ? usuario.nombre.charAt(0).toUpperCase() : 'U'}
                        </div>
                        <div class="user-list-info">
                            <div class="user-list-name">
                                ${escapeHtml(usuario.nombre)}
                                ${rolBadge}
                            </div>
                            <div class="user-list-username">${escapeHtml(usuario.username)}</div>
                            ${usuario.email ? `<div class="user-list-email">${escapeHtml(usuario.email)}</div>` : ''}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            return html;
        }
        
        // Función auxiliar para escapar HTML
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        // Función para confirmar y eliminar usuario con SweetAlert
        function confirmDeleteUser(userId, userName) {
            Swal.fire({
                title: '¿Eliminar usuario?',
                html: `¿Estás seguro de que deseas eliminar a <strong>${userName}</strong>?<br><small class="text-danger">Esta acción no se puede deshacer.</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    form.style.display = 'none';
                    
                    const inputAccion = document.createElement('input');
                    inputAccion.type = 'hidden';
                    inputAccion.name = 'accion';
                    inputAccion.value = 'eliminar';
                    
                    const inputId = document.createElement('input');
                    inputId.type = 'hidden';
                    inputId.name = 'id';
                    inputId.value = userId;
                    
                    form.appendChild(inputAccion);
                    form.appendChild(inputId);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Función para confirmar cambio de estado
        function confirmToggleStatus(userId, userName, currentStatus) {
            const newStatus = currentStatus === 1 ? 'desactivar' : 'activar';
            const newStatusText = currentStatus === 1 ? 'desactivar' : 'activar';
            
            Swal.fire({
                title: `${newStatusText.charAt(0).toUpperCase() + newStatusText.slice(1)} usuario`,
                html: `¿Estás seguro de que deseas ${newStatusText} a <strong>${userName}</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: currentStatus === 1 ? '#dc3545' : '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Sí, ${newStatusText}`,
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    form.style.display = 'none';
                    
                    const inputAccion = document.createElement('input');
                    inputAccion.type = 'hidden';
                    inputAccion.name = 'accion';
                    inputAccion.value = 'cambiar_estado';
                    
                    const inputId = document.createElement('input');
                    inputId.type = 'hidden';
                    inputId.name = 'id';
                    inputId.value = userId;
                    
                    const inputActivo = document.createElement('input');
                    inputActivo.type = 'hidden';
                    inputActivo.name = 'activo';
                    inputActivo.value = currentStatus === 1 ? '0' : '1';
                    
                    form.appendChild(inputAccion);
                    form.appendChild(inputId);
                    form.appendChild(inputActivo);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Función para abrir el modal de edición con los datos del usuario
        function openEditModal(userData) {
            currentEditUser = userData;
            
            document.getElementById('modalTitle').textContent = 'Editar Usuario';
            document.getElementById('formAction').value = 'editar';
            document.getElementById('userId').value = userData.id;
            document.getElementById('username').value = userData.username;
            document.getElementById('nombre').value = userData.nombre;
            document.getElementById('email').value = userData.email;
            document.getElementById('rol').value = userData.rol;
            document.getElementById('sucursal_id').value = userData.sucursal_id;

            const passwordField = document.getElementById('password');
            const passwordRequired = document.getElementById('passwordRequired');
            const passwordHelp = document.getElementById('passwordHelp');

            passwordField.required = false;
            passwordField.value = '';
            if (passwordRequired) passwordRequired.style.display = 'none';
            if (passwordHelp) passwordHelp.textContent = 'Dejar en blanco para no cambiar la contraseña';

            const submitBtn = document.getElementById('submitUserBtn');
            if (submitBtn) submitBtn.disabled = false;
            
            // Mostrar botones de acción para edición
            const modalActions = document.getElementById('modalActions');
            if (modalActions) {
                modalActions.style.display = 'flex';
                
                // Configurar botón de cambio de estado
                const toggleStatusBtn = document.getElementById('toggleStatusBtn');
                const toggleStatusText = document.getElementById('toggleStatusText');
                const isActive = userData.activo == 1;
                
                if (toggleStatusBtn && toggleStatusText) {
                    toggleStatusText.textContent = isActive ? 'Desactivar' : 'Activar';
                    toggleStatusBtn.className = isActive ? 'btn btn-outline-warning' : 'btn btn-outline-success';
                    toggleStatusBtn.innerHTML = isActive ? '<i class="fas fa-ban me-2"></i>Desactivar' : '<i class="fas fa-check me-2"></i>Activar';
                    
                    // Remover eventos anteriores y agregar nuevo
                    const newToggleBtn = toggleStatusBtn.cloneNode(true);
                    toggleStatusBtn.parentNode.replaceChild(newToggleBtn, toggleStatusBtn);
                    newToggleBtn.addEventListener('click', function() {
                        confirmToggleStatus(userData.id, userData.nombre, userData.activo);
                    });
                }
                
                // Configurar botón de eliminar
                const deleteFromModalBtn = document.getElementById('deleteFromModalBtn');
                if (deleteFromModalBtn) {
                    const usuarioActualId = <?php echo $usuario_actual_id; ?>;
                    if (userData.id == usuarioActualId) {
                        deleteFromModalBtn.disabled = true;
                        deleteFromModalBtn.title = "No puedes eliminar tu propio usuario";
                    } else {
                        deleteFromModalBtn.disabled = false;
                        deleteFromModalBtn.title = "";
                    }
                    
                    // Remover eventos anteriores y agregar nuevo
                    const newDeleteBtn = deleteFromModalBtn.cloneNode(true);
                    deleteFromModalBtn.parentNode.replaceChild(newDeleteBtn, deleteFromModalBtn);
                    newDeleteBtn.addEventListener('click', function() {
                        confirmDeleteUser(userData.id, userData.nombre);
                    });
                }
            }
            
            const modal = new bootstrap.Modal(document.getElementById('userModal'));
            modal.show();
        }

        // Función para resetear el formulario (modo nuevo usuario)
        function resetFormForNew() {
            currentEditUser = null;
            document.getElementById('userForm').reset();
            document.getElementById('modalTitle').textContent = 'Nuevo Usuario';
            document.getElementById('formAction').value = 'crear';
            document.getElementById('userId').value = '';

            const passwordField = document.getElementById('password');
            const passwordRequired = document.getElementById('passwordRequired');
            const passwordHelp = document.getElementById('passwordHelp');

            passwordField.required = true;
            if (passwordRequired) passwordRequired.style.display = 'inline';
            if (passwordHelp) passwordHelp.textContent = 'Mínimo 6 caracteres';

            const submitBtn = document.getElementById('submitUserBtn');
            const totalUsuarios = <?php echo $total_usuarios; ?>;
            const limiteUsuarios = <?php echo $limite_usuarios; ?>;
            if (submitBtn) {
                submitBtn.disabled = (totalUsuarios >= limiteUsuarios);
            }
            
            // Ocultar botones de acción
            const modalActions = document.getElementById('modalActions');
            if (modalActions) {
                modalActions.style.display = 'none';
            }

            toggleNuevaSucursal(false);
        }

        // Función para crear una nueva sucursal
        function toggleNuevaSucursal(mostrar) {
            const nuevaSucursalRow = document.getElementById('nuevaSucursalRow');
            const sucursalSelect = document.getElementById('sucursal_id');
            if (nuevaSucursalRow) {
                nuevaSucursalRow.style.display = mostrar ? 'block' : 'none';
            }
            if (sucursalSelect) sucursalSelect.disabled = mostrar;
            if (!mostrar && document.getElementById('nuevaSucursalNombre')) {
                document.getElementById('nuevaSucursalNombre').value = '';
            }
        }

        function guardarNuevaSucursal(nombreSucursal, callback) {
            if (!nombreSucursal.trim()) {
                Swal.fire('Error', 'Por favor ingresa un nombre para la sucursal.', 'error');
                return;
            }

            const btnGuardar = document.getElementById('btnGuardarSucursal');
            const originalText = btnGuardar ? btnGuardar.innerHTML : '';
            if (btnGuardar) {
                btnGuardar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
                btnGuardar.disabled = true;
            }

            fetch('guardar_sucursal.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ accion: 'crear', nombre: nombreSucursal })
            })
            .then(response => response.json())
            .then(response => {
                if (response.success) {
                    const sucursalSelect = document.getElementById('sucursal_id');
                    const nuevaOpcion = document.createElement('option');
                    nuevaOpcion.value = response.sucursal_id;
                    nuevaOpcion.textContent = response.nombre;
                    nuevaOpcion.selected = true;
                    if (sucursalSelect) sucursalSelect.appendChild(nuevaOpcion);
                    toggleNuevaSucursal(false);
                    Swal.fire('Éxito', response.message, 'success');
                    if (callback) callback(response.sucursal_id, response.nombre);
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error AJAX:', error);
                Swal.fire('Error', 'Error de conexión', 'error');
            })
            .finally(() => {
                if (btnGuardar) {
                    btnGuardar.innerHTML = originalText;
                    btnGuardar.disabled = false;
                }
            });
        }

        // Agregar evento a las tarjetas de estadísticas
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function(e) {
                e.stopPropagation();
                
                const statType = this.getAttribute('data-stat-type');
                if (statType) {
                    const modalBody = document.getElementById('modalListaUsuariosBody');
                    if (modalBody) {
                        modalBody.innerHTML = `
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Cargando...</span>
                                </div>
                            </div>
                        `;
                        setTimeout(() => {
                            modalBody.innerHTML = generarListaUsuarios(statType);
                        }, 100);
                    }
                }
            });
        });

        // Variables para swipe sidebar
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        let isTouchActive = false;
        const SWIPE_THRESHOLD = 50;
        const SWIPE_EDGE_ZONE = 30;
        const VERTICAL_THRESHOLD = 30;

        function openSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            if (sidebar && sidebarBackdrop) {
                sidebar.classList.add('show');
                sidebarBackdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            if (sidebar && sidebarBackdrop) {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        document.addEventListener('touchstart', function(e) {
            if (window.innerWidth >= 768) return;
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            touchEndX = touchStartX;
            touchEndY = touchStartY;
            isTouchActive = true;
        });

        document.addEventListener('touchmove', function(e) {
            if (!isTouchActive) return;
            touchEndX = e.touches[0].clientX;
            touchEndY = e.touches[0].clientY;
            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;
            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                e.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('touchend', function(e) {
            if (!isTouchActive) return;
            isTouchActive = false;
            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;
            if (Math.abs(deltaY) > VERTICAL_THRESHOLD) return;
            const sidebar = document.getElementById('sidebar');
            const isSidebarOpen = sidebar && sidebar.classList.contains('show');
            if (deltaX > SWIPE_THRESHOLD && touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                openSidebarAuto();
            } else if (deltaX < -SWIPE_THRESHOLD && isSidebarOpen) {
                closeSidebarAuto();
            }
            touchStartX = 0;
            touchStartY = 0;
            touchEndX = 0;
            touchEndY = 0;
        });

        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function toggleSidebar() {
                if (sidebar.classList.contains('show')) {
                    closeSidebarAuto();
                } else {
                    openSidebarAuto();
                }
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebarAuto);
            }

            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebarAuto();
                    }
                });
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebarAuto();
                }
            });

            // ============================================
            // CLICK EN FILAS DE TABLA (DESKTOP)
            // ============================================
            const clickableRows = document.querySelectorAll('.clickable-row');
            clickableRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Evitar que el click se propague si se hizo clic en un enlace o botón dentro de la fila
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                        return;
                    }
                    const userDataAttr = this.getAttribute('data-user');
                    if (userDataAttr) {
                        const userData = JSON.parse(userDataAttr);
                        openEditModal(userData);
                    }
                });
            });

            // ============================================
            // CLICK EN TARJETAS DE USUARIO (MÓVIL)
            // ============================================
            const clickableCards = document.querySelectorAll('.clickable-card');
            clickableCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    const userDataAttr = this.getAttribute('data-user');
                    if (userDataAttr) {
                        const userData = JSON.parse(userDataAttr);
                        openEditModal(userData);
                    }
                });
            });

            // Búsqueda en tiempo real
            const searchInput = document.getElementById('searchInput');
            const showInactiveCheckbox = document.getElementById('showInactive');

            function filterUsers() {
                const searchTerm = (searchInput ? searchInput.value.toLowerCase() : '');
                const showInactive = showInactiveCheckbox ? showInactiveCheckbox.checked : true;

                // Filtrar tabla (desktop)
                const tableRows = document.querySelectorAll('#usersTable tbody tr');
                tableRows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const isActive = row.querySelector('.status-badge')?.classList.contains('status-active');
                    const matchesSearch = text.includes(searchTerm);
                    const matchesStatus = showInactive || isActive;
                    row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
                });

                // Filtrar tarjetas (móvil)
                const cards = document.querySelectorAll('#usersCardsContainer .user-card');
                cards.forEach(card => {
                    const cardText = (card.getAttribute('data-username') || '') + ' ' + 
                                     (card.getAttribute('data-nombre') || '') + ' ' + 
                                     (card.getAttribute('data-email') || '');
                    const isActiveCard = card.getAttribute('data-activo') === '1';
                    const matchesSearchCard = cardText.includes(searchTerm);
                    const matchesStatusCard = showInactive || isActiveCard;
                    card.style.display = (matchesSearchCard && matchesStatusCard) ? '' : 'none';
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', filterUsers);
            }
            if (showInactiveCheckbox) {
                showInactiveCheckbox.addEventListener('change', filterUsers);
            }

            // Resetear formulario para nuevo usuario
            const newUserBtn = document.getElementById('newUserBtn');
            if (newUserBtn) {
                newUserBtn.addEventListener('click', resetFormForNew);
            }

            // Al cerrar el modal, resetear
            const userModal = document.getElementById('userModal');
            if (userModal) {
                userModal.addEventListener('hidden.bs.modal', function() {
                    resetFormForNew();
                });
            }

            // Funciones para nueva sucursal
            const btnNuevaSucursal = document.getElementById('btnNuevaSucursal');
            const btnCancelarSucursal = document.getElementById('btnCancelarSucursal');
            const btnGuardarSucursal = document.getElementById('btnGuardarSucursal');
            const nuevaSucursalNombre = document.getElementById('nuevaSucursalNombre');

            if (btnNuevaSucursal) {
                btnNuevaSucursal.addEventListener('click', () => toggleNuevaSucursal(true));
            }
            if (btnCancelarSucursal) {
                btnCancelarSucursal.addEventListener('click', () => toggleNuevaSucursal(false));
            }
            if (btnGuardarSucursal && nuevaSucursalNombre) {
                btnGuardarSucursal.addEventListener('click', () => guardarNuevaSucursal(nuevaSucursalNombre.value));
                nuevaSucursalNombre.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') btnGuardarSucursal.click();
                });
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebarAuto);
            }
        });
    </script>
</body>

</html>
