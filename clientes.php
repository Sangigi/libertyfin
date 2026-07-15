<?php
//clientes.php

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Login");
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

    // Obtener colores personalizados de la configuración
    $sql_colores = "SELECT color_primario, color_secundario FROM sistema_config LIMIT 1";
    $result_colores = $conn->query($sql_colores);
    if ($result_colores->rowCount() > 0) {
        $colores_config = $result_colores->fetch(PDO::FETCH_ASSOC);
        $_SESSION['color_primario'] = $colores_config['color_primario'] ?? '#27ae60';
        $_SESSION['color_secundario'] = $colores_config['color_secundario'] ?? '#2ecc71';
    } else {
        $_SESSION['color_primario'] = '#27ae60';
        $_SESSION['color_secundario'] = '#2ecc71';
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

    // Verificar estructura de la tabla clientes
    $sql_estructura = "SHOW COLUMNS FROM clientes";
    $result_estructura = $conn->query($sql_estructura);
    $campos_clientes = [];
    while ($row = $result_estructura->fetch(PDO::FETCH_ASSOC)) {
        $campos_clientes[] = $row['Field'];
    }

    // Construir consulta usando fecha_creacion
    $campos_select = "c.id, c.nombre, c.email, c.telefono, c.direccion, c.rfc, c.activo, c.fecha_creacion, c.fecha_actualizacion";

    // Obtener el total de registros para paginación
    $sql_count = "SELECT COUNT(*) as total FROM clientes c";
    $result_count = $conn->query($sql_count);
    $total_registros = $result_count->fetch(PDO::FETCH_ASSOC)['total'];
    $result_count = null;

    // Calcular total de páginas
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }

    // Obtener clientes con LIMIT para paginación
    $sql_clientes = "
        SELECT 
            $campos_select
        FROM clientes c 
        ORDER BY c.fecha_actualizacion DESC, c.fecha_creacion DESC, c.id DESC
        LIMIT ? OFFSET ?
    ";
    $stmt_clientes = $conn->prepare($sql_clientes);
    $stmt_clientes->execute([$registros_por_pagina, $offset]);
    $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
    
    // Asegurar valores por defecto
    foreach ($clientes as &$cliente) {
        $cliente['nombre'] = $cliente['nombre'] ?? '';
        $cliente['email'] = $cliente['email'] ?? '';
        $cliente['telefono'] = $cliente['telefono'] ?? '';
        $cliente['direccion'] = $cliente['direccion'] ?? '';
        $cliente['rfc'] = $cliente['rfc'] ?? '';
        $cliente['fecha_creacion'] = $cliente['fecha_creacion'] ?? $cliente['fecha_actualizacion'] ?? date('Y-m-d H:i:s');
    }
    unset($cliente);
    $stmt_clientes = null;

    // Obtener estadísticas de clientes
    $sql_stats = "
        SELECT 
            COUNT(*) as total_clientes,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as clientes_activos,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as clientes_inactivos
        FROM clientes
    ";
    $result_stats = $conn->query($sql_stats);
    $stats_clientes = $result_stats->fetch(PDO::FETCH_ASSOC);

    // Obtener estadísticas de ventas por cliente
    $sql_ventas_cliente = "
        SELECT 
            cliente_id,
            COUNT(*) as total_ventas,
            SUM(total) as monto_total
        FROM ventas 
        WHERE cliente_id IS NOT NULL 
        GROUP BY cliente_id
    ";
    $result_ventas_cliente = $conn->query($sql_ventas_cliente);
    $ventas_por_cliente = [];
    while ($row = $result_ventas_cliente->fetch(PDO::FETCH_ASSOC)) {
        $ventas_por_cliente[$row['cliente_id']] = $row;
    }

    $total_clientes = $stats_clientes['total_clientes'] ?? 0;
    $clientes_activos = $stats_clientes['clientes_activos'] ?? 0;
    $clientes_inactivos = $stats_clientes['clientes_inactivos'] ?? 0;
    
    // OBTENER LISTAS DE CLIENTES PARA LOS MODALES
    $sql_todos = "SELECT id, nombre, email, telefono, activo FROM clientes ORDER BY nombre";
    $result_todos = $conn->query($sql_todos);
    $todos_clientes = $result_todos->fetchAll(PDO::FETCH_ASSOC);
    
    $sql_activos = "SELECT id, nombre, email, telefono FROM clientes WHERE activo = 1 ORDER BY nombre";
    $result_activos_lista = $conn->query($sql_activos);
    $clientes_activos_lista = $result_activos_lista->fetchAll(PDO::FETCH_ASSOC);
    
    $sql_con_compras = "
        SELECT DISTINCT c.id, c.nombre, c.email, c.telefono, COUNT(v.id) as total_compras
        FROM clientes c
        INNER JOIN ventas v ON c.id = v.cliente_id
        GROUP BY c.id, c.nombre, c.email, c.telefono
        ORDER BY c.nombre
    ";
    $result_con_compras = $conn->query($sql_con_compras);
    $clientes_con_compras = $result_con_compras->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear':
                crearCliente($conn);
                break;
            case 'editar':
                editarCliente($conn);
                break;
            case 'cambiar_estado':
                cambiarEstadoCliente($conn);
                break;
            case 'eliminar':
                eliminarCliente($conn);
                break;
        }
    }
}

function crearCliente($conn)
{
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $rfc = trim($_POST['rfc'] ?? '');

    try {
        if (empty($nombre)) {
            throw new Exception("El nombre del cliente es obligatorio");
        }

        $condiciones = [];
        $params = [];
        
        if (!empty($email)) {
            $condiciones[] = "email = ?";
            $params[] = $email;
        }
        
        if (!empty($rfc)) {
            $condiciones[] = "rfc = ?";
            $params[] = $rfc;
        }
        
        if (!empty($condiciones)) {
            $sql_verificar = "SELECT id, email, rfc FROM clientes WHERE " . implode(" OR ", $condiciones);
            $stmt_verificar = $conn->prepare($sql_verificar);
            $stmt_verificar->execute($params);
            $result_verificar = $stmt_verificar->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($result_verificar) > 0) {
                $cliente_existente = $result_verificar[0];
                $mensaje = "Ya existe un cliente con ";
                $conflictos = [];
                
                if (!empty($email) && $cliente_existente['email'] === $email) {
                    $conflictos[] = "el email '$email'";
                }
                if (!empty($rfc) && $cliente_existente['rfc'] === $rfc) {
                    $conflictos[] = "el RFC '$rfc'";
                }
                
                throw new Exception($mensaje . implode(" y ", $conflictos));
            }
            $stmt_verificar = null;
        }

        $sql = "INSERT INTO clientes (nombre, email, telefono, direccion, rfc, fecha_creacion, fecha_actualizacion) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $email, $telefono, $direccion, $rfc]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['mensaje'] = "Cliente creado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al crear cliente");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: Clientes');
    exit();
}

function editarCliente($conn)
{
    $id = intval($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $rfc = trim($_POST['rfc'] ?? '');

    try {
        if ($id <= 0) {
            throw new Exception("ID de cliente inválido");
        }
        
        if (empty($nombre)) {
            throw new Exception("El nombre del cliente es obligatorio");
        }

        $sql_existe = "SELECT id FROM clientes WHERE id = ?";
        $stmt_existe = $conn->prepare($sql_existe);
        $stmt_existe->execute([$id]);
        $result_existe = $stmt_existe->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($result_existe) === 0) {
            throw new Exception("El cliente no existe en esta base de datos");
        }
        $stmt_existe = null;

        $condiciones = [];
        $params = [];
        
        if (!empty($email)) {
            $condiciones[] = "email = ?";
            $params[] = $email;
        }
        
        if (!empty($rfc)) {
            $condiciones[] = "rfc = ?";
            $params[] = $rfc;
        }
        
        if (!empty($condiciones)) {
            $params[] = $id;
            $sql_verificar = "SELECT id, email, rfc FROM clientes WHERE (" . implode(" OR ", $condiciones) . ") AND id != ?";
            $stmt_verificar = $conn->prepare($sql_verificar);
            $stmt_verificar->execute($params);
            $result_verificar = $stmt_verificar->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($result_verificar) > 0) {
                $cliente_existente = $result_verificar[0];
                $mensaje = "Ya existe otro cliente con ";
                $conflictos = [];
                
                if (!empty($email) && $cliente_existente['email'] === $email) {
                    $conflictos[] = "el email '$email'";
                }
                if (!empty($rfc) && $cliente_existente['rfc'] === $rfc) {
                    $conflictos[] = "el RFC '$rfc'";
                }
                
                throw new Exception($mensaje . implode(" y ", $conflictos));
            }
            $stmt_verificar = null;
        }

        $sql = "UPDATE clientes SET 
                nombre = ?, 
                email = ?, 
                telefono = ?, 
                direccion = ?, 
                rfc = ?, 
                fecha_actualizacion = NOW() 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $email, $telefono, $direccion, $rfc, $id]);

        if ($stmt->rowCount() >= 0) {
            $_SESSION['mensaje'] = "Cliente actualizado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al actualizar cliente");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: Clientes');
    exit();
}

function cambiarEstadoCliente($conn)
{
    $id = intval($_POST['id'] ?? 0);
    $activo = intval($_POST['activo'] ?? 0);

    try {
        $sql = "UPDATE clientes SET activo = ?, fecha_actualizacion = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$activo, $id]);

        if ($stmt->rowCount() >= 0) {
            $estado = $activo ? "activado" : "desactivado";
            $_SESSION['mensaje'] = "Cliente $estado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al cambiar estado");
        }

        $stmt = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: Clientes');
    exit();
}

function eliminarCliente($conn)
{
    $id = intval($_POST['id'] ?? 0);

    try {
        if ($id <= 0) {
            throw new Exception("ID de cliente inválido");
        }

        $sql_existe = "SELECT id, nombre FROM clientes WHERE id = ?";
        $stmt_existe = $conn->prepare($sql_existe);
        $stmt_existe->execute([$id]);
        $result_existe = $stmt_existe->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($result_existe) === 0) {
            throw new Exception("El cliente no existe");
        }
        $cliente = $result_existe[0];
        $stmt_existe = null;

        $sql_ventas = "SELECT COUNT(*) as total_ventas FROM ventas WHERE cliente_id = ?";
        $stmt_ventas = $conn->prepare($sql_ventas);
        $stmt_ventas->execute([$id]);
        $result_ventas = $stmt_ventas->fetch(PDO::FETCH_ASSOC);
        $stmt_ventas = null;

        if ($result_ventas['total_ventas'] > 0) {
            throw new Exception("No se puede eliminar el cliente porque tiene " . $result_ventas['total_ventas'] . " ventas asociadas. Puede desactivarlo en su lugar.");
        }

        $sql_delete = "DELETE FROM clientes WHERE id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->execute([$id]);

        if ($stmt_delete->rowCount() > 0) {
            $_SESSION['mensaje'] = "Cliente '" . htmlspecialchars($cliente['nombre']) . "' eliminado exitosamente";
            $_SESSION['tipo_mensaje'] = "success";
        } else {
            throw new Exception("Error al eliminar cliente");
        }

        $stmt_delete = null;
    } catch (Exception $e) {
        $_SESSION['mensaje'] = $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
    }

    header('Location: Clientes');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Clientes - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
     <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: <?php echo $_SESSION['color_primario']; ?>;
            --secondary-color: <?php echo $_SESSION['color_secundario']; ?>;
            --bs-success: var(--primary-color) !important;
            --bs-success-rgb: 39, 174, 96 !important;
        }

        .btn-success {
            --bs-btn-bg: var(--primary-color) !important;
            --bs-btn-border-color: var(--primary-color) !important;
            --bs-btn-hover-bg: var(--secondary-color) !important;
            --bs-btn-hover-border-color: var(--secondary-color) !important;
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

        .client-list-modal {
            max-height: 60vh;
            overflow-y: auto;
        }
        .client-list-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }
        .client-list-item:hover {
            background-color: #f8f9fa;
        }
        .client-list-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .client-list-info {
            flex: 1;
        }
        .client-list-name {
            font-weight: 600;
            margin-bottom: 2px;
            color: #2c3e50;
        }
        .client-list-contact {
            font-size: 0.75rem;
            color: #6c757d;
        }
        .client-list-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 12px;
            background: #e8f4fd;
            color: #2c3e50;
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

        .cliente-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
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

        .ventas-badge {
            background: #e8f4fd;
            color: #2c3e50;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .monto-ventas {
            font-size: 0.875rem;
            font-weight: 600;
            color: #27ae60;
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

        .btn-delete {
            color: #dc3545;
            border-color: #dc3545;
        }
        .btn-delete:hover {
            background-color: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        /* Responsive */
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
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay {
                position: fixed;
                top: 56px;
                left: 0;
                width: 100%;
                height: calc(100vh - 56px);
                background: rgba(0, 0, 0, 0.5);
                z-index: 1045;
                opacity: 0;
                transition: opacity 0.3s ease;
            }

            .sidebar-overlay.show {
                opacity: 1;
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

            .mobile-cliente-card {
                border-left: 4px solid #9b59b6;
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

        .cliente-frecuente {
            border-left: 4px solid #f39c12 !important;
        }

        .cliente-premium {
            border-left: 4px solid #e74c3c !important;
        }

        .swipe-indicator {
            position: fixed;
            top: 56px;
            left: 0;
            width: 30px;
            height: calc(100vh - 56px);
            background: rgba(39, 174, 96, 0.1);
            z-index: 9999;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s;
        }

        @media (max-width: 767.98px) {
            main {
                transition: none !important;
                transform: none !important;
            }
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

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="Inicio">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Usuarios">
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
                            <a class="nav-link active" href="Clientes">
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
                            <a class="nav-link" href="Reportes">
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
                                <a class="nav-link" href="Configuracion">
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
                <div class="d-flex justify-content-between align-items-center mb-4 flex-column flex-md-row">
                    <h2 class="mb-3 mb-md-0">
                        <i class="fas fa-users me-2"></i>
                        Gestión de Clientes
                    </h2>
                    <div class="btn-group-mobile">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#clienteModal" id="nuevoClienteBtn">
                            <i class="fas fa-plus me-2"></i>Nuevo Cliente
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

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-stat-type="total" data-bs-toggle="modal" data-bs-target="#modalListaClientes">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Clientes</div>
                                        <div class="metric-value text-primary"><?php echo $total_clientes; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-users fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-stat-type="activos" data-bs-toggle="modal" data-bs-target="#modalListaClientes">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Clientes Activos</div>
                                        <div class="metric-value text-success"><?php echo $clientes_activos; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card stat-card h-100" data-stat-type="con_compras" data-bs-toggle="modal" data-bs-target="#modalListaClientes">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Con Compras</div>
                                        <div class="metric-value text-info"><?php echo count($ventas_por_cliente); ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-shopping-cart fa-2x text-info opacity-25"></i>
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
                                    <input type="text" class="form-control" placeholder="Buscar clientes..." id="searchInput">
                                </div>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <select class="form-select" id="estadoFilter">
                                    <option value="">Todos los estados</option>
                                    <option value="activo">Activos</option>
                                    <option value="inactivo">Inactivos</option>
                                    <option value="con_compras">Con compras</option>
                                    <option value="sin_compras">Sin compras</option>
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
                            <h5 class="card-title mb-0">Lista de Clientes <small class="text-muted ms-2"><i class="fas fa-hand-pointer"></i> Haz clic en cualquier cliente para ver/editar</small></h5>
                            <div class="d-flex align-items-center">
                                <small class="result-count me-3">
                                    Mostrando <?php echo count($clientes); ?> de <?php echo $total_registros; ?> clientes
                                </small>
                                <?php if ($total_paginas > 1): ?>
                                    <span class="badge bg-secondary">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="clientesTable">
                                    <thead>
                                        <tr>
                                            <th>Cliente</th>
                                            <th>Contacto</th>
                                            <th>Compras</th>
                                            <th>Total Gastado</th>
                                            <th>Estado</th>
                                            <th>Registro</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($clientes)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">
                                                    <i class="fas fa-users fa-3x mb-3"></i>
                                                    <p>No se encontraron clientes</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($clientes as $cliente):
                                                $ventas_cliente = $ventas_por_cliente[$cliente['id']] ?? null;
                                                $total_ventas = $ventas_cliente['total_ventas'] ?? 0;
                                                $monto_total = $ventas_cliente['monto_total'] ?? 0;

                                                $clase_especial = '';
                                                if ($total_ventas >= 10) {
                                                    $clase_especial = 'cliente-premium';
                                                } elseif ($total_ventas >= 5) {
                                                    $clase_especial = 'cliente-frecuente';
                                                }
                                            ?>
                                                <tr class="clickable-row <?php echo $clase_especial; ?>" 
                                                    data-id="<?php echo $cliente['id']; ?>"
                                                    data-nombre="<?php echo htmlspecialchars($cliente['nombre']); ?>"
                                                    data-email="<?php echo htmlspecialchars($cliente['email']); ?>"
                                                    data-telefono="<?php echo htmlspecialchars($cliente['telefono']); ?>"
                                                    data-direccion="<?php echo htmlspecialchars($cliente['direccion']); ?>"
                                                    data-rfc="<?php echo htmlspecialchars($cliente['rfc']); ?>"
                                                    data-activo="<?php echo $cliente['activo']; ?>"
                                                    data-fecha_creacion="<?php echo $cliente['fecha_creacion']; ?>"
                                                    data-total_ventas="<?php echo $total_ventas; ?>"
                                                    data-monto_total="<?php echo $monto_total; ?>">
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="cliente-avatar me-3">
                                                                <?php echo strtoupper(substr($cliente['nombre'], 0, 2)); ?>
                                                            </div>
                                                            <div>
                                                                <div class="fw-bold"><?php echo htmlspecialchars($cliente['nombre']); ?></div>
                                                                <?php if (!empty($cliente['rfc'])): ?>
                                                                    <small class="rfc-badge"><?php echo htmlspecialchars($cliente['rfc']); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="contacto-info">
                                                            <?php if (!empty($cliente['email'])): ?>
                                                                <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($cliente['email']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($cliente['telefono'])): ?>
                                                                <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($cliente['telefono']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($total_ventas > 0): ?>
                                                            <span class="ventas-badge">
                                                                <i class="fas fa-shopping-cart me-1"></i>
                                                                <?php echo $total_ventas; ?> compras
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Sin compras</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($monto_total > 0): ?>
                                                            <span class="monto-ventas">$<?php echo number_format($monto_total, 2); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">$0.00</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $cliente['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                            <?php echo $cliente['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($cliente['fecha_creacion'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_paginas > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination-info">
                                        Mostrando <?php echo count($clientes); ?> de <?php echo $total_registros; ?> clientes
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
                </div>

                <!-- Vista de Tarjetas (Móvil) - SIN botones de acción -->
                <div class="d-lg-none">
                    <div class="row" id="mobileClientes">
                        <?php if (empty($clientes)): ?>
                            <div class="col-12">
                                <div class="card text-center py-5">
                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No se encontraron clientes</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($clientes as $cliente):
                                $ventas_cliente = $ventas_por_cliente[$cliente['id']] ?? null;
                                $total_ventas = $ventas_cliente['total_ventas'] ?? 0;
                                $monto_total = $ventas_cliente['monto_total'] ?? 0;

                                $clase_especial = '';
                                if ($total_ventas >= 10) {
                                    $clase_especial = 'cliente-premium';
                                } elseif ($total_ventas >= 5) {
                                    $clase_especial = 'cliente-frecuente';
                                }
                            ?>
                                <div class="col-12 mb-3">
                                    <div class="card mobile-cliente-card clickable-card <?php echo $clase_especial; ?>"
                                        data-id="<?php echo $cliente['id']; ?>"
                                        data-nombre="<?php echo htmlspecialchars($cliente['nombre']); ?>"
                                        data-email="<?php echo htmlspecialchars($cliente['email']); ?>"
                                        data-telefono="<?php echo htmlspecialchars($cliente['telefono']); ?>"
                                        data-direccion="<?php echo htmlspecialchars($cliente['direccion']); ?>"
                                        data-rfc="<?php echo htmlspecialchars($cliente['rfc']); ?>"
                                        data-activo="<?php echo $cliente['activo']; ?>"
                                        data-fecha_creacion="<?php echo $cliente['fecha_creacion']; ?>"
                                        data-total_ventas="<?php echo $total_ventas; ?>"
                                        data-monto_total="<?php echo $monto_total; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="cliente-avatar me-3">
                                                        <?php echo strtoupper(substr($cliente['nombre'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($cliente['nombre']); ?></h6>
                                                        <?php if (!empty($cliente['rfc'])): ?>
                                                            <small class="rfc-badge"><?php echo htmlspecialchars($cliente['rfc']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="status-badge <?php echo $cliente['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $cliente['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                </span>
                                            </div>

                                            <div class="contacto-info mb-2">
                                                <?php if (!empty($cliente['email'])): ?>
                                                    <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($cliente['email']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($cliente['telefono'])): ?>
                                                    <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($cliente['telefono']); ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($cliente['direccion'])): ?>
                                                <div class="info-text mb-2">
                                                    <i class="fas fa-map-marker-alt me-1"></i>
                                                    <?php echo htmlspecialchars($cliente['direccion']); ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <?php if ($total_ventas > 0): ?>
                                                        <span class="ventas-badge me-2">
                                                            <i class="fas fa-shopping-cart me-1"></i>
                                                            <?php echo $total_ventas; ?> compras
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Sin compras</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($monto_total > 0): ?>
                                                    <span class="monto-ventas">$<?php echo number_format($monto_total, 2); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Paginación para móvil -->
                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-info">
                                Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
                            </div>
                            <nav>
                                <ul class="pagination mb-0">
                                    <li class="page-item <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>" title="Página anterior">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>" title="Página siguiente">
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

    <!-- MODAL PARA LISTA DE CLIENTES (Estadísticas) -->
    <div class="modal fade" id="modalListaClientes" tabindex="-1" aria-labelledby="modalListaClientesLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalListaClientesLabel">Lista de Clientes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body client-list-modal" id="modalListaClientesBody">
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

    <!-- Modal para Nuevo/Editar Cliente -->
    <div class="modal fade" id="clienteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nuevo Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="clienteForm">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="formAction" value="crear">
                        <input type="hidden" name="id" id="clienteId">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombre del Cliente *</label>
                                <input type="text" class="form-control" name="nombre" id="nombre" required
                                    placeholder="Nombre completo del cliente">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">RFC</label>
                                <input type="text" class="form-control" name="rfc" id="rfc"
                                    placeholder="RFC del cliente">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="email"
                                    placeholder="Correo electrónico">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="tel" class="form-control" name="telefono" id="telefono"
                                    placeholder="Número de teléfono">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Dirección</label>
                            <textarea class="form-control" name="direccion" id="direccion" rows="3"
                                placeholder="Dirección completa del cliente"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Guardar Cliente</button>
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
                    <h5 class="modal-title">Detalles del Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detallesContent">
                    <!-- Contenido dinámico -->
                </div>
                <div class="modal-footer" id="detallesFooter">
                    <!-- Botones de acción se agregarán dinámicamente -->
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Pasar datos de PHP a JavaScript para los modales
        const clientesData = {
            total: <?php echo json_encode($todos_clientes); ?>,
            activos: <?php echo json_encode($clientes_activos_lista); ?>,
            con_compras: <?php echo json_encode($clientes_con_compras); ?>
        };

        // Función para generar el HTML de la lista de clientes
        function generarListaClientes(tipo) {
            let clientes = [];
            let titulo = '';
            
            switch(tipo) {
                case 'total':
                    clientes = clientesData.total;
                    titulo = 'Todos los Clientes';
                    break;
                case 'activos':
                    clientes = clientesData.activos;
                    titulo = 'Clientes Activos';
                    break;
                case 'con_compras':
                    clientes = clientesData.con_compras;
                    titulo = 'Clientes con Compras';
                    break;
                default:
                    clientes = [];
                    titulo = 'Clientes';
            }
            
            document.getElementById('modalListaClientesLabel').textContent = titulo;
            
            if (!clientes || clientes.length === 0) {
                return `
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-user-slash fa-3x mb-3"></i>
                        <p>No hay clientes en esta categoría</p>
                    </div>
                `;
            }
            
            let html = '<div class="list-group list-group-flush">';
            clientes.forEach(cliente => {
                let contactoHtml = '';
                if (cliente.email || cliente.telefono) {
                    contactoHtml = '<div class="client-list-contact">';
                    if (cliente.email) contactoHtml += '<i class="fas fa-envelope me-1"></i>' + escapeHtml(cliente.email) + ' ';
                    if (cliente.telefono) contactoHtml += '<i class="fas fa-phone me-1"></i>' + escapeHtml(cliente.telefono);
                    contactoHtml += '</div>';
                }
                
                let comprasHtml = '';
                if (cliente.total_compras) {
                    comprasHtml = `<span class="client-list-badge ms-2">${cliente.total_compras} compras</span>`;
                }
                
                let estadoHtml = '';
                if (tipo === 'total' && cliente.activo !== undefined) {
                    const estadoClass = cliente.activo ? 'status-active' : 'status-inactive';
                    const estadoText = cliente.activo ? 'Activo' : 'Inactivo';
                    estadoHtml = `<span class="status-badge ${estadoClass} ms-2" style="font-size: 0.7rem; padding: 2px 8px;">${estadoText}</span>`;
                }
                
                html += `
                    <div class="client-list-item">
                        <div class="client-list-avatar">
                            ${cliente.nombre ? cliente.nombre.charAt(0).toUpperCase() : 'C'}
                        </div>
                        <div class="client-list-info">
                            <div class="client-list-name">
                                ${escapeHtml(cliente.nombre)}
                                ${comprasHtml}
                                ${estadoHtml}
                            </div>
                            ${contactoHtml}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            return html;
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        // Agregar evento a las tarjetas de estadísticas
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function(e) {
                e.stopPropagation();
                const statType = this.getAttribute('data-stat-type');
                if (statType) {
                    const modalBody = document.getElementById('modalListaClientesBody');
                    if (modalBody) {
                        modalBody.innerHTML = `
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Cargando...</span>
                                </div>
                            </div>
                        `;
                        setTimeout(() => {
                            modalBody.innerHTML = generarListaClientes(statType);
                        }, 100);
                    }
                }
            });
        });

        // Función para mostrar el modal de detalles con botones de acción
        function mostrarDetallesCliente(clienteData) {
            const detallesContent = document.getElementById('detallesContent');
            const detallesFooter = document.getElementById('detallesFooter');
            
            const estadoText = clienteData.activo == 1 ? 'Activo' : 'Inactivo';
            const estadoClass = clienteData.activo == 1 ? 'status-active' : 'status-inactive';
            const montoTotal = parseFloat(clienteData.monto_total || 0);
            
            detallesContent.innerHTML = `
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>Nombre:</strong>
                        <p><i class="fas fa-user me-1"></i> ${escapeHtml(clienteData.nombre)}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>RFC:</strong>
                        <p>${clienteData.rfc ? escapeHtml(clienteData.rfc) : 'No especificado'}</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>Email:</strong>
                        <p><i class="fas fa-envelope me-1"></i> ${clienteData.email ? escapeHtml(clienteData.email) : 'No especificado'}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>Teléfono:</strong>
                        <p><i class="fas fa-phone me-1"></i> ${clienteData.telefono ? escapeHtml(clienteData.telefono) : 'No especificado'}</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 mb-3">
                        <strong>Dirección:</strong>
                        <p><i class="fas fa-map-marker-alt me-1"></i> ${clienteData.direccion ? escapeHtml(clienteData.direccion) : 'No especificada'}</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>Estado:</strong>
                        <p><span class="status-badge ${estadoClass}">${estadoText}</span></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>Fecha de Registro:</strong>
                        <p><i class="fas fa-calendar-alt me-1"></i> ${new Date(clienteData.fecha_creacion).toLocaleDateString('es-MX')}</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <h6 class="border-bottom pb-2">Historial de Compras</h6>
                        ${clienteData.total_ventas > 0 ? `
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <strong>Total de Compras:</strong>
                                    <p><i class="fas fa-shopping-cart me-1"></i> ${clienteData.total_ventas} compras</p>
                                </div>
                                <div class="col-md-6">
                                    <strong>Monto Total:</strong>
                                    <p class="monto-ventas">$${montoTotal.toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                                </div>
                            </div>
                        ` : '<p class="text-muted mt-2">Este cliente no ha realizado compras.</p>'}
                    </div>
                </div>
            `;
            
            // Agregar botones de acción al footer
            const nuevoEstado = clienteData.activo == 1 ? 0 : 1;
            const estadoBotonTexto = clienteData.activo == 1 ? 'Desactivar Cliente' : 'Activar Cliente';
            const estadoBotonColor = clienteData.activo == 1 ? 'warning' : 'success';
            const estadoBotonIcono = clienteData.activo == 1 ? 'ban' : 'check';
            
            detallesFooter.innerHTML = `
                <div class="d-flex justify-content-between w-100 flex-wrap gap-2">
                    <button type="button" class="btn btn-success" id="editarDesdeDetallesBtn">
                        <i class="fas fa-edit me-1"></i>Editar Cliente
                    </button>
                    <form method="POST" class="d-inline" id="cambiarEstadoForm">
                        <input type="hidden" name="accion" value="cambiar_estado">
                        <input type="hidden" name="id" value="${clienteData.id}">
                        <input type="hidden" name="activo" value="${nuevoEstado}">
                        <button type="submit" class="btn btn-${estadoBotonColor}">
                            <i class="fas fa-${estadoBotonIcono} me-1"></i>${estadoBotonTexto}
                        </button>
                    </form>
                    <button type="button" class="btn btn-danger" id="eliminarDesdeDetallesBtn">
                        <i class="fas fa-trash-alt me-1"></i>Eliminar Cliente
                    </button>
                    <button type="button" class="btn btn-secondary ms-auto" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
                </div>
            `;
            
            // Mostrar el modal
            const modal = new bootstrap.Modal(document.getElementById('detallesModal'));
            modal.show();
            
            // Evento para editar desde detalles
            document.getElementById('editarDesdeDetallesBtn').addEventListener('click', function() {
                modal.hide();
                // Cargar datos en el modal de edición
                document.getElementById('modalTitle').textContent = 'Editar Cliente';
                document.getElementById('formAction').value = 'editar';
                document.getElementById('clienteId').value = clienteData.id;
                document.getElementById('nombre').value = clienteData.nombre;
                document.getElementById('rfc').value = clienteData.rfc || '';
                document.getElementById('email').value = clienteData.email || '';
                document.getElementById('telefono').value = clienteData.telefono || '';
                document.getElementById('direccion').value = clienteData.direccion || '';
                
                const modalEditar = new bootstrap.Modal(document.getElementById('clienteModal'));
                modalEditar.show();
            });
            
            // Evento para eliminar desde detalles
            document.getElementById('eliminarDesdeDetallesBtn').addEventListener('click', function() {
                modal.hide();
                deleteCliente(clienteData.id, clienteData.nombre);
            });
        }
        
        // Función de eliminación con SweetAlert2
        function deleteCliente(clienteId, clienteNombre) {
            Swal.fire({
                title: '¿Eliminar cliente?',
                html: `¿Estás seguro de que deseas eliminar a <strong>${clienteNombre}</strong>?<br><br><span class="text-danger">Esta acción no se puede deshacer.</span>`,
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
                    form.action = 'Clientes';
                    
                    const inputAccion = document.createElement('input');
                    inputAccion.type = 'hidden';
                    inputAccion.name = 'accion';
                    inputAccion.value = 'eliminar';
                    
                    const inputId = document.createElement('input');
                    inputId.type = 'hidden';
                    inputId.name = 'id';
                    inputId.value = clienteId;
                    
                    form.appendChild(inputAccion);
                    form.appendChild(inputId);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // Evento para filas clickeables (escritorio)
        document.querySelectorAll('.clickable-row').forEach(row => {
            row.addEventListener('click', function(e) {
                // Evitar que se active si se hizo clic en un enlace o botón dentro de la fila
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                    return;
                }
                
                const clienteData = {
                    id: this.getAttribute('data-id'),
                    nombre: this.getAttribute('data-nombre'),
                    email: this.getAttribute('data-email'),
                    telefono: this.getAttribute('data-telefono'),
                    direccion: this.getAttribute('data-direccion'),
                    rfc: this.getAttribute('data-rfc'),
                    activo: this.getAttribute('data-activo'),
                    fecha_creacion: this.getAttribute('data-fecha_creacion'),
                    total_ventas: this.getAttribute('data-total_ventas'),
                    monto_total: this.getAttribute('data-monto_total')
                };
                
                mostrarDetallesCliente(clienteData);
            });
        });
        
        // Evento para tarjetas clickeables (móvil)
        document.querySelectorAll('.clickable-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Evitar que se active si se hizo clic en un botón dentro de la tarjeta
                if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                    return;
                }
                
                const clienteData = {
                    id: this.getAttribute('data-id'),
                    nombre: this.getAttribute('data-nombre'),
                    email: this.getAttribute('data-email'),
                    telefono: this.getAttribute('data-telefono'),
                    direccion: this.getAttribute('data-direccion'),
                    rfc: this.getAttribute('data-rfc'),
                    activo: this.getAttribute('data-activo'),
                    fecha_creacion: this.getAttribute('data-fecha_creacion'),
                    total_ventas: this.getAttribute('data-total_ventas'),
                    monto_total: this.getAttribute('data-monto_total')
                };
                
                mostrarDetallesCliente(clienteData);
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            let overlay = null;

            function openSidebar() {
                if (!sidebar) return;

                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    overlay.style.cssText = `
                        position: fixed;
                        top: 56px;
                        left: 0;
                        width: 100%;
                        height: calc(100vh - 56px);
                        background: rgba(0, 0, 0, 0.5);
                        z-index: 1045;
                        opacity: 0;
                        transition: opacity 0.3s ease;
                    `;
                    document.body.appendChild(overlay);

                    overlay.addEventListener('click', closeSidebar);
                    overlay.addEventListener('touchstart', closeSidebar);
                }

                sidebar.style.transition = 'transform 0.3s ease-out';
                sidebar.style.transform = 'translateX(0)';
                document.body.classList.add('sidebar-open');

                setTimeout(() => {
                    if (overlay) overlay.style.opacity = '1';
                }, 10);
            }

            function closeSidebar() {
                if (!sidebar) return;

                sidebar.style.transition = 'transform 0.3s ease-out';
                sidebar.style.transform = 'translateX(-100%)';
                document.body.classList.remove('sidebar-open');

                if (overlay) {
                    overlay.style.opacity = '0';
                    setTimeout(() => {
                        if (overlay && overlay.parentNode) {
                            overlay.parentNode.removeChild(overlay);
                            overlay = null;
                        }
                    }, 300);
                }
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (sidebar.style.transform === 'translateX(0px)' || sidebar.classList.contains('show')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                });
            }

            document.querySelectorAll('#sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebar();
                    }
                });
            });

            // SWIPE PARA SIDEBAR
            let touchStartX = 0;
            let touchStartY = 0;
            let isSwiping = false;
            const SWIPE_THRESHOLD = 50;
            const SWIPE_EDGE_ZONE = 30;

            document.addEventListener('touchstart', function(e) {
                if (window.innerWidth >= 768) return;
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                isSwiping = true;
            });

            document.addEventListener('touchmove', function(e) {
                if (!isSwiping) return;
                const touchX = e.touches[0].clientX;
                const touchY = e.touches[0].clientY;
                const deltaX = touchX - touchStartX;
                const deltaY = touchY - touchStartY;
                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                    e.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('touchend', function(e) {
                if (!isSwiping) return;
                const touchEndX = e.changedTouches[0].clientX;
                const deltaX = touchEndX - touchStartX;
                if (Math.abs(deltaX) > SWIPE_THRESHOLD) {
                    if (deltaX > 0 && touchStartX <= SWIPE_EDGE_ZONE) {
                        openSidebar();
                    } else if (deltaX < 0 && sidebar.style.transform === 'translateX(0px)') {
                        closeSidebar();
                    }
                }
                isSwiping = false;
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebar();
                }
            });

            // Búsqueda en tiempo real
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const rows = document.querySelectorAll('#clientesTable tbody tr');
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                    const cards = document.querySelectorAll('#mobileClientes .col-12');
                    cards.forEach(card => {
                        const text = card.textContent.toLowerCase();
                        card.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            }

            // Editar cliente (botones de edición existentes - se mantienen por compatibilidad)
            document.querySelectorAll('.edit-cliente-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const clienteId = this.getAttribute('data-id');
                    const clienteNombre = this.getAttribute('data-nombre');
                    const clienteEmail = this.getAttribute('data-email');
                    const clienteTelefono = this.getAttribute('data-telefono');
                    const clienteDireccion = this.getAttribute('data-direccion');
                    const clienteRfc = this.getAttribute('data-rfc');

                    document.getElementById('modalTitle').textContent = 'Editar Cliente';
                    document.getElementById('formAction').value = 'editar';
                    document.getElementById('clienteId').value = clienteId;
                    document.getElementById('nombre').value = clienteNombre;
                    document.getElementById('rfc').value = clienteRfc;
                    document.getElementById('email').value = clienteEmail;
                    document.getElementById('telefono').value = clienteTelefono;
                    document.getElementById('direccion').value = clienteDireccion;
                    
                    const modalElement = document.getElementById('clienteModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
                    modal.show();
                });
            });

            // Resetear formulario al abrir modal para nuevo cliente
            document.getElementById('nuevoClienteBtn').addEventListener('click', function() {
                document.getElementById('clienteForm').reset();
                document.getElementById('modalTitle').textContent = 'Nuevo Cliente';
                document.getElementById('formAction').value = 'crear';
                document.getElementById('clienteId').value = '';
            });

            // Resetear cuando se abre el modal sin datos de edición
            document.getElementById('clienteModal').addEventListener('show.bs.modal', function(e) {
                if (!document.getElementById('clienteId').value) {
                    document.getElementById('clienteForm').reset();
                    document.getElementById('modalTitle').textContent = 'Nuevo Cliente';
                    document.getElementById('formAction').value = 'crear';
                    document.getElementById('clienteId').value = '';
                }
            });

            // Filtros
            function aplicarFiltros() {
                const estadoFilter = document.getElementById('estadoFilter').value;
                const showRFC = document.getElementById('showRFC').checked;

                const rows = document.querySelectorAll('#clientesTable tbody tr');
                rows.forEach(row => {
                    const isActive = row.querySelector('.status-badge').textContent.trim() === 'Activo';
                    const hasPurchases = row.querySelector('.ventas-badge') !== null;

                    let show = true;
                    if (estadoFilter === 'activo' && !isActive) show = false;
                    if (estadoFilter === 'inactivo' && isActive) show = false;
                    if (estadoFilter === 'con_compras' && !hasPurchases) show = false;
                    if (estadoFilter === 'sin_compras' && hasPurchases) show = false;

                    const rfcBadge = row.querySelector('.rfc-badge');
                    if (rfcBadge) {
                        rfcBadge.style.display = showRFC ? 'inline-block' : 'none';
                    }

                    row.style.display = show ? '' : 'none';
                });

                const cards = document.querySelectorAll('#mobileClientes .col-12');
                cards.forEach(card => {
                    const isActive = card.querySelector('.status-badge').textContent.trim() === 'Activo';
                    const hasPurchases = card.querySelector('.ventas-badge') !== null;

                    let show = true;
                    if (estadoFilter === 'activo' && !isActive) show = false;
                    if (estadoFilter === 'inactivo' && isActive) show = false;
                    if (estadoFilter === 'con_compras' && !hasPurchases) show = false;
                    if (estadoFilter === 'sin_compras' && hasPurchases) show = false;

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
        });
    </script>
</body>

</html>
