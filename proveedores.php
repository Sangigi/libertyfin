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

// Valores por defecto
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

    // Guardar colores en sesión para uso consistente
    $_SESSION['color_primario'] = $color_primario;
    $_SESSION['color_secundario'] = $color_secundario;

    // Verificar estructura de la tabla proveedores
    $sql_estructura = "SHOW COLUMNS FROM proveedores";
    $result_estructura = $conn->query($sql_estructura);
    $campos_proveedores = [];
    while ($row = $result_estructura->fetch(PDO::FETCH_ASSOC)) {
        $campos_proveedores[] = $row['Field'];
    }

    // Construir consulta según la estructura de la tabla
    $campos_select = "p.*";
    if (in_array('fecha_creacion', $campos_proveedores)) {
        $campos_select = "p.id, p.nombre, p.contacto, p.telefono, p.email, p.direccion, p.rfc, p.activo, p.fecha_creacion, p.fecha_actualizacion";
    }

    // Obtener el total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM proveedores p";
    $result_count = $conn->query($sql_count);
    $total_registros = $result_count->fetch(PDO::FETCH_ASSOC)['total'];
    $result_count = null;

    // Calcular total de páginas
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // Obtener proveedores con LIMIT para paginación
   $sql_proveedores = "
    SELECT 
        $campos_select
    FROM proveedores p 
    ORDER BY p.fecha_actualizacion DESC, p.id DESC
    LIMIT ? OFFSET ?
";
    $stmt_proveedores = $conn->prepare($sql_proveedores);
    $stmt_proveedores->execute([$registros_por_pagina, $offset]);
    $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);
    
    // Sanitizar valores
    foreach ($proveedores as &$proveedor) {
        $proveedor['contacto'] = $proveedor['contacto'] ?? '';
        $proveedor['telefono'] = $proveedor['telefono'] ?? '';
        $proveedor['email'] = $proveedor['email'] ?? '';
        $proveedor['direccion'] = $proveedor['direccion'] ?? '';
        $proveedor['rfc'] = $proveedor['rfc'] ?? '';
        $proveedor['fecha_creacion'] = $proveedor['fecha_creacion'] ?? $proveedor['fecha_actualizacion'] ?? date('Y-m-d H:i:s');
    }
    unset($proveedor);
    $stmt_proveedores = null;

    // Obtener estadísticas de proveedores
    $sql_stats = "
        SELECT 
            COUNT(*) as total_proveedores,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as proveedores_activos,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as proveedores_inactivos
        FROM proveedores
    ";
    $result_stats = $conn->query($sql_stats);
    $stats_proveedores = $result_stats->fetch(PDO::FETCH_ASSOC);

    // Obtener conteo de productos por proveedor
    $sql_productos_proveedor = "
        SELECT 
            proveedor_id,
            COUNT(*) as total_productos
        FROM productos 
        WHERE proveedor_id IS NOT NULL 
        GROUP BY proveedor_id
    ";
    $result_productos_proveedor = $conn->query($sql_productos_proveedor);
    $productos_por_proveedor = [];
    while ($row = $result_productos_proveedor->fetch(PDO::FETCH_ASSOC)) {
        $productos_por_proveedor[$row['proveedor_id']] = $row['total_productos'];
    }

    $total_proveedores = $stats_proveedores['total_proveedores'] ?? 0;
    $proveedores_activos = $stats_proveedores['proveedores_activos'] ?? 0;
    $proveedores_inactivos = $stats_proveedores['proveedores_inactivos'] ?? 0;

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear':
                crearProveedor($conn);
                break;
            case 'editar':
                editarProveedor($conn);
                break;
            case 'cambiar_estado':
                cambiarEstadoProveedor($conn);
                break;
            case 'eliminar':
                eliminarProveedor($conn);
                break;
        }
    }
}

// ==============================================
// FUNCIÓN PARA OBTENER LISTA DE PROVEEDORES POR CATEGORÍA (AJAX)
// ==============================================

function obtenerListaProveedores($conn, $tipo) {
    $proveedores = [];
    
    switch ($tipo) {
        case 'todos':
            $sql = "SELECT id, nombre, contacto, telefono, email, activo, fecha_creacion,
                           (SELECT COUNT(*) FROM productos WHERE productos.proveedor_id = proveedores.id) as total_productos
                    FROM proveedores 
                    ORDER BY nombre ASC";
            break;
            
        case 'activos':
            $sql = "SELECT id, nombre, contacto, telefono, email, activo, fecha_creacion,
                           (SELECT COUNT(*) FROM productos WHERE productos.proveedor_id = proveedores.id) as total_productos
                    FROM proveedores 
                    WHERE activo = 1 
                    ORDER BY nombre ASC";
            break;
            
        case 'con_productos':
            $sql = "SELECT p.id, p.nombre, p.contacto, p.telefono, p.email, p.activo, p.fecha_creacion,
                           COUNT(prod.id) as total_productos
                    FROM proveedores p
                    INNER JOIN productos prod ON prod.proveedor_id = p.id
                    GROUP BY p.id, p.nombre, p.contacto, p.telefono, p.email, p.activo, p.fecha_creacion
                    ORDER BY p.nombre ASC";
            break;
            
        default:
            return [];
    }
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $proveedores[] = [
                'id' => $row['id'],
                'nombre' => $row['nombre'],
                'contacto' => $row['contacto'] ?? '',
                'telefono' => $row['telefono'] ?? '',
                'email' => $row['email'] ?? '',
                'activo' => (bool)$row['activo'],
                'fecha_creacion' => $row['fecha_creacion'],
                'total_productos' => isset($row['total_productos']) ? (int)$row['total_productos'] : 0
            ];
        }
    }
    
    return $proveedores;
}

// Procesar solicitud AJAX para obtener lista de proveedores
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'obtener_lista_proveedores') {
    header('Content-Type: application/json');
    
    try {
        // Conectar a la base de datos de la empresa
        $conn_ajax = getEmpresaDBConnection($_SESSION['empresa_db']);
        
        $tipo = $_POST['tipo'] ?? 'todos';
        $proveedores = obtenerListaProveedores($conn_ajax, $tipo);
        
        echo json_encode([
            'success' => true,
            'proveedores' => $proveedores,
            'total' => count($proveedores)
        ]);
        
        $conn_ajax = null;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

function crearProveedor($conn)
{
    $nombre = trim($_POST['nombre'] ?? '');
    $contacto = trim($_POST['contacto'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $rfc = trim($_POST['rfc'] ?? '');
    $rfc = !empty($rfc) ? $rfc : null;

    try {
        $sql_verificar_nombre = "SELECT id FROM proveedores WHERE nombre = ?";
        $stmt_verificar_nombre = $conn->prepare($sql_verificar_nombre);
        $stmt_verificar_nombre->execute([$nombre]);
        $result_verificar_nombre = $stmt_verificar_nombre->fetchAll(PDO::FETCH_ASSOC);

        if (count($result_verificar_nombre) > 0) {
            throw new Exception("Ya existe un proveedor con ese nombre");
        }
        $stmt_verificar_nombre = null;

        if ($rfc !== null) {
            $sql_verificar_rfc = "SELECT id FROM proveedores WHERE rfc = ? AND rfc IS NOT NULL";
            $stmt_verificar_rfc = $conn->prepare($sql_verificar_rfc);
            $stmt_verificar_rfc->execute([$rfc]);
            $result_verificar_rfc = $stmt_verificar_rfc->fetchAll(PDO::FETCH_ASSOC);

            if (count($result_verificar_rfc) > 0) {
                throw new Exception("Ya existe un proveedor con ese RFC");
            }
            $stmt_verificar_rfc = null;
        }

        $sql = "INSERT INTO proveedores (nombre, contacto, telefono, email, direccion, rfc, fecha_creacion) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $contacto, $telefono, $email, $direccion, $rfc]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['mensaje'] = "Proveedor creado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al crear proveedor");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: proveedores.php');
    exit();
}

function editarProveedor($conn)
{
    $id = intval($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $contacto = trim($_POST['contacto'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $rfc = trim($_POST['rfc'] ?? '');
    $rfc = !empty($rfc) ? $rfc : null;

    try {
        $sql_verificar_nombre = "SELECT id FROM proveedores WHERE nombre = ? AND id != ?";
        $stmt_verificar_nombre = $conn->prepare($sql_verificar_nombre);
        $stmt_verificar_nombre->execute([$nombre, $id]);
        $result_verificar_nombre = $stmt_verificar_nombre->fetchAll(PDO::FETCH_ASSOC);

        if (count($result_verificar_nombre) > 0) {
            throw new Exception("Ya existe otro proveedor con ese nombre");
        }
        $stmt_verificar_nombre = null;

        if ($rfc !== null) {
            $sql_verificar_rfc = "SELECT id FROM proveedores WHERE rfc = ? AND rfc IS NOT NULL AND id != ?";
            $stmt_verificar_rfc = $conn->prepare($sql_verificar_rfc);
            $stmt_verificar_rfc->execute([$rfc, $id]);
            $result_verificar_rfc = $stmt_verificar_rfc->fetchAll(PDO::FETCH_ASSOC);

            if (count($result_verificar_rfc) > 0) {
                throw new Exception("Ya existe otro proveedor con ese RFC");
            }
            $stmt_verificar_rfc = null;
        }

        $sql = "UPDATE proveedores SET 
                nombre = ?, contacto = ?, telefono = ?, email = ?, direccion = ?, rfc = ?, fecha_actualizacion = NOW() 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $contacto, $telefono, $email, $direccion, $rfc, $id]);

        if ($stmt->rowCount() >= 0) {
            $_SESSION['mensaje'] = "Proveedor actualizado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al actualizar proveedor");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: proveedores.php');
    exit();
}

function cambiarEstadoProveedor($conn)
{
    $id = intval($_POST['id'] ?? 0);
    $activo = intval($_POST['activo'] ?? 0);

    try {
        $sql = "UPDATE proveedores SET activo = ?, fecha_actualizacion = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$activo, $id]);

        if ($stmt->rowCount() >= 0) {
            $estado = $activo ? "activado" : "desactivado";
            $_SESSION['mensaje'] = "Proveedor $estado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al cambiar estado");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: proveedores.php');
    exit();
}

// ==============================================
// FUNCIÓN ELIMINAR PROVEEDOR - SOLO ADMINISTRADORES
// ==============================================
function eliminarProveedor($conn)
{
    // 🔐 VERIFICAR QUE SEA ADMINISTRADOR
    if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'admin') {
        $_SESSION['mensaje'] = "❌ Acceso denegado. Solo los administradores pueden eliminar proveedores.";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: proveedores.php');
        exit();
    }

    $id = intval($_POST['id'] ?? 0);

    try {
        // Obtener nombre del proveedor
        $sql_info = "SELECT nombre FROM proveedores WHERE id = ?";
        $stmt_info = $conn->prepare($sql_info);
        $stmt_info->execute([$id]);
        $proveedor = $stmt_info->fetch(PDO::FETCH_ASSOC);
        $nombre_proveedor = $proveedor['nombre'] ?? 'Desconocido';
        $stmt_info = null;

        // Contar compras asociadas
        $sql_compras = "SELECT COUNT(*) as total FROM compras WHERE proveedor_id = ?";
        $stmt_compras = $conn->prepare($sql_compras);
        $stmt_compras->execute([$id]);
        $total_compras = $stmt_compras->fetch(PDO::FETCH_ASSOC)['total'];
        $stmt_compras = null;

        // Contar productos asociados
        $sql_productos = "SELECT COUNT(*) as total FROM productos WHERE proveedor_id = ?";
        $stmt_productos = $conn->prepare($sql_productos);
        $stmt_productos->execute([$id]);
        $total_productos = $stmt_productos->fetch(PDO::FETCH_ASSOC)['total'];
        $stmt_productos = null;

        // ELIMINAR PROVEEDOR (ON DELETE CASCADE eliminará compras y productos automáticamente)
        $sql = "DELETE FROM proveedores WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            // Construir mensaje detallado
            $mensaje = "✅ Proveedor <strong>" . htmlspecialchars($nombre_proveedor) . "</strong> eliminado correctamente.";
            if ($total_compras > 0) {
                $mensaje .= "<br>📦 Se eliminaron <strong>{$total_compras}</strong> compra(s) asociada(s).";
            }
            if ($total_productos > 0) {
                $mensaje .= "<br>📦 Se eliminaron <strong>{$total_productos}</strong> producto(s) asociado(s).";
            }
            
            $_SESSION['mensaje'] = $mensaje;
            $_SESSION['tipo_mensaje'] = "warning";
        } else {
            throw new Exception("Error al eliminar proveedor");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = "❌ Error: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: proveedores.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Proveedores - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
     <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars($color_primario); ?>;
            --secondary-color: <?php echo htmlspecialchars($color_secundario); ?>;
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
            margin-bottom: 1rem;
        }

        .card:hover {
            transform: translateY(-2px);
        }

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

        .proveedor-avatar {
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

        .contacto-info {
            font-size: 0.875rem;
            color: #6c757d;
        }

        .productos-badge {
            background: #e8f4fd;
            color: #2c3e50;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }

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
        
        .pagination .page-item.disabled .page-link {
            color: #6c757d;
            background-color: #f8f9fa;
            border-color: #dee2e6;
            opacity: 0.6;
        }

        /* Fila clickeable */
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .clickable-row:hover {
            background-color: rgba(0, 0, 0, 0.05) !important;
        }

        /* Tarjeta clickeable para móvil */
        .clickable-card {
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .clickable-card:active {
            transform: scale(0.98);
        }

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

        .proveedor-list-item {
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }
        .proveedor-list-item:hover {
            background-color: #f8f9fa;
            border-left-color: var(--primary-color);
        }

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
                padding: 1rem !important;
            }

            body.sidebar-open {
                overflow: hidden;
            }

            .stat-card .card-body {
                padding: 1rem;
            }

            .stats-number {
                font-size: 1.5rem;
            }

            .table-responsive {
                font-size: 0.875rem;
            }

            .mobile-proveedor-card {
                border-left: 4px solid var(--primary-color);
                margin-bottom: 1rem;
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

        @media (min-width: 768px) {
            .sidebar {
                transform: translateX(0) !important;
            }
        }

        @media (max-width: 575.98px) {
            .col-md-2 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .stats-number {
                font-size: 1.3rem;
            }

            .pagination .page-link {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
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

        .rfc-badge {
            background: #f8f9fa;
            color: #495057;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-family: monospace;
        }

        .text-primary {
            color: var(--primary-color) !important;
        }

        .text-success {
            color: var(--secondary-color) !important;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="Dashboard">
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
                    <ul class="dropdown-menu">
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
                        <li class="nav-item">
                            <a class="nav-link" href="Inicio">
                                <i class="fas fa-tachometer-alt"></i> Inicio
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Usuarios">
                                    <i class="fas fa-user-cog"></i> Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="Caja">
                                <i class="fas fa-cash-register"></i> Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Productos">
                                <i class="fas fa-boxes"></i> Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Clientes">
                                <i class="fas fa-users"></i> Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Ventas">
                                <i class="fas fa-receipt"></i> Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="CortesCaja">
                                <i class="fas fa-cash-register"></i> Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="Proveedores">
                                <i class="fas fa-truck"></i> Proveedores
                            </a>
                        </li>
                        <?php if ($empresa_plan !== 'basico' && $_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Sucursales">
                                    <i class="fas fa-store"></i> Sucursales
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles > 0): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Facturacion/inicio.php">
                                    <i class="fas fa-file-invoice-dollar"></i> Facturación
                                    <?php if ($timbres_disponibles > 0): ?>
                                        <span class="badge bg-success ms-2" style="font-size: 0.65rem;">
                                            <?php echo $timbres_disponibles; ?> timbres
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning ms-2" style="font-size: 0.65rem;">Sin timbres</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="Reportes">
                                <i class="fas fa-chart-bar"></i> Reportes
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i> Comisiones
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Configuracion">
                                    <i class="fas fa-cogs"></i> Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-column flex-md-row">
                    <h2 class="mb-3 mb-md-0">
                        <i class="fas fa-truck me-2"></i>
                        Gestión de Proveedores
                    </h2>
                    <div class="btn-group-mobile">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#proveedorModal" id="newProveedorBtn">
                            <i class="fas fa-plus me-2"></i>Nuevo Proveedor
                        </button>
                    </div>
                </div>

                <?php if (isset($_SESSION['mensaje'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show">
                        <?php echo $_SESSION['mensaje']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
                <?php endif; ?>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-tipo="todos">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Proveedores</div>
                                        <div class="metric-value text-primary"><?php echo $total_proveedores; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-truck fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-tipo="activos">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Proveedores Activos</div>
                                        <div class="metric-value text-success"><?php echo $proveedores_activos; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-tipo="con_productos">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Con Productos</div>
                                        <div class="metric-value text-info"><?php echo count($productos_por_proveedor); ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-boxes fa-2x text-info opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra de Búsqueda y Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <div class="search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="text" class="form-control" placeholder="Buscar proveedores..." id="searchInput">
                                </div>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <select class="form-select" id="estadoFilter">
                                    <option value="">Todos los estados</option>
                                    <option value="activo">Activos</option>
                                    <option value="inactivo">Inactivos</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="showRFC">
                                    <label class="form-check-label" for="showRFC">Mostrar RFC</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vista de Tabla (Desktop) - SIN columna de acciones -->
                <div class="d-none d-lg-block">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Lista de Proveedores <small class="text-muted ms-2"><i class="fas fa-hand-pointer"></i> Haz clic en cualquier proveedor para ver/editar</small></h5>
                            <div class="d-flex align-items-center">
                                <small class="result-count me-3">
                                    Mostrando <?php echo count($proveedores); ?> de <?php echo $total_registros; ?> proveedores
                                </small>
                                <?php if ($total_paginas > 1): ?>
                                    <span class="badge bg-secondary">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="proveedoresTable">
                                    <thead>
                                        <tr>
                                            <th>Proveedor</th>
                                            <th>Contacto</th>
                                            <th>Teléfono</th>
                                            <th>Email</th>
                                            <th>Productos</th>
                                            <th>Estado</th>
                                            <th>Registro</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($proveedores)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    <i class="fas fa-truck fa-3x mb-3"></i>
                                                    <p>No se encontraron proveedores</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($proveedores as $proveedor):
                                                $total_productos = $productos_por_proveedor[$proveedor['id']] ?? 0;
                                            ?>
                                                <tr class="clickable-row" 
                                                    data-id="<?php echo $proveedor['id']; ?>"
                                                    data-nombre="<?php echo htmlspecialchars($proveedor['nombre']); ?>"
                                                    data-contacto="<?php echo htmlspecialchars($proveedor['contacto']); ?>"
                                                    data-telefono="<?php echo htmlspecialchars($proveedor['telefono']); ?>"
                                                    data-email="<?php echo htmlspecialchars($proveedor['email']); ?>"
                                                    data-direccion="<?php echo htmlspecialchars($proveedor['direccion']); ?>"
                                                    data-rfc="<?php echo htmlspecialchars($proveedor['rfc']); ?>"
                                                    data-activo="<?php echo $proveedor['activo']; ?>"
                                                    data-fecha_creacion="<?php echo $proveedor['fecha_creacion']; ?>"
                                                    data-fecha_actualizacion="<?php echo $proveedor['fecha_actualizacion'] ?? ''; ?>"
                                                    data-total_productos="<?php echo $total_productos; ?>">
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="proveedor-avatar me-3">
                                                                <?php echo strtoupper(substr($proveedor['nombre'], 0, 2)); ?>
                                                            </div>
                                                            <div>
                                                                <div class="fw-bold"><?php echo htmlspecialchars($proveedor['nombre']); ?></div>
                                                                <?php if (!empty($proveedor['rfc'])): ?>
                                                                    <small class="rfc-badge"><?php echo htmlspecialchars($proveedor['rfc']); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($proveedor['contacto'])): ?>
                                                            <div class="contacto-info"><?php echo htmlspecialchars($proveedor['contacto']); ?></div>
                                                        <?php else: ?>
                                                            <span class="text-muted">No especificado</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($proveedor['telefono'])): ?>
                                                            <div class="contacto-info">
                                                                <i class="fas fa-phone me-1"></i>
                                                                <?php echo htmlspecialchars($proveedor['telefono']); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($proveedor['email'])): ?>
                                                            <div class="contacto-info">
                                                                <i class="fas fa-envelope me-1"></i>
                                                                <?php echo htmlspecialchars($proveedor['email']); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($total_productos > 0): ?>
                                                            <span class="productos-badge">
                                                                <i class="fas fa-box me-1"></i>
                                                                <?php echo $total_productos; ?> productos
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Sin productos</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $proveedor['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                            <?php echo $proveedor['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($proveedor['fecha_creacion'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_paginas > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Mostrando <?php echo count($proveedores); ?> de <?php echo $total_registros; ?> proveedores
                                </div>
                                <nav>
                                    <ul class="pagination mb-0">
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>">
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
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Vista de Tarjetas (Móvil) - SIN botones de acción -->
                <div class="d-lg-none">
                    <div class="row" id="mobileProveedores">
                        <?php if (empty($proveedores)): ?>
                            <div class="col-12">
                                <div class="card text-center py-5">
                                    <i class="fas fa-truck fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No se encontraron proveedores</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($proveedores as $proveedor):
                                $total_productos = $productos_por_proveedor[$proveedor['id']] ?? 0;
                            ?>
                                <div class="col-12 mb-3">
                                    <div class="card mobile-proveedor-card clickable-card"
                                        data-id="<?php echo $proveedor['id']; ?>"
                                        data-nombre="<?php echo htmlspecialchars($proveedor['nombre']); ?>"
                                        data-contacto="<?php echo htmlspecialchars($proveedor['contacto']); ?>"
                                        data-telefono="<?php echo htmlspecialchars($proveedor['telefono']); ?>"
                                        data-email="<?php echo htmlspecialchars($proveedor['email']); ?>"
                                        data-direccion="<?php echo htmlspecialchars($proveedor['direccion']); ?>"
                                        data-rfc="<?php echo htmlspecialchars($proveedor['rfc']); ?>"
                                        data-activo="<?php echo $proveedor['activo']; ?>"
                                        data-fecha_creacion="<?php echo $proveedor['fecha_creacion']; ?>"
                                        data-fecha_actualizacion="<?php echo $proveedor['fecha_actualizacion'] ?? ''; ?>"
                                        data-total_productos="<?php echo $total_productos; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="proveedor-avatar me-3">
                                                        <?php echo strtoupper(substr($proveedor['nombre'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($proveedor['nombre']); ?></h6>
                                                        <?php if (!empty($proveedor['rfc'])): ?>
                                                            <small class="rfc-badge"><?php echo htmlspecialchars($proveedor['rfc']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="status-badge <?php echo $proveedor['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $proveedor['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                </span>
                                            </div>

                                            <div class="contacto-info mb-2">
                                                <?php if (!empty($proveedor['contacto'])): ?>
                                                    <div><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($proveedor['contacto']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($proveedor['telefono'])): ?>
                                                    <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($proveedor['telefono']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($proveedor['email'])): ?>
                                                    <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($proveedor['email']); ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($proveedor['direccion'])): ?>
                                                <div class="info-text mb-2">
                                                    <i class="fas fa-map-marker-alt me-1"></i>
                                                    <?php echo htmlspecialchars($proveedor['direccion']); ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="d-flex justify-content-between align-items-center">
                                                <?php if ($total_productos > 0): ?>
                                                    <span class="productos-badge">
                                                        <i class="fas fa-box me-1"></i>
                                                        <?php echo $total_productos; ?> productos
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin productos</span>
                                                <?php endif; ?>
                                                <small class="text-muted"><?php echo date('d/m/Y', strtotime($proveedor['fecha_creacion'])); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination-info">
                            Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>
                                <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para Nuevo/Editar Proveedor -->
    <div class="modal fade" id="proveedorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nuevo Proveedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="proveedorForm">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="formAction" value="crear">
                        <input type="hidden" name="id" id="proveedorId">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombre del Proveedor *</label>
                                <input type="text" class="form-control" name="nombre" id="nombre" required
                                    placeholder="Nombre de la empresa proveedora">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">RFC</label>
                                <input type="text" class="form-control" name="rfc" id="rfc"
                                    placeholder="RFC del proveedor">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Persona de Contacto</label>
                                <input type="text" class="form-control" name="contacto" id="contacto"
                                    placeholder="Nombre del contacto">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="tel" class="form-control" name="telefono" id="telefono"
                                    placeholder="Número de teléfono">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="email"
                                placeholder="Correo electrónico">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Dirección</label>
                            <textarea class="form-control" name="direccion" id="direccion" rows="3"
                                placeholder="Dirección completa del proveedor"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Guardar Proveedor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Ver Detalles (CON BOTONES DE ACCIÓN) -->
    <div class="modal fade" id="detallesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalles del Proveedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detallesContent"></div>
                <div class="modal-footer" id="detallesFooter">
                    <!-- Botones de acción se agregarán dinámicamente -->
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para mostrar lista de proveedores por categoría -->
    <div class="modal fade" id="listaProveedoresModal" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header py-2 px-3">
                    <h6 class="modal-title" id="listaProveedoresModalTitle">
                        <i class="fas fa-truck me-2"></i>Lista de Proveedores
                    </h6>
                    <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="max-height: 60vh; overflow-y: auto;" id="listaProveedoresContent">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2 small text-muted">Cargando proveedores...</p>
                    </div>
                </div>
                <div class="modal-footer py-2 px-3">
                    <span class="small text-muted me-auto" id="proveedoresCount"></span>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // Variable para saber si el usuario es admin
    const esAdmin = <?php echo ($_SESSION['usuario_rol'] === 'admin') ? 'true' : 'false'; ?>;

    // Función para escapar HTML (seguridad)
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Función para mostrar la lista de proveedores en el modal
    function mostrarListaProveedores(tipo, titulo) {
        const modalTitle = document.getElementById('listaProveedoresModalTitle');
        const modalContent = document.getElementById('listaProveedoresContent');
        const proveedoresCount = document.getElementById('proveedoresCount');
        
        modalTitle.innerHTML = `<i class="fas fa-truck me-2"></i>${titulo}`;
        modalContent.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border spinner-border-sm text-primary" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <p class="mt-2 small text-muted">Cargando proveedores...</p>
            </div>
        `;
        proveedoresCount.textContent = '';
        
        const modal = new bootstrap.Modal(document.getElementById('listaProveedoresModal'));
        modal.show();
        
        const formData = new FormData();
        formData.append('accion', 'obtener_lista_proveedores');
        formData.append('tipo', tipo);
        
        fetch('proveedores.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div class="list-group list-group-flush">';
                
                if (data.proveedores.length === 0) {
                    html = `
                        <div class="text-center py-4">
                            <i class="fas fa-truck fa-2x text-muted mb-2 opacity-25"></i>
                            <p class="small text-muted mb-0">No hay proveedores en esta categoría</p>
                        </div>
                    `;
                    proveedoresCount.textContent = '0 proveedores';
                } else {
                    data.proveedores.forEach(proveedor => {
                        const estadoClass = proveedor.activo ? 'status-active' : 'status-inactive';
                        const estadoText = proveedor.activo ? 'Activo' : 'Inactivo';
                        
                        html += `
                            <div class="list-group-item list-group-item-action px-3 py-2 proveedor-list-item" style="border-left: 3px solid var(--primary-color);">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <strong class="small">${escapeHtml(proveedor.nombre)}</strong>
                                            <span class="status-badge ${estadoClass}" style="font-size: 0.65rem; padding: 2px 6px;">${estadoText}</span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 mt-1">
                                            ${proveedor.contacto ? `<small class="text-muted"><i class="fas fa-user fa-xs me-1"></i>${escapeHtml(proveedor.contacto)}</small>` : ''}
                                            ${proveedor.telefono ? `<small class="text-muted"><i class="fas fa-phone fa-xs me-1"></i>${escapeHtml(proveedor.telefono)}</small>` : ''}
                                            ${proveedor.email ? `<small class="text-muted"><i class="fas fa-envelope fa-xs me-1"></i>${escapeHtml(proveedor.email)}</small>` : ''}
                                        </div>
                                        <div class="d-flex gap-3 mt-1">
                                            <small class="text-muted"><i class="fas fa-calendar-alt fa-xs me-1"></i>${new Date(proveedor.fecha_creacion).toLocaleDateString('es-MX')}</small>
                                            ${proveedor.total_productos > 0 ? 
                                                `<small class="text-primary"><i class="fas fa-box fa-xs me-1"></i>${proveedor.total_productos} productos</small>` : 
                                                `<small class="text-muted"><i class="fas fa-box fa-xs me-1"></i>Sin productos</small>`
                                            }
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    proveedoresCount.textContent = `${data.proveedores.length} proveedor${data.proveedores.length !== 1 ? 'es' : ''}`;
                }
                
                modalContent.innerHTML = html;
            } else {
                modalContent.innerHTML = `
                    <div class="alert alert-danger alert-sm m-3 py-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <small>${escapeHtml(data.message)}</small>
                    </div>
                `;
                proveedoresCount.textContent = 'Error';
            }
        })
        .catch(error => {
            modalContent.innerHTML = `
                <div class="alert alert-danger alert-sm m-3 py-2">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <small>Error de conexión. Intente nuevamente.</small>
                </div>
            `;
            proveedoresCount.textContent = 'Error';
            console.error('Error:', error);
        });
    }

    // Función para mostrar el modal de detalles con botones de acción
    function mostrarDetallesProveedor(proveedorData) {
        const detallesContent = document.getElementById('detallesContent');
        const detallesFooter = document.getElementById('detallesFooter');
        
        const estadoText = proveedorData.activo == 1 ? 'Activo' : 'Inactivo';
        const estadoClass = proveedorData.activo == 1 ? 'status-active' : 'status-inactive';
        
        detallesContent.innerHTML = `
            <div class="row">
                <div class="col-md-6 mb-3">
                    <strong>Nombre:</strong>
                    <p><i class="fas fa-truck me-1"></i> ${escapeHtml(proveedorData.nombre)}</p>
                </div>
                <div class="col-md-6 mb-3">
                    <strong>RFC:</strong>
                    <p>${proveedorData.rfc ? escapeHtml(proveedorData.rfc) : 'No especificado'}</p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <strong>Contacto:</strong>
                    <p><i class="fas fa-user me-1"></i> ${proveedorData.contacto ? escapeHtml(proveedorData.contacto) : 'No especificado'}</p>
                </div>
                <div class="col-md-6 mb-3">
                    <strong>Teléfono:</strong>
                    <p><i class="fas fa-phone me-1"></i> ${proveedorData.telefono ? escapeHtml(proveedorData.telefono) : 'No especificado'}</p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <strong>Email:</strong>
                    <p><i class="fas fa-envelope me-1"></i> ${proveedorData.email ? escapeHtml(proveedorData.email) : 'No especificado'}</p>
                </div>
                <div class="col-md-6 mb-3">
                    <strong>Estado:</strong>
                    <p><span class="status-badge ${estadoClass}">${estadoText}</span></p>
                </div>
            </div>
            <div class="row">
                <div class="col-12 mb-3">
                    <strong>Dirección:</strong>
                    <p><i class="fas fa-map-marker-alt me-1"></i> ${proveedorData.direccion ? escapeHtml(proveedorData.direccion) : 'No especificada'}</p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <strong>Fecha de Registro:</strong>
                    <p><i class="fas fa-calendar-alt me-1"></i> ${new Date(proveedorData.fecha_creacion).toLocaleDateString('es-MX')}</p>
                </div>
                <div class="col-md-6 mb-3">
                    <strong>Productos Asociados:</strong>
                    <p><i class="fas fa-box me-1"></i> ${proveedorData.total_productos} producto${proveedorData.total_productos != 1 ? 's' : ''}</p>
                </div>
            </div>
        `;
        
        // Agregar botones de acción al footer
        const nuevoEstado = proveedorData.activo == 1 ? 0 : 1;
        const estadoBotonTexto = proveedorData.activo == 1 ? 'Desactivar Proveedor' : 'Activar Proveedor';
        const estadoBotonColor = proveedorData.activo == 1 ? 'warning' : 'success';
        const estadoBotonIcono = proveedorData.activo == 1 ? 'ban' : 'check';
        
        let html = `
            <div class="d-flex justify-content-between w-100 flex-wrap gap-2">
                <button type="button" class="btn btn-success" id="editarDesdeDetallesBtn">
                    <i class="fas fa-edit me-1"></i>Editar Proveedor
                </button>
                <form method="POST" class="d-inline" id="cambiarEstadoForm">
                    <input type="hidden" name="accion" value="cambiar_estado">
                    <input type="hidden" name="id" value="${proveedorData.id}">
                    <input type="hidden" name="activo" value="${nuevoEstado}">
                    <button type="submit" class="btn btn-${estadoBotonColor}">
                        <i class="fas fa-${estadoBotonIcono} me-1"></i>${estadoBotonTexto}
                    </button>
                </form>
        `;
        
        // Solo mostrar botón de eliminar si es administrador
        if (esAdmin) {
            html += `
                <button type="button" class="btn btn-danger" id="eliminarDesdeDetallesBtn">
                    <i class="fas fa-trash-alt me-1"></i>Eliminar Proveedor
                </button>
            `;
        }
        
        html += `
                <button type="button" class="btn btn-secondary ms-auto" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cerrar
                </button>
            </div>
        `;
        
        detallesFooter.innerHTML = html;
        
        // Mostrar el modal
        const modal = new bootstrap.Modal(document.getElementById('detallesModal'));
        modal.show();
        
        // Evento para editar desde detalles
        document.getElementById('editarDesdeDetallesBtn').addEventListener('click', function() {
            modal.hide();
            // Cargar datos en el modal de edición
            document.getElementById('modalTitle').textContent = 'Editar Proveedor';
            document.getElementById('formAction').value = 'editar';
            document.getElementById('proveedorId').value = proveedorData.id;
            document.getElementById('nombre').value = proveedorData.nombre;
            document.getElementById('rfc').value = proveedorData.rfc || '';
            document.getElementById('contacto').value = proveedorData.contacto || '';
            document.getElementById('telefono').value = proveedorData.telefono || '';
            document.getElementById('email').value = proveedorData.email || '';
            document.getElementById('direccion').value = proveedorData.direccion || '';
            
            const modalEditar = new bootstrap.Modal(document.getElementById('proveedorModal'));
            modalEditar.show();
        });
        
        // Evento para eliminar desde detalles (solo admin)
        const eliminarBtn = document.getElementById('eliminarDesdeDetallesBtn');
        if (eliminarBtn) {
            eliminarBtn.addEventListener('click', function() {
                modal.hide();
                deleteProveedor(proveedorData.id, proveedorData.nombre);
            });
        }
        
        // Evento para cambiar estado directamente desde el modal
        document.getElementById('cambiarEstadoForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const esActivo = proveedorData.activo == 1;
            const titulo = esActivo ? '¿Desactivar proveedor?' : '¿Activar proveedor?';
            const texto = esActivo ? 'El proveedor quedará inactivo y no aparecerá en las listas de selección.' : 'El proveedor quedará activo y disponible.';
            
            Swal.fire({
                title: titulo,
                text: texto,
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
    
    // Función de eliminación con SweetAlert2 (solo admin)
    function deleteProveedor(proveedorId, proveedorNombre) {
        Swal.fire({
            title: '⚠️ ¿Eliminar proveedor?',
            html: `¿Estás seguro de eliminar a <strong>${escapeHtml(proveedorNombre)}</strong>?<br><br>
                   <span class="text-danger">Esta acción eliminará también todos los productos y compras asociados.</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'proveedores.php';
                
                const inputAccion = document.createElement('input');
                inputAccion.type = 'hidden';
                inputAccion.name = 'accion';
                inputAccion.value = 'eliminar';
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'id';
                inputId.value = proveedorId;
                
                form.appendChild(inputAccion);
                form.appendChild(inputId);
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function toggleSidebar() {
            sidebar.classList.toggle('show');
            sidebarBackdrop.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', toggleSidebar);
        }

        const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth < 768) {
                    toggleSidebar();
                }
            });
        });

        // =============================================
        // EVENTOS PARA LOS CARDS DE ESTADÍSTICAS
        // =============================================
        
        const totalProveedoresCard = document.querySelector('.stat-card[data-tipo="todos"]');
        if (totalProveedoresCard) {
            totalProveedoresCard.addEventListener('click', function() {
                mostrarListaProveedores('todos', 'Todos los Proveedores');
            });
        }
        
        const proveedoresActivosCard = document.querySelector('.stat-card[data-tipo="activos"]');
        if (proveedoresActivosCard) {
            proveedoresActivosCard.addEventListener('click', function() {
                mostrarListaProveedores('activos', 'Proveedores Activos');
            });
        }
        
        const conProductosCard = document.querySelector('.stat-card[data-tipo="con_productos"]');
        if (conProductosCard) {
            conProductosCard.addEventListener('click', function() {
                mostrarListaProveedores('con_productos', 'Proveedores con Productos');
            });
        }

        // =============================================
        // EVENTOS PARA FILAS CLICKEABLES (ESCRITORIO)
        // =============================================
        document.querySelectorAll('.clickable-row').forEach(row => {
            row.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                    return;
                }
                
                const proveedorData = {
                    id: this.getAttribute('data-id'),
                    nombre: this.getAttribute('data-nombre'),
                    contacto: this.getAttribute('data-contacto'),
                    telefono: this.getAttribute('data-telefono'),
                    email: this.getAttribute('data-email'),
                    direccion: this.getAttribute('data-direccion'),
                    rfc: this.getAttribute('data-rfc'),
                    activo: this.getAttribute('data-activo'),
                    fecha_creacion: this.getAttribute('data-fecha_creacion'),
                    total_productos: this.getAttribute('data-total_productos')
                };
                
                mostrarDetallesProveedor(proveedorData);
            });
        });
        
        // =============================================
        // EVENTOS PARA TARJETAS CLICKEABLES (MÓVIL)
        // =============================================
        document.querySelectorAll('.clickable-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                    return;
                }
                
                const proveedorData = {
                    id: this.getAttribute('data-id'),
                    nombre: this.getAttribute('data-nombre'),
                    contacto: this.getAttribute('data-contacto'),
                    telefono: this.getAttribute('data-telefono'),
                    email: this.getAttribute('data-email'),
                    direccion: this.getAttribute('data-direccion'),
                    rfc: this.getAttribute('data-rfc'),
                    activo: this.getAttribute('data-activo'),
                    fecha_creacion: this.getAttribute('data-fecha_creacion'),
                    total_productos: this.getAttribute('data-total_productos')
                };
                
                mostrarDetallesProveedor(proveedorData);
            });
        });

        // Búsqueda en tiempo real
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('#proveedoresTable tbody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
                const cards = document.querySelectorAll('#mobileProveedores .col-12');
                cards.forEach(card => {
                    const text = card.textContent.toLowerCase();
                    card.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }

        // Botones de edición existentes (se mantienen por compatibilidad)
        document.querySelectorAll('.edit-proveedor').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const proveedorData = JSON.parse(this.getAttribute('data-proveedor'));
                document.getElementById('modalTitle').textContent = 'Editar Proveedor';
                document.getElementById('formAction').value = 'editar';
                document.getElementById('proveedorId').value = proveedorData.id;
                document.getElementById('nombre').value = proveedorData.nombre;
                document.getElementById('rfc').value = proveedorData.rfc || '';
                document.getElementById('contacto').value = proveedorData.contacto || '';
                document.getElementById('telefono').value = proveedorData.telefono || '';
                document.getElementById('email').value = proveedorData.email || '';
                document.getElementById('direccion').value = proveedorData.direccion || '';

                const modalEditar = new bootstrap.Modal(document.getElementById('proveedorModal'));
                modalEditar.show();
            });
        });

        // Resetear formulario al abrir modal para nuevo proveedor
        document.getElementById('newProveedorBtn').addEventListener('click', function() {
            document.getElementById('proveedorForm').reset();
            document.getElementById('modalTitle').textContent = 'Nuevo Proveedor';
            document.getElementById('formAction').value = 'crear';
            document.getElementById('proveedorId').value = '';
        });

        // Resetear cuando se abre el modal sin datos de edición
        document.getElementById('proveedorModal').addEventListener('show.bs.modal', function(e) {
            if (!document.getElementById('proveedorId').value) {
                document.getElementById('proveedorForm').reset();
                document.getElementById('modalTitle').textContent = 'Nuevo Proveedor';
                document.getElementById('formAction').value = 'crear';
                document.getElementById('proveedorId').value = '';
            }
        });

        // Función para aplicar filtros
        function aplicarFiltros() {
            const estadoFilter = document.getElementById('estadoFilter').value;
            const showRFC = document.getElementById('showRFC').checked;

            const rows = document.querySelectorAll('#proveedoresTable tbody tr');
            rows.forEach(row => {
                const isActive = row.querySelector('.status-badge').textContent.trim() === 'Activo';
                let show = true;
                if (estadoFilter === 'activo' && !isActive) show = false;
                if (estadoFilter === 'inactivo' && isActive) show = false;
                const rfcBadge = row.querySelector('.rfc-badge');
                if (rfcBadge) {
                    rfcBadge.style.display = showRFC ? 'inline-block' : 'none';
                }
                row.style.display = show ? '' : 'none';
            });

            const cards = document.querySelectorAll('#mobileProveedores .col-12');
            cards.forEach(card => {
                const isActive = card.querySelector('.status-badge').textContent.trim() === 'Activo';
                let show = true;
                if (estadoFilter === 'activo' && !isActive) show = false;
                if (estadoFilter === 'inactivo' && isActive) show = false;
                const rfcBadge = card.querySelector('.rfc-badge');
                if (rfcBadge) {
                    rfcBadge.style.display = showRFC ? 'inline-block' : 'none';
                }
                card.style.display = show ? '' : 'none';
            });
        }

        const estadoFilter = document.getElementById('estadoFilter');
        const showRFCCheckbox = document.getElementById('showRFC');
        if (estadoFilter) estadoFilter.addEventListener('change', aplicarFiltros);
        if (showRFCCheckbox) showRFCCheckbox.addEventListener('change', aplicarFiltros);
        
        // Botones de cambio de estado existentes (para cuando se usan los botones originales)
        document.querySelectorAll('.cambiar-estado-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
                const esActivo = btn.classList.contains('btn-outline-danger');
                const titulo = esActivo ? '¿Desactivar proveedor?' : '¿Activar proveedor?';
                const texto = esActivo ? 'El proveedor quedará inactivo y no aparecerá en las listas de selección.' : 'El proveedor quedará activo y disponible.';
                
                Swal.fire({
                    title: titulo,
                    text: texto,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: esActivo ? '#dc3545' : '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: esActivo ? 'Sí, desactivar' : 'Sí, activar',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });
        
        // Botones de eliminar existentes (solo admin)
        if (esAdmin) {
            document.querySelectorAll('.eliminar-proveedor').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const proveedorId = this.getAttribute('data-id');
                    const proveedorNombre = this.getAttribute('data-nombre');
                    deleteProveedor(proveedorId, proveedorNombre);
                });
            });
        }
    });
    </script>
</body>

</html>
