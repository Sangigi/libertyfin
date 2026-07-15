<?php
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$conn_main = getDBConnection();

$empresa_plan = "prueba"; // Valor por defecto
if ($conn_main) {
    $sql_plan = "SELECT plan FROM empresas WHERE id = ?";
    $stmt_plan = $conn_main->prepare($sql_plan);
    $stmt_plan->execute([$_SESSION['empresa_id']]);
    $result_plan = $stmt_plan->fetch(PDO::FETCH_ASSOC);
    if ($result_plan) {
        $empresa_plan = $result_plan['plan'];
    }
    $stmt_plan = null;
    $conn_main = null;
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Determinar límite de sucursales según el plan
switch ($empresa_plan) {
    case 'prueba':
        $limite_sucursales = 2;
        break;
    case 'basico':
        $limite_sucursales = 1;
        break;
    case 'emprendedor':
        $limite_sucursales = 6;
        break;
    case 'premium':
        $limite_sucursales = 11;
        break;
    default:
        $limite_sucursales = 1;
}

// Conectar a la base de datos de la empresa
try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Obtener información de la empresa Y CONFIGURACIÓN DE COLORES
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

    // Establecer colores por defecto si no existen
    $color_primario = $empresa_info['color_primario'] ?? '#27ae60';
    $color_secundario = $empresa_info['color_secundario'] ?? '#2ecc71';

    $_SESSION['color_primario'] = $color_primario;
    $_SESSION['color_secundario'] = $color_secundario;

    // Obtener sucursales
    $sql_sucursales = "
        SELECT 
            s.*,
            COALESCE(s.fecha_actualizacion, s.fecha_creacion, NOW()) as fecha_actualizacion_orden
        FROM sucursales s 
        ORDER BY COALESCE(s.fecha_actualizacion, s.fecha_creacion, NOW()) DESC, s.id DESC
    ";
    $result_sucursales = $conn->query($sql_sucursales);
    $sucursales = [];
    while ($row = $result_sucursales->fetch(PDO::FETCH_ASSOC)) {
        $row['nombre'] = $row['nombre'] ?? '';
        $row['direccion'] = $row['direccion'] ?? '';
        $row['telefono'] = $row['telefono'] ?? '';
        $row['email'] = $row['email'] ?? '';
        $row['responsable'] = $row['responsable'] ?? '';
        $row['fecha_creacion'] = $row['fecha_creacion'] ?? date('Y-m-d H:i:s');
        $sucursales[] = $row;
    }

    $total_sucursales = count($sucursales);
    $puede_crear_mas = $total_sucursales < $limite_sucursales;

    // Obtener usuarios
    $sql_usuarios = "
        SELECT id, nombre, email, rol, sucursal_id 
        FROM usuarios 
        WHERE activo = 1 
        ORDER BY nombre ASC
    ";
    $result_usuarios = $conn->query($sql_usuarios);
    $usuarios = $result_usuarios->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas
    $sql_stats = "
        SELECT 
            COUNT(*) as total_sucursales,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as sucursales_activas,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as sucursales_inactivas
        FROM sucursales
    ";
    $result_stats = $conn->query($sql_stats);
    $stats_sucursales = $result_stats->fetch(PDO::FETCH_ASSOC);

    // Ventas por sucursal
    $sql_ventas_sucursal = "
        SELECT 
            sucursal_id,
            COUNT(*) as total_ventas,
            SUM(total) as monto_total,
            COUNT(DISTINCT usuario_id) as total_usuarios
        FROM ventas 
        WHERE sucursal_id IS NOT NULL 
        GROUP BY sucursal_id
    ";
    $result_ventas_sucursal = $conn->query($sql_ventas_sucursal);
    $ventas_por_sucursal = [];
    while ($row = $result_ventas_sucursal->fetch(PDO::FETCH_ASSOC)) {
        $ventas_por_sucursal[$row['sucursal_id']] = $row;
    }

    // Usuarios por sucursal
    $sql_usuarios_sucursal = "
        SELECT 
            sucursal_id,
            COUNT(*) as total_usuarios
        FROM usuarios 
        WHERE sucursal_id IS NOT NULL 
        GROUP BY sucursal_id
    ";
    $result_usuarios_sucursal = $conn->query($sql_usuarios_sucursal);
    $usuarios_por_sucursal = [];
    while ($row = $result_usuarios_sucursal->fetch(PDO::FETCH_ASSOC)) {
        $usuarios_por_sucursal[$row['sucursal_id']] = $row['total_usuarios'];
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear':
                crearSucursal($conn, $limite_sucursales);
                break;
            case 'editar':
                editarSucursal($conn);
                break;
            case 'cambiar_estado':
                cambiarEstadoSucursal($conn);
                break;
            case 'eliminar':
                eliminarSucursal($conn);
                break;
        }
    }
}

function crearSucursal($conn, $limite_sucursales)
{
    $sql_count = "SELECT COUNT(*) as total FROM sucursales";
    $result_count = $conn->query($sql_count);
    $count_data = $result_count->fetch(PDO::FETCH_ASSOC);
    $total_sucursales = $count_data['total'];

    if ($total_sucursales >= $limite_sucursales) {
        $_SESSION['mensaje'] = "Has alcanzado el límite de sucursales permitido para tu plan.";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: sucursales.php');
        exit();
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    $responsable = '';
    if (isset($_POST['modo_manual']) && $_POST['modo_manual'] == '1') {
        $responsable = trim($_POST['responsable_manual'] ?? '');
    } else {
        $responsable = trim($_POST['responsable'] ?? '');
    }

    try {
        $sql_verificar = "SELECT id FROM sucursales WHERE nombre = ?";
        $stmt_verificar = $conn->prepare($sql_verificar);
        $stmt_verificar->execute([$nombre]);
        $result_verificar = $stmt_verificar->fetchAll(PDO::FETCH_ASSOC);

        if (count($result_verificar) > 0) {
            throw new Exception("Ya existe una sucursal con ese nombre");
        }
        $stmt_verificar = null;

        $sql = "INSERT INTO sucursales (nombre, direccion, telefono, email, responsable, fecha_creacion) 
                VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $direccion, $telefono, $email, $responsable]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['mensaje'] = "Sucursal creada exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al crear sucursal");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: sucursales.php');
    exit();
}

function editarSucursal($conn)
{
    $id = intval($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    $responsable = '';
    if (isset($_POST['modo_manual']) && $_POST['modo_manual'] == '1') {
        $responsable = trim($_POST['responsable_manual'] ?? '');
    } else {
        $responsable = trim($_POST['responsable'] ?? '');
    }

    try {
        $sql_verificar = "SELECT id FROM sucursales WHERE nombre = ? AND id != ?";
        $stmt_verificar = $conn->prepare($sql_verificar);
        $stmt_verificar->execute([$nombre, $id]);
        $result_verificar = $stmt_verificar->fetchAll(PDO::FETCH_ASSOC);

        if (count($result_verificar) > 0) {
            throw new Exception("Ya existe otra sucursal con ese nombre");
        }
        $stmt_verificar = null;

        $sql = "UPDATE sucursales SET 
                nombre = ?, direccion = ?, telefono = ?, email = ?, responsable = ?, fecha_actualizacion = NOW() 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $direccion, $telefono, $email, $responsable, $id]);

        if ($stmt->rowCount() >= 0) {
            $_SESSION['mensaje'] = "Sucursal actualizada exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al actualizar sucursal");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: sucursales.php');
    exit();
}

function cambiarEstadoSucursal($conn)
{
    $id = intval($_POST['id'] ?? 0);
    $activo = intval($_POST['activo'] ?? 0);

    try {
        $sql = "UPDATE sucursales SET activo = ?, fecha_actualizacion = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$activo, $id]);

        if ($stmt->rowCount() >= 0) {
            $estado = $activo ? "activada" : "desactivada";
            $_SESSION['mensaje'] = "Sucursal $estado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al cambiar estado");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: sucursales.php');
    exit();
}

function eliminarSucursal($conn)
{
    // Verificar que el usuario sea administrador
    if ($_SESSION['usuario_rol'] !== 'admin') {
        $_SESSION['mensaje'] = "No tienes permisos para eliminar sucursales";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: sucursales.php');
        exit();
    }

    $id = intval($_POST['id'] ?? 0);
    
    // Evitar eliminar la sucursal principal (ID 1)
    if ($id == 1) {
        $_SESSION['mensaje'] = "No se puede eliminar la sucursal principal";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: sucursales.php');
        exit();
    }

    try {
        // Iniciar transacción
        $conn->beginTransaction();

        // 1. Eliminar movimientos de caja relacionados
        $sql = "DELETE FROM movimientos_caja WHERE sucursal_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $stmt = null;

        // 2. Eliminar transacciones emida
        $sql = "DELETE FROM emida_transacciones WHERE sucursal_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $stmt = null;

        // 3. Eliminar ventas
        $sql = "DELETE FROM ventas WHERE sucursal_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $stmt = null;

        // 4. Eliminar compras
        $sql = "DELETE FROM compras WHERE sucursal_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $stmt = null;

        // 5. Eliminar movimientos de inventario
        $sql = "DELETE FROM movimientos_inventario WHERE sucursal_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $stmt = null;

        // 6. Eliminar registros de producto_sucursal
        $sql = "DELETE FROM producto_sucursal WHERE sucursal_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $stmt = null;

        // 7. Manejar usuarios: primero reasignar o eliminar
        $sql_check_usuarios = "SELECT id FROM usuarios WHERE sucursal_id = ?";
        $stmt_check = $conn->prepare($sql_check_usuarios);
        $stmt_check->execute([$id]);
        $result_usuarios = $stmt_check->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($result_usuarios) > 0) {
            // Eliminar los usuarios de esta sucursal
            $sql_delete_usuarios = "DELETE FROM usuarios WHERE sucursal_id = ?";
            $stmt_del_usuarios = $conn->prepare($sql_delete_usuarios);
            $stmt_del_usuarios->execute([$id]);
            $stmt_del_usuarios = null;
        }
        $stmt_check = null;

        // 8. Eliminar caja
        $sql = "DELETE FROM caja WHERE sucursal_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $stmt = null;

        // 9. Finalmente eliminar la sucursal
        $sql = "DELETE FROM sucursales WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            $conn->commit();
            $_SESSION['mensaje'] = "Sucursal eliminada exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al eliminar sucursal");
        }

        $stmt = null;
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['mensaje'] = "Error al eliminar sucursal: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: sucursales.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Sucursales - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars($color_primario); ?>;
            --secondary-color: <?php echo htmlspecialchars($color_secundario); ?>;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
        }

        /* Navbar */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        /* Sidebar */
        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: transform 0.3s ease-out;
            will-change: transform;
            transform: translateX(-100%);
            z-index: 1050;
        }

        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        /* Cards */
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            margin-bottom: 1rem;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        /* Tarjetas de estadísticas clickeables */
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

        /* Botones */
        .btn-primary, .btn-success {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover, .btn-success:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        /* Sidebar toggle */
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
            top: 56px;
            left: 0;
            width: 100%;
            height: calc(100vh - 56px);
            background: rgba(0, 0, 0, 0.5);
            z-index: 1045;
        }
        .sidebar-backdrop.show {
            display: block;
        }

        /* Filas y tarjetas clickeables */
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .clickable-row:hover {
            background-color: rgba(0, 0, 0, 0.05) !important;
        }
        .clickable-card {
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .clickable-card:active {
            transform: scale(0.98);
        }

        /* Badges */
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
        .stats-badge {
            background: #e8f4fd;
            color: #2c3e50;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .responsable-badge {
            background: #fff3cd;
            color: #856404;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        /* Avatar sucursal */
        .sucursal-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1rem;
        }

        /* Search box */
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

        /* Métricas */
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

        /* Monto ventas */
        .monto-ventas {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        /* Info text */
        .info-text {
            font-size: 0.8rem;
            color: #6c757d;
        }

        /* Plan limit alert */
        .plan-limit-alert {
            background: linear-gradient(135deg, #ff6b6b, #ff8e8e);
            color: white;
            border-left: 4px solid #ff3838;
        }
        .plan-info-badge {
            background: #ffeb3b;
            color: #856404;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Sucursal principal */
        .sucursal-principal {
            border-left: 4px solid var(--primary-color) !important;
        }

        /* Colores de texto */
        .text-primary {
            color: var(--primary-color) !important;
        }
        .text-success {
            color: var(--secondary-color) !important;
        }

        /* ============================================ */
        /* ESTILOS PARA MODALES MEJORADOS EN MÓVIL */
        /* ============================================ */
        
        /* Modal de detalles - estilos móvil */
        @media (max-width: 768px) {
            .detalle-card-mobile { 
                margin-bottom: 0.75rem; 
                border-radius: 12px; 
                overflow: hidden; 
            }
            .detalle-label-mobile { 
                font-size: 0.65rem; 
                text-transform: uppercase; 
                letter-spacing: 0.5px;
                font-weight: 600;
            }
            .detalle-valor-mobile { 
                font-size: 0.85rem; 
                font-weight: 500; 
                word-break: break-word; 
            }
            
            /* Modal de edición - estilos móvil */
            .form-label-mobile { 
                font-size: 0.75rem; 
                font-weight: 600; 
                margin-bottom: 0.25rem; 
            }
            .form-control-mobile, .form-select-mobile { 
                font-size: 0.85rem; 
                padding: 0.5rem 0.75rem; 
                border-radius: 10px;
            }
            .form-text-mobile { 
                font-size: 0.65rem; 
            }
            .alert-mobile { 
                padding: 0.5rem 0.75rem; 
                font-size: 0.7rem; 
                margin-bottom: 0.75rem; 
                border-radius: 10px;
            }
            .info-card-mobile { 
                padding: 0.5rem; 
                margin-bottom: 0.75rem; 
                border-radius: 10px;
            }
            .info-card-mobile small { 
                font-size: 0.65rem; 
            }
            .mb-3-mobile { 
                margin-bottom: 0.75rem; 
            }
            
            /* Modal header más compacto */
            .modal-header {
                padding: 0.75rem 1rem;
            }
            .modal-header .modal-title {
                font-size: 1rem;
            }
            .modal-body {
                padding: 0.75rem;
            }
            .modal-footer {
                padding: 0.75rem;
            }
        }
        
        /* ============================================ */
        /* BOTONES EN MODAL - RESPONSIVE (CORREGIDO) */
        /* ============================================ */
        .btn-group-mobile-stack { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 0.5rem; 
            align-items: center; 
        }

        @media (max-width: 768px) {
            .btn-group-mobile-stack { 
                flex-direction: column; 
                width: 100%; 
            }
            .btn-group-mobile-stack .btn { 
                margin: 0 !important; 
                width: 100%; 
                padding: 0.6rem; 
                font-size: 0.85rem; 
                border-radius: 10px;
            }
        }

        @media (min-width: 769px) {
            .btn-group-mobile-stack {
                flex-direction: row;
                justify-content: flex-end;
            }
            .btn-group-mobile-stack .btn {
                width: auto;
                min-width: 140px;
            }
        }
        
        /* Estilos para escritorio */
        @media (min-width: 769px) {
            .detalle-card-mobile {
                margin-bottom: 1rem;
            }
            .form-label-mobile {
                font-size: 0.85rem;
                font-weight: 600;
            }
        }

        /* Sidebar responsive */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }
            .sidebar {
                position: fixed;
                top: 56px;
                left: 0;
                width: 280px;
                height: calc(100vh - 56px);
                overflow-y: auto;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            main {
                margin-left: 0 !important;
                padding: 0.75rem !important;
            }
            body.sidebar-open {
                overflow: hidden;
            }
            .stat-card .card-body {
                padding: 0.75rem;
            }
            .metric-value {
                font-size: 1.3rem;
            }
            .metric-label {
                font-size: 0.7rem;
            }
            .mobile-sucursal-card {
                border-left: 4px solid var(--primary-color);
                margin-bottom: 0.75rem;
            }
        }

        @media (min-width: 768px) {
            .sidebar {
                transform: translateX(0) !important;
            }
        }
    </style>
</head>

<body>
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
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li><hr class="dropdown-divider"></li>
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
                        <li class="nav-item"><a class="nav-link" href="Inicio"><i class="fas fa-tachometer-alt"></i> Inicio</a></li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="Usuarios"><i class="fas fa-user-cog"></i> Usuarios</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link" href="Caja"><i class="fas fa-cash-register"></i> Caja</a></li>
                        <li class="nav-item"><a class="nav-link" href="Productos"><i class="fas fa-boxes"></i> Productos</a></li>
                        <li class="nav-item"><a class="nav-link" href="Clientes"><i class="fas fa-users"></i> Clientes</a></li>
                        <li class="nav-item"><a class="nav-link" href="Ventas"><i class="fas fa-receipt"></i> Ventas</a></li>
                        <li class="nav-item"><a class="nav-link" href="CortesCaja"><i class="fas fa-cash-register"></i> Cortes de Caja</a></li>
                        <li class="nav-item"><a class="nav-link" href="Proveedores"><i class="fas fa-truck"></i> Proveedores</a></li>
                        <?php if ($empresa_plan !== 'basico' && $_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link active" href="Sucursales"><i class="fas fa-store"></i> Sucursales</a></li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1): ?>
                            <li class="nav-item"><a class="nav-link" href="Facturacion/inicio.php"><i class="fas fa-file-invoice-dollar"></i> Facturación</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link" href="Reportes"><i class="fas fa-chart-bar"></i> Reportes</a></li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="comisiones_config.php"><i class="fas fa-percentage"></i> Comisiones</a></li>
                            <li class="nav-item"><a class="nav-link" href="Configuracion"><i class="fas fa-cogs"></i> Configuración</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-column flex-md-row">
                    <h1 class="h3 mb-3 mb-md-0"><i class="fas fa-store me-2"></i>Gestión de Sucursales</h1>
                    <?php if ($puede_crear_mas): ?>
                        <button class="btn btn-primary btn-action" id="newSucursalBtn">
                            <i class="fas fa-plus me-2"></i>Nueva Sucursal
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-action" disabled>
                            <i class="fas fa-ban me-2"></i>Límite Alcanzado
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!$puede_crear_mas): ?>
                    <div class="alert plan-limit-alert d-flex align-items-center mb-4">
                        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                        <div>
                            <h5 class="alert-heading mb-1">¡Límite de sucursales alcanzado!</h5>
                            <p class="mb-2">Has alcanzado el límite de <strong><?php echo $limite_sucursales; ?> sucursales</strong> permitidas para tu plan <strong><?php echo ucfirst($empresa_plan); ?></strong>.</p>
                            <div class="d-flex align-items-center">
                                <span class="me-3">Usado: <?php echo $total_sucursales; ?>/<?php echo $limite_sucursales; ?> sucursales</span>
                                <div class="progress flex-grow-1" style="height: 8px; max-width: 200px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo min(100, ($total_sucursales / $limite_sucursales) * 100); ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card mb-4">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center flex-wrap">
                                <div>
                                    <small class="text-muted">Plan actual:</small>
                                    <span class="fw-bold ms-2"><?php echo ucfirst($empresa_plan); ?></span>
                                    <span class="plan-info-badge ms-2"><?php echo $total_sucursales; ?>/<?php echo $limite_sucursales; ?> sucursales</span>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">Puedes crear <?php echo $limite_sucursales - $total_sucursales; ?> sucursales más</small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['mensaje'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['mensaje']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
                <?php endif; ?>

                <!-- Estadísticas clickeables -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-stat-type="total">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Sucursales</div>
                                        <div class="metric-value text-primary"><?php echo $stats_sucursales['total_sucursales'] ?? 0; ?></div>
                                    </div>
                                    <div><i class="fas fa-store fa-2x text-primary opacity-25"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-stat-type="activos">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Sucursales Activas</div>
                                        <div class="metric-value text-success"><?php echo $stats_sucursales['sucursales_activas'] ?? 0; ?></div>
                                    </div>
                                    <div><i class="fas fa-check-circle fa-2x text-success opacity-25"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-stat-type="con_ventas">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Con Ventas</div>
                                        <div class="metric-value text-info"><?php echo count($ventas_por_sucursal); ?></div>
                                    </div>
                                    <div><i class="fas fa-chart-line fa-2x text-info opacity-25"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-5 mb-3 mb-md-0">
                                <div class="search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="text" class="form-control" placeholder="Buscar sucursales..." id="searchInput">
                                </div>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <select class="form-select" id="estadoFilter">
                                    <option value="">Todos los estados</option>
                                    <option value="activo">Activas</option>
                                    <option value="inactivo">Inactivas</option>
                                    <option value="con_ventas">Con ventas</option>
                                    <option value="sin_ventas">Sin ventas</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="showResponsable" checked>
                                    <label class="form-check-label" for="showResponsable">Mostrar responsable</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vista de Tabla (Desktop) - SIN columna de acciones -->
                <div class="d-none d-lg-block">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Lista de Sucursales
                                <span class="badge bg-secondary ms-2"><?php echo $total_sucursales; ?> registros</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="sucursalesTable">
                                    <thead>
                                        <tr>
                                            <th>Sucursal</th>
                                            <th>Contacto</th>
                                            <th id="responsableHeader">Responsable</th>
                                            <th>Ventas</th>
                                            <th>Usuarios</th>
                                            <th>Estado</th>
                                            <th>Registro</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sucursales as $sucursal):
                                            $ventas_sucursal = $ventas_por_sucursal[$sucursal['id']] ?? null;
                                            $total_ventas = $ventas_sucursal['total_ventas'] ?? 0;
                                            $monto_total = $ventas_sucursal['monto_total'] ?? 0;
                                            $total_usuarios = $usuarios_por_sucursal[$sucursal['id']] ?? 0;
                                            $es_principal = $sucursal['id'] == 1;
                                        ?>
                                            <tr class="clickable-row <?php echo $es_principal ? 'sucursal-principal' : ''; ?>"
                                                data-id="<?php echo $sucursal['id']; ?>"
                                                data-nombre="<?php echo htmlspecialchars($sucursal['nombre']); ?>"
                                                data-direccion="<?php echo htmlspecialchars($sucursal['direccion']); ?>"
                                                data-telefono="<?php echo htmlspecialchars($sucursal['telefono']); ?>"
                                                data-email="<?php echo htmlspecialchars($sucursal['email']); ?>"
                                                data-responsable="<?php echo htmlspecialchars($sucursal['responsable']); ?>"
                                                data-activo="<?php echo $sucursal['activo']; ?>"
                                                data-fecha_creacion="<?php echo $sucursal['fecha_creacion']; ?>"
                                                data-total_ventas="<?php echo $total_ventas; ?>"
                                                data-monto_total="<?php echo $monto_total; ?>"
                                                data-total_usuarios="<?php echo $total_usuarios; ?>">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="sucursal-avatar me-3"><?php echo strtoupper(substr($sucursal['nombre'], 0, 2)); ?></div>
                                                        <div>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($sucursal['nombre']); ?></div>
                                                            <?php if (!empty($sucursal['direccion'])): ?>
                                                                <div class="contacto-info"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($sucursal['direccion']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="contacto-info">
                                                        <?php if (!empty($sucursal['telefono'])): ?>
                                                            <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($sucursal['telefono']); ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($sucursal['email'])): ?>
                                                            <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($sucursal['email']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="responsable-cell">
                                                    <?php if (!empty($sucursal['responsable'])): ?>
                                                        <span class="responsable-badge">
                                                            <i class="fas fa-user me-1"></i>
                                                            <?php echo htmlspecialchars($sucursal['responsable']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">No asignado</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($total_ventas > 0): ?>
                                                        <div>
                                                            <span class="stats-badge"><i class="fas fa-shopping-cart me-1"></i><?php echo $total_ventas; ?> ventas</span>
                                                            <div class="monto-ventas">$<?php echo number_format($monto_total, 2); ?></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Sin ventas</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($total_usuarios > 0): ?>
                                                        <span class="stats-badge"><i class="fas fa-users me-1"></i><?php echo $total_usuarios; ?> usuarios</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Sin usuarios</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $sucursal['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $sucursal['activo'] ? 'Activa' : 'Inactiva'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($sucursal['fecha_creacion'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vista de Tarjetas (Móvil) - SIN botones de acción -->
                <div class="d-lg-none">
                    <div class="row" id="mobileSucursales">
                        <?php foreach ($sucursales as $sucursal):
                            $ventas_sucursal = $ventas_por_sucursal[$sucursal['id']] ?? null;
                            $total_ventas = $ventas_sucursal['total_ventas'] ?? 0;
                            $monto_total = $ventas_sucursal['monto_total'] ?? 0;
                            $total_usuarios = $usuarios_por_sucursal[$sucursal['id']] ?? 0;
                            $es_principal = $sucursal['id'] == 1;
                        ?>
                            <div class="col-12 mb-3">
                                <div class="card mobile-sucursal-card clickable-card <?php echo $es_principal ? 'sucursal-principal' : ''; ?>"
                                    data-id="<?php echo $sucursal['id']; ?>"
                                    data-nombre="<?php echo htmlspecialchars($sucursal['nombre']); ?>"
                                    data-direccion="<?php echo htmlspecialchars($sucursal['direccion']); ?>"
                                    data-telefono="<?php echo htmlspecialchars($sucursal['telefono']); ?>"
                                    data-email="<?php echo htmlspecialchars($sucursal['email']); ?>"
                                    data-responsable="<?php echo htmlspecialchars($sucursal['responsable']); ?>"
                                    data-activo="<?php echo $sucursal['activo']; ?>"
                                    data-fecha_creacion="<?php echo $sucursal['fecha_creacion']; ?>"
                                    data-total_ventas="<?php echo $total_ventas; ?>"
                                    data-monto_total="<?php echo $monto_total; ?>"
                                    data-total_usuarios="<?php echo $total_usuarios; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="d-flex align-items-center">
                                                <div class="sucursal-avatar me-3"><?php echo strtoupper(substr($sucursal['nombre'], 0, 2)); ?></div>
                                                <div>
                                                    <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($sucursal['nombre']); ?></h6>
                                                    <?php if (!empty($sucursal['direccion'])): ?>
                                                        <div class="info-text"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($sucursal['direccion']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="status-badge <?php echo $sucursal['activo'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $sucursal['activo'] ? 'Activa' : 'Inactiva'; ?></span>
                                        </div>

                                        <div class="contacto-info mb-2">
                                            <?php if (!empty($sucursal['telefono'])): ?>
                                                <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($sucursal['telefono']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($sucursal['email'])): ?>
                                                <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($sucursal['email']); ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="responsable-mobile mb-2">
                                            <?php if (!empty($sucursal['responsable'])): ?>
                                                <span class="responsable-badge">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php echo htmlspecialchars($sucursal['responsable']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="row text-center">
                                            <div class="col-4">
                                                <small class="text-muted d-block">Ventas</small>
                                                <span class="stats-badge"><?php echo $total_ventas; ?></span>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted d-block">Monto</small>
                                                <?php if ($monto_total > 0): ?>
                                                    <span class="monto-ventas">$<?php echo number_format($monto_total, 2); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">$0.00</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted d-block">Usuarios</small>
                                                <span class="stats-badge"><?php echo $total_usuarios; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para Nuevo/Editar Sucursal - MEJORADO PARA MÓVIL -->
    <div class="modal fade" id="sucursalModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="modalTitle">
                        <i class="fas fa-store me-2" style="color: var(--primary-color);"></i>
                        Nueva Sucursal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="sucursalForm">
                    <div class="modal-body pt-0" id="modalBodyContent">
                        <!-- El contenido se llena dinámicamente con JavaScript -->
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Ver Detalles (CON BOTONES DE ACCIÓN) - MEJORADO PARA MÓVIL -->
    <div class="modal fade" id="detallesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="detallesModalTitle">
                        <i class="fas fa-store me-2" style="color: var(--primary-color);"></i>
                        Detalles de la Sucursal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0" id="detallesContent"></div>
                <div class="modal-footer border-0 pt-0" id="detallesFooter"></div>
            </div>
        </div>
    </div>

    <!-- Modal para Confirmar Eliminación -->
    <div class="modal fade" id="confirmarEliminarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar la sucursal <strong id="sucursalNombreEliminar"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Esta acción:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Eliminará todas las ventas asociadas</li>
                            <li>Eliminará todos los usuarios de esta sucursal</li>
                            <li>Eliminará todos los movimientos de caja</li>
                            <li>Eliminará todas las compras relacionadas</li>
                            <li>Eliminará los movimientos de inventario</li>
                            <li><strong class="text-danger">¡Esta acción no se puede deshacer!</strong></li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="formEliminarSucursal">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" id="sucursalIdEliminar">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Eliminar Sucursal</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Variables globales
        const esAdmin = <?php echo ($_SESSION['usuario_rol'] === 'admin') ? 'true' : 'false'; ?>;
        const usuariosData = <?php echo json_encode($usuarios); ?>;
        const empresaPlan = '<?php echo $empresa_plan; ?>';
        const totalSucursales = <?php echo $total_sucursales; ?>;
        const limiteSucursales = <?php echo $limite_sucursales; ?>;

        // Función para escapar HTML
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // ============================================
        // FUNCIÓN PARA GENERAR FORMULARIO DE SUCURSAL
        // ============================================
        function generarFormularioSucursal(modo, datos = null) {
            const isEdit = modo === 'editar';
            const responsableValue = datos ? (datos.responsable || '') : '';
            const responsableSeleccionado = responsableValue ? usuariosData.some(u => u.nombre === responsableValue) : false;
            
            let optionsHtml = '<option value="">Seleccionar responsable...</option>';
            usuariosData.forEach(usuario => {
                const selected = (responsableValue === usuario.nombre && responsableSeleccionado) ? 'selected' : '';
                optionsHtml += `<option value="${escapeHtml(usuario.nombre)}" data-user-id="${usuario.id}" data-user-email="${escapeHtml(usuario.email)}" data-user-rol="${escapeHtml(usuario.rol)}" ${selected}>${escapeHtml(usuario.nombre)} (${escapeHtml(usuario.email)}) - ${escapeHtml(usuario.rol)}</option>`;
            });
            
            return `
                <style>
                    @media (max-width: 768px) {
                        .form-label-mobile { font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; }
                        .form-control-mobile, .form-select-mobile { font-size: 0.85rem; padding: 0.5rem 0.75rem; border-radius: 10px; }
                        .alert-mobile { padding: 0.5rem 0.75rem; font-size: 0.7rem; margin-bottom: 0.75rem; border-radius: 10px; }
                        .info-card-mobile { padding: 0.5rem; margin-bottom: 0.75rem; border-radius: 10px; }
                        .mb-3-mobile { margin-bottom: 0.75rem; }
                    }
                </style>
                
                <input type="hidden" name="accion" id="formAction" value="${modo}">
                <input type="hidden" name="id" id="sucursalId" value="${datos ? datos.id : ''}">
                <input type="hidden" name="modo_manual" id="modoManualHidden" value="0">
                
                <!-- Información del Plan -->
                <div class="alert alert-info alert-mobile mb-3">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-info-circle me-2"></i>
                        <div class="small">
                            <strong>${empresaPlan.charAt(0).toUpperCase() + empresaPlan.slice(1)}</strong> · 
                            ${totalSucursales}/${limiteSucursales} sucursales
                        </div>
                    </div>
                </div>
                
                <!-- Campo Nombre -->
                <div class="mb-3 mb-3-mobile">
                    <label class="form-label form-label-mobile">
                        <i class="fas fa-store me-1"></i>Nombre de la Sucursal <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-mobile" name="nombre" id="nombre" 
                           required placeholder="Ej: Sucursal Centro" value="${datos ? escapeHtml(datos.nombre) : ''}">
                </div>
                
                <!-- Campo Dirección -->
                <div class="mb-3 mb-3-mobile">
                    <label class="form-label form-label-mobile">
                        <i class="fas fa-map-marker-alt me-1"></i>Dirección
                    </label>
                    <textarea class="form-control form-control-mobile" name="direccion" id="direccion" 
                              rows="3" placeholder="Calle, número, colonia, ciudad, código postal">${datos ? escapeHtml(datos.direccion) : ''}</textarea>
                </div>
                
                <div class="row g-2">
                    <!-- Campo Teléfono -->
                    <div class="col-12 col-md-6 mb-3 mb-3-mobile">
                        <label class="form-label form-label-mobile">
                            <i class="fas fa-phone me-1"></i>Teléfono
                        </label>
                        <input type="tel" class="form-control form-control-mobile" name="telefono" id="telefono" 
                               placeholder="Ej: 55 1234 5678" value="${datos ? escapeHtml(datos.telefono) : ''}">
                    </div>
                    
                    <!-- Campo Email -->
                    <div class="col-12 col-md-6 mb-3 mb-3-mobile">
                        <label class="form-label form-label-mobile">
                            <i class="fas fa-envelope me-1"></i>Email
                        </label>
                        <input type="email" class="form-control form-control-mobile" name="email" id="email" 
                               placeholder="sucursal@empresa.com" value="${datos ? escapeHtml(datos.email) : ''}">
                    </div>
                </div>
                
                <!-- Campo Responsable -->
                <div class="mb-3 mb-3-mobile">
                    <label class="form-label form-label-mobile">
                        <i class="fas fa-user me-1"></i>Responsable
                    </label>
                    <select class="form-select form-control-mobile" name="responsable" id="responsableSelect">
                        ${optionsHtml}
                    </select>
                    
                    <input type="text" class="form-control form-control-mobile mt-2 d-none" name="responsable_manual" 
                           id="responsableManual" placeholder="Ingresa manualmente el nombre del responsable"
                           value="${(responsableValue && !responsableSeleccionado) ? escapeHtml(responsableValue) : ''}">
                    
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="modoManualCheckbox" 
                               ${(responsableValue && !responsableSeleccionado) ? 'checked' : ''}>
                        <label class="form-check-label form-label-mobile" for="modoManualCheckbox">
                            <i class="fas fa-keyboard me-1"></i>Ingresar responsable manualmente
                        </label>
                    </div>
                </div>
                
                <!-- Información del Usuario Seleccionado -->
                <div class="card info-card-mobile ${(responsableValue && responsableSeleccionado) ? '' : 'd-none'}" id="userInfoCard">
                    <div class="card-body p-2 p-md-3">
                        <div class="row align-items-center">
                            <div class="col-8">
                                <small class="text-muted" id="userInfoText"></small>
                            </div>
                            <div class="col-4 text-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearResponsable">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${isEdit && datos ? `
                    <div class="alert alert-secondary alert-mobile mt-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-clock me-2"></i>
                            <div class="small">
                                <strong>Estado actual:</strong>
                                <span class="status-badge ${datos.activo == 1 ? 'status-active' : 'status-inactive'}" 
                                      style="font-size: 0.7rem; margin-left: 0.5rem;">
                                    ${datos.activo == 1 ? 'Activa' : 'Inactiva'}
                                </span>
                            </div>
                        </div>
                    </div>
                ` : ''}
            `;
        }

        // ============================================
        // FUNCIÓN PARA MOSTRAR MODAL DE DETALLES
        // ============================================
        function mostrarDetallesSucursal(sucursalData) {
            const detallesContent = document.getElementById('detallesContent');
            const detallesFooter = document.getElementById('detallesFooter');
            const modalTitle = document.getElementById('detallesModalTitle');
            
            const estadoText = sucursalData.activo == 1 ? 'Activa' : 'Inactiva';
            const estadoClass = sucursalData.activo == 1 ? 'status-active' : 'status-inactive';
            const montoTotal = parseFloat(sucursalData.monto_total || 0);
            const esPrincipal = sucursalData.id == 1;
            
            if (modalTitle) {
                modalTitle.innerHTML = `<i class="fas fa-store me-2" style="color: var(--primary-color);"></i>${escapeHtml(sucursalData.nombre)}`;
            }
            
            detallesContent.innerHTML = `
                <!-- Tarjeta de Información General -->
                <div class="card detalle-card-mobile mb-3">
                    <div class="card-body p-3 p-md-4">
                        <div class="row g-3">
                            <div class="col-6 col-md-6">
                                <div class="detalle-label-mobile text-muted mb-1">
                                    <i class="fas fa-store fa-sm me-1"></i>Estado
                                </div>
                                <div class="detalle-valor-mobile">
                                    <span class="status-badge ${estadoClass}" style="font-size: 0.75rem;">${estadoText}</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-6">
                                <div class="detalle-label-mobile text-muted mb-1">
                                    <i class="fas fa-calendar-alt fa-sm me-1"></i>Registro
                                </div>
                                <div class="detalle-valor-mobile">
                                    ${new Date(sucursalData.fecha_creacion).toLocaleDateString('es-MX')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tarjeta de Contacto -->
                <div class="card detalle-card-mobile mb-3">
                    <div class="card-body p-3 p-md-4">
                        <h6 class="mb-3 small fw-bold text-uppercase text-muted">
                            <i class="fas fa-address-card me-2"></i>Información de Contacto
                        </h6>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="detalle-label-mobile text-muted mb-1">
                                    <i class="fas fa-phone fa-sm me-1"></i>Teléfono
                                </div>
                                <div class="detalle-valor-mobile">
                                    ${sucursalData.telefono ? escapeHtml(sucursalData.telefono) : '<span class="text-muted">No especificado</span>'}
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="detalle-label-mobile text-muted mb-1">
                                    <i class="fas fa-envelope fa-sm me-1"></i>Email
                                </div>
                                <div class="detalle-valor-mobile" style="word-break: break-all;">
                                    ${sucursalData.email ? escapeHtml(sucursalData.email) : '<span class="text-muted">No especificado</span>'}
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="detalle-label-mobile text-muted mb-1">
                                    <i class="fas fa-map-marker-alt fa-sm me-1"></i>Dirección
                                </div>
                                <div class="detalle-valor-mobile">
                                    ${sucursalData.direccion ? escapeHtml(sucursalData.direccion) : '<span class="text-muted">No especificada</span>'}
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="detalle-label-mobile text-muted mb-1">
                                    <i class="fas fa-user fa-sm me-1"></i>Responsable
                                </div>
                                <div class="detalle-valor-mobile">
                                    ${sucursalData.responsable ? `<span class="responsable-badge">${escapeHtml(sucursalData.responsable)}</span>` : '<span class="text-muted">No asignado</span>'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tarjeta de Estadísticas -->
                <div class="card detalle-card-mobile mb-3">
                    <div class="card-body p-3 p-md-4">
                        <h6 class="mb-3 small fw-bold text-uppercase text-muted">
                            <i class="fas fa-chart-line me-2"></i>Estadísticas
                        </h6>
                        <div class="row g-3 text-center">
                            <div class="col-4">
                                <div class="detalle-label-mobile text-muted mb-1">Ventas</div>
                                <div class="detalle-valor-mobile text-primary">
                                    <i class="fas fa-shopping-cart me-1"></i>${sucursalData.total_ventas || 0}
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="detalle-label-mobile text-muted mb-1">Monto</div>
                                <div class="detalle-valor-mobile text-success">
                                    $${montoTotal.toLocaleString('es-MX', {minimumFractionDigits: 2})}
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="detalle-label-mobile text-muted mb-1">Usuarios</div>
                                <div class="detalle-valor-mobile text-info">
                                    <i class="fas fa-users me-1"></i>${sucursalData.total_usuarios || 0}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Botones de acción
            const nuevoEstado = sucursalData.activo == 1 ? 0 : 1;
            const estadoBotonTexto = sucursalData.activo == 1 ? 'Desactivar Sucursal' : 'Activar Sucursal';
            const estadoBotonColor = sucursalData.activo == 1 ? 'warning' : 'success';
            const estadoBotonIcono = sucursalData.activo == 1 ? 'ban' : 'check';
            
            let buttonsHtml = `
                <div class="btn-group-mobile-stack w-100">
                    <button type="button" class="btn btn-success" id="editarDesdeDetallesBtn">
                        <i class="fas fa-edit me-2"></i>Editar Sucursal
                    </button>
                    <form method="POST" class="d-inline" id="cambiarEstadoForm" style="margin: 0;">
                        <input type="hidden" name="accion" value="cambiar_estado">
                        <input type="hidden" name="id" value="${sucursalData.id}">
                        <input type="hidden" name="activo" value="${nuevoEstado}">
                        <button type="submit" class="btn btn-${estadoBotonColor}">
                            <i class="fas fa-${estadoBotonIcono} me-2"></i>${estadoBotonTexto}
                        </button>
                    </form>
            `;
            
            if (esAdmin && !esPrincipal) {
                buttonsHtml += `
                    <button type="button" class="btn btn-danger" id="eliminarDesdeDetallesBtn">
                        <i class="fas fa-trash-alt me-2"></i>Eliminar Sucursal
                    </button>
                `;
            }
            
            buttonsHtml += `
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cerrar
                    </button>
                </div>
            `;
            
            detallesFooter.innerHTML = buttonsHtml;
            
            const modal = new bootstrap.Modal(document.getElementById('detallesModal'));
            modal.show();
            
            // Evento editar
            document.getElementById('editarDesdeDetallesBtn').addEventListener('click', function() {
                modal.hide();
                const modalBody = document.getElementById('modalBodyContent');
                modalBody.innerHTML = generarFormularioSucursal('editar', sucursalData);
                const editModal = new bootstrap.Modal(document.getElementById('sucursalModal'));
                editModal.show();
                inicializarEventosFormulario();
            });
            
            // Evento eliminar
            const eliminarBtn = document.getElementById('eliminarDesdeDetallesBtn');
            if (eliminarBtn) {
                eliminarBtn.addEventListener('click', function() {
                    modal.hide();
                    mostrarConfirmacionEliminar(sucursalData.id, sucursalData.nombre);
                });
            }
            
            // Evento cambiar estado
            document.getElementById('cambiarEstadoForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const esActivo = sucursalData.activo == 1;
                Swal.fire({
                    title: esActivo ? '¿Desactivar sucursal?' : '¿Activar sucursal?',
                    text: esActivo ? 'La sucursal quedará inactiva y no podrá operar.' : 'La sucursal quedará activa y disponible.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: esActivo ? '#dc3545' : '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: esActivo ? 'Sí, desactivar' : 'Sí, activar',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        modal.hide();
                        this.submit();
                    }
                });
            });
        }
        
        // ============================================
        // FUNCIÓN PARA MOSTRAR CONFIRMACIÓN DE ELIMINACIÓN
        // ============================================
        function mostrarConfirmacionEliminar(sucursalId, sucursalNombre) {
            document.getElementById('sucursalNombreEliminar').textContent = sucursalNombre;
            document.getElementById('sucursalIdEliminar').value = sucursalId;
            const modal = new bootstrap.Modal(document.getElementById('confirmarEliminarModal'));
            modal.show();
        }
        
        // ============================================
        // INICIALIZAR EVENTOS DEL FORMULARIO
        // ============================================
        function inicializarEventosFormulario() {
            const responsableSelect = document.getElementById('responsableSelect');
            const responsableManual = document.getElementById('responsableManual');
            const modoManualCheckbox = document.getElementById('modoManualCheckbox');
            const modoManualHidden = document.getElementById('modoManualHidden');
            const userInfoCard = document.getElementById('userInfoCard');
            const userInfoText = document.getElementById('userInfoText');
            const clearResponsableBtn = document.getElementById('clearResponsable');
            
            if (modoManualCheckbox) {
                modoManualCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        if (responsableSelect) responsableSelect.classList.add('d-none');
                        if (responsableManual) responsableManual.classList.remove('d-none');
                        if (userInfoCard) userInfoCard.classList.add('d-none');
                        if (modoManualHidden) modoManualHidden.value = '1';
                    } else {
                        if (responsableSelect) responsableSelect.classList.remove('d-none');
                        if (responsableManual) responsableManual.classList.add('d-none');
                        if (responsableManual) responsableManual.value = '';
                        if (modoManualHidden) modoManualHidden.value = '0';
                    }
                });
            }
            
            if (responsableSelect) {
                responsableSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (this.value && selectedOption.dataset.userId && userInfoCard && userInfoText) {
                        userInfoText.textContent = `ID: ${selectedOption.dataset.userId} | Email: ${selectedOption.dataset.userEmail} | Rol: ${selectedOption.dataset.userRol}`;
                        userInfoCard.classList.remove('d-none');
                    } else if (userInfoCard) {
                        userInfoCard.classList.add('d-none');
                    }
                });
                // Trigger change event to show user info if already selected
                if (responsableSelect.value) {
                    const event = new Event('change');
                    responsableSelect.dispatchEvent(event);
                }
            }
            
            if (clearResponsableBtn && responsableSelect && userInfoCard) {
                clearResponsableBtn.addEventListener('click', function() {
                    responsableSelect.value = '';
                    userInfoCard.classList.add('d-none');
                });
            }
        }

        // ============================================
        // DOCUMENT READY
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
            }

            if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
            if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', toggleSidebar);

            document.querySelectorAll('#sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) toggleSidebar();
                });
            });

            // Search
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    document.querySelectorAll('#sucursalesTable tbody tr').forEach(row => {
                        row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
                    });
                    document.querySelectorAll('#mobileSucursales .col-12').forEach(card => {
                        card.style.display = card.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
                    });
                });
            }

            // Nueva Sucursal
            const newSucursalBtn = document.getElementById('newSucursalBtn');
            if (newSucursalBtn) {
                newSucursalBtn.addEventListener('click', function() {
                    const modalBody = document.getElementById('modalBodyContent');
                    modalBody.innerHTML = generarFormularioSucursal('crear');
                    const modal = new bootstrap.Modal(document.getElementById('sucursalModal'));
                    modal.show();
                    inicializarEventosFormulario();
                });
            }

            // Filas clickeables (escritorio)
            document.querySelectorAll('.clickable-row').forEach(row => {
                row.addEventListener('click', function(e) {
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) return;
                    const sucursalData = {
                        id: this.getAttribute('data-id'),
                        nombre: this.getAttribute('data-nombre'),
                        direccion: this.getAttribute('data-direccion'),
                        telefono: this.getAttribute('data-telefono'),
                        email: this.getAttribute('data-email'),
                        responsable: this.getAttribute('data-responsable'),
                        activo: this.getAttribute('data-activo'),
                        fecha_creacion: this.getAttribute('data-fecha_creacion'),
                        total_ventas: this.getAttribute('data-total_ventas'),
                        monto_total: this.getAttribute('data-monto_total'),
                        total_usuarios: this.getAttribute('data-total_usuarios')
                    };
                    mostrarDetallesSucursal(sucursalData);
                });
            });
            
            // Tarjetas clickeables (móvil)
            document.querySelectorAll('.clickable-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    if (e.target.tagName === 'BUTTON' || e.target.closest('button')) return;
                    const sucursalData = {
                        id: this.getAttribute('data-id'),
                        nombre: this.getAttribute('data-nombre'),
                        direccion: this.getAttribute('data-direccion'),
                        telefono: this.getAttribute('data-telefono'),
                        email: this.getAttribute('data-email'),
                        responsable: this.getAttribute('data-responsable'),
                        activo: this.getAttribute('data-activo'),
                        fecha_creacion: this.getAttribute('data-fecha_creacion'),
                        total_ventas: this.getAttribute('data-total_ventas'),
                        monto_total: this.getAttribute('data-monto_total'),
                        total_usuarios: this.getAttribute('data-total_usuarios')
                    };
                    mostrarDetallesSucursal(sucursalData);
                });
            });

            // Filtros
            function toggleResponsableColumn() {
                const showResponsable = document.getElementById('showResponsable');
                const responsableHeader = document.getElementById('responsableHeader');
                const responsableCells = document.querySelectorAll('.responsable-cell');
                const responsableMobile = document.querySelectorAll('.responsable-mobile');
                
                if (showResponsable) {
                    const isChecked = showResponsable.checked;
                    if (responsableHeader) responsableHeader.style.display = isChecked ? '' : 'none';
                    responsableCells.forEach(cell => cell.style.display = isChecked ? '' : 'none');
                    responsableMobile.forEach(div => div.style.display = isChecked ? '' : 'none');
                }
            }

            function aplicarFiltros() {
                const estadoFilter = document.getElementById('estadoFilter');
                if (!estadoFilter) return;
                const estadoValue = estadoFilter.value;
                
                const rows = document.querySelectorAll('#sucursalesTable tbody tr');
                rows.forEach(row => {
                    const statusBadge = row.querySelector('.status-badge');
                    const montoVentas = row.querySelector('.monto-ventas');
                    if (!statusBadge) return;
                    const isActive = statusBadge.textContent.trim() === 'Activa';
                    const hasSales = montoVentas !== null;
                    let show = true;
                    if (estadoValue === 'activo' && !isActive) show = false;
                    if (estadoValue === 'inactivo' && isActive) show = false;
                    if (estadoValue === 'con_ventas' && !hasSales) show = false;
                    if (estadoValue === 'sin_ventas' && hasSales) show = false;
                    row.style.display = show ? '' : 'none';
                });
                
                const cards = document.querySelectorAll('#mobileSucursales .col-12');
                cards.forEach(card => {
                    const statusBadge = card.querySelector('.status-badge');
                    const montoVentas = card.querySelector('.monto-ventas');
                    if (!statusBadge) return;
                    const isActive = statusBadge.textContent.trim() === 'Activa';
                    const hasSales = montoVentas !== null;
                    let show = true;
                    if (estadoValue === 'activo' && !isActive) show = false;
                    if (estadoValue === 'inactivo' && isActive) show = false;
                    if (estadoValue === 'con_ventas' && !hasSales) show = false;
                    if (estadoValue === 'sin_ventas' && hasSales) show = false;
                    card.style.display = show ? '' : 'none';
                });
                
                toggleResponsableColumn();
            }
            
            const estadoFilter = document.getElementById('estadoFilter');
            const showResponsable = document.getElementById('showResponsable');
            if (estadoFilter) estadoFilter.addEventListener('change', aplicarFiltros);
            if (showResponsable) showResponsable.addEventListener('change', aplicarFiltros);
            aplicarFiltros();
        });
    </script>
</body>

</html>
