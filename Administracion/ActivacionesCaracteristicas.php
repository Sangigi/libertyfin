<?php

$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Configuración de la base de datos principal
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname_main = "juanc141_ventas";

$mensaje = '';
$tipo_mensaje = '';

// Obtener lista de empresas
$empresas = [];
$conn = new mysqli($servername, $username, $password, $dbname_main);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener todas las empresas activas
$sql_empresas = "SELECT id, nombre_empresa, plan FROM empresas WHERE activo = 1 ORDER BY nombre_empresa";
$result_empresas = $conn->query($sql_empresas);

while ($row = $result_empresas->fetch_assoc()) {
    $empresas[] = $row;
}

// Obtener empresa seleccionada (por defecto la primera)
$empresa_id = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : (count($empresas) > 0 ? $empresas[0]['id'] : 0);

// Obtener características de la empresa seleccionada
$caracteristicas = [];
$tipos_unidad_config = [];

if ($empresa_id > 0) {
    $sql_caract = "SELECT caracteristica, habilitado, configuracion_extra 
                   FROM empresa_caracteristicas 
                   WHERE empresa_id = ?";
    $stmt_caract = $conn->prepare($sql_caract);
    $stmt_caract->bind_param("i", $empresa_id);
    $stmt_caract->execute();
    $result_caract = $stmt_caract->get_result();
    
    while ($row = $result_caract->fetch_assoc()) {
        $caracteristicas[$row['caracteristica']] = $row['habilitado'];
        
        if ($row['caracteristica'] === 'unidad_medida' && !empty($row['configuracion_extra'])) {
            $tipos_unidad_config = json_decode($row['configuracion_extra'], true);
            if (!is_array($tipos_unidad_config)) {
                $tipos_unidad_config = ['pieza', 'kilo', 'litro'];
            }
        }
    }
    
    // Valores por defecto si no existen
    if (!isset($caracteristicas['precio_compra'])) $caracteristicas['precio_compra'] = 1;
    if (!isset($caracteristicas['unidad_medida'])) $caracteristicas['unidad_medida'] = 1;
    if (!isset($caracteristicas['proveedor'])) $caracteristicas['proveedor'] = 1;
    if (!isset($caracteristicas['fecha_caducidad'])) $caracteristicas['fecha_caducidad'] = 1;
    if (!isset($caracteristicas['categoria'])) $caracteristicas['categoria'] = 1;
    
    if (empty($tipos_unidad_config)) {
        $tipos_unidad_config = ['pieza', 'kilo', 'litro'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activar Características - Panel Administrativo</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 1rem;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-outline-light {
            border-color: rgba(255,255,255,0.5);
        }
        
        .btn-outline-light:hover {
            background-color: rgba(255,255,255,0.1);
            border-color: white;
        }
        
        .caracteristica-item {
            padding: 1rem;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .caracteristica-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .caracteristica-item.disabled {
            background-color: #f8f9fa;
            opacity: 0.7;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .badge-plan {
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
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
        
        .unidad-tipo-item {
            display: inline-block;
            margin: 0.25rem;
        }
        
        .unidad-tipo-item .form-check {
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .unidad-tipo-item .form-check-input:checked + .form-check-label {
            color: var(--primary-color);
            font-weight: bold;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(39, 174, 96, 0.25);
        }
        
        .alert {
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../images/LibertyfinBlanco.png" alt="Logo" class="me-2" style="height: 30px;">
                <span>Panel de Administración</span>
            </a>
            
            <div class="d-flex">
                <div class="dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </div>
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
                                Planes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="usuarios.php">
                                <i class="fas fa-user-cog"></i>
                                Usuarios Admin
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="solicitudes.php">
                                <i class="fas fa-clipboard-list"></i>
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
                            <a class="nav-link active" href="ActivacionesCaracteristicas.php">
                                <i class="fas fa-sliders-h"></i>
                                Características
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                
                <!-- Mensajes -->
                <div id="mensajeContainer"></div>
                
                <!-- Selector de Empresa -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-building me-2"></i>Seleccionar Empresa
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-end">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-store me-2"></i>Empresa
                                </label>
                                <select class="form-select" id="empresaSelect">
                                    <?php foreach ($empresas as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" 
                                            <?php echo $empresa_id == $emp['id'] ? 'selected' : ''; ?>
                                            data-plan="<?php echo $emp['plan']; ?>">
                                            <?php echo htmlspecialchars($emp['nombre_empresa']); ?> 
                                            (Plan: <?php echo ucfirst($emp['plan']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary w-100" id="btnCargar">
                                    <i class="fas fa-sync-alt me-2"></i>Cargar Configuración
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Configuración de Características -->
                <div class="card mt-4" id="configuracionCard">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-sliders-h me-2"></i>
                            Configuración de Características para <span id="empresaNombre"><?php echo htmlspecialchars($empresas[array_search($empresa_id, array_column($empresas, 'id'))]['nombre_empresa'] ?? ''); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form id="caracteristicasForm">
                            <input type="hidden" name="empresa_id" id="empresa_id" value="<?php echo $empresa_id; ?>">
                            
                            <!-- Precio Compra -->
                            <div class="caracteristica-item">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-tags fa-2x me-3 text-primary"></i>
                                            <div>
                                                <h6 class="mb-0">Precio Compra</h6>
                                                <small class="text-muted">Permite registrar el precio de compra del producto</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-muted small">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Si está desactivado, el campo "Precio Compra" no se mostrará
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <label class="switch">
                                            <input type="checkbox" name="precio_compra" id="precio_compra" 
                                                <?php echo ($caracteristicas['precio_compra'] ?? 1) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Unidad de Medida -->
                            <div class="caracteristica-item" id="unidadMedidaItem">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-ruler fa-2x me-3 text-primary"></i>
                                            <div>
                                                <h6 class="mb-0">Unidad de Medida</h6>
                                                <small class="text-muted">Permite seleccionar la unidad de medida del producto</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-muted small">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Si está desactivado: se oculta unidad, peso y venta por fracciones
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <label class="switch">
                                            <input type="checkbox" name="unidad_medida" id="unidad_medida" 
                                                <?php echo ($caracteristicas['unidad_medida'] ?? 1) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Configuración extra: Tipos de Unidad permitidos -->
                                <div class="row mt-3" id="tiposUnidadContainer" style="<?php echo ($caracteristicas['unidad_medida'] ?? 1) ? '' : 'display: none;'; ?>">
                                    <div class="col-12">
                                        <hr>
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-cog me-2"></i>Tipos de Unidad Permitidos
                                        </label>
                                        <small class="text-muted d-block mb-3">
                                            Seleccione qué tipos de unidad puede usar esta empresa
                                        </small>
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="tipos_unidad[]" value="pieza" id="tipo_pieza"
                                                        <?php echo in_array('pieza', $tipos_unidad_config) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="tipo_pieza">
                                                        <i class="fas fa-cube me-1"></i> Pieza
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="tipos_unidad[]" value="kilo" id="tipo_kilo"
                                                        <?php echo in_array('kilo', $tipos_unidad_config) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="tipo_kilo">
                                                        <i class="fas fa-weight-hanging me-1"></i> Kilo
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="tipos_unidad[]" value="litro" id="tipo_litro"
                                                        <?php echo in_array('litro', $tipos_unidad_config) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="tipo_litro">
                                                        <i class="fas fa-tint me-1"></i> Litro
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="tipos_unidad[]" value="tonelada" id="tipo_tonelada"
                                                        <?php echo in_array('tonelada', $tipos_unidad_config) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="tipo_tonelada">
                                                        <i class="fas fa-truck me-1"></i> Tonelada
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-2">
                                            <i class="fas fa-info-circle"></i> 
                                            Si no selecciona ninguno, se usará "Pieza" por defecto
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Proveedor -->
                            <div class="caracteristica-item">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-truck fa-2x me-3 text-primary"></i>
                                            <div>
                                                <h6 class="mb-0">Proveedor</h6>
                                                <small class="text-muted">Permite asociar un proveedor al producto</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-muted small">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Si está desactivado, el campo "Proveedor" no se mostrará
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <label class="switch">
                                            <input type="checkbox" name="proveedor" id="proveedor" 
                                                <?php echo ($caracteristicas['proveedor'] ?? 1) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Fecha de Caducidad -->
                            <div class="caracteristica-item">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-calendar-alt fa-2x me-3 text-primary"></i>
                                            <div>
                                                <h6 class="mb-0">Fecha de Caducidad</h6>
                                                <small class="text-muted">Permite registrar fecha de caducidad del producto</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-muted small">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Si está desactivado, el campo "Fecha de Caducidad" no se mostrará
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <label class="switch">
                                            <input type="checkbox" name="fecha_caducidad" id="fecha_caducidad" 
                                                <?php echo ($caracteristicas['fecha_caducidad'] ?? 1) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Categoría -->
                            <div class="caracteristica-item">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-folder-open fa-2x me-3 text-primary"></i>
                                            <div>
                                                <h6 class="mb-0">Categoría</h6>
                                                <small class="text-muted">Permite clasificar productos por categoría</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-muted small">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Si está desactivado, el campo "Categoría" no se mostrará
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <label class="switch">
                                            <input type="checkbox" name="categoria" id="categoria" 
                                                <?php echo ($caracteristicas['categoria'] ?? 1) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary btn-lg px-5" id="btnGuardar">
                                    <i class="fas fa-save me-2"></i>Guardar Configuración
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Información de ayuda -->
                <div class="card mt-4">
                    <div class="card-body">
                        <h6 class="mb-3">
                            <i class="fas fa-question-circle me-2 text-primary"></i>
                            ¿Cómo funciona?
                        </h6>
                        <ul class="text-muted small">
                            <li>Las características que active aquí se reflejarán inmediatamente en el módulo de productos.</li>
                            <li>Si desactiva "Unidad de Medida", automáticamente se ocultarán también "Peso por Unidad" y "Permitir venta por fracciones".</li>
                            <li>Puede seleccionar múltiples tipos de unidad para que la empresa pueda elegir entre ellos al crear/editar productos.</li>
                            <li>Esta configuración es específica por empresa y no afecta a otras empresas.</li>
                        </ul>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    $(document).ready(function() {
        
        // Mostrar/ocultar configuración de tipos de unidad según checkbox
        $('#unidad_medida').on('change', function() {
            if ($(this).is(':checked')) {
                $('#tiposUnidadContainer').slideDown();
            } else {
                $('#tiposUnidadContainer').slideUp();
            }
        });
        
        // Cambiar empresa
        $('#btnCargar').on('click', function() {
            const empresaId = $('#empresaSelect').val();
            const empresaNombre = $('#empresaSelect option:selected').text();
            
            // Cargar configuración de la empresa seleccionada
            $.ajax({
                url: 'obtener_caracteristicas.php',
                type: 'POST',
                data: { empresa_id: empresaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Actualizar campos del formulario
                        $('#precio_compra').prop('checked', response.data.precio_compra == 1);
                        $('#unidad_medida').prop('checked', response.data.unidad_medida == 1);
                        $('#proveedor').prop('checked', response.data.proveedor == 1);
                        $('#fecha_caducidad').prop('checked', response.data.fecha_caducidad == 1);
                        $('#categoria').prop('checked', response.data.categoria == 1);
                        
                        // Actualizar tipos de unidad
                        if (response.data.tipos_unidad && Array.isArray(response.data.tipos_unidad)) {
                            $('input[name="tipos_unidad[]"]').each(function() {
                                $(this).prop('checked', response.data.tipos_unidad.includes($(this).val()));
                            });
                        }
                        
                        // Actualizar empresa ID
                        $('#empresa_id').val(empresaId);
                        $('#empresaNombre').text(empresaNombre);
                        
                        // Mostrar/ocultar configuración de tipos
                        if (response.data.unidad_medida == 1) {
                            $('#tiposUnidadContainer').show();
                        } else {
                            $('#tiposUnidadContainer').hide();
                        }
                        
                        // Mostrar mensaje
                        mostrarMensaje('success', 'Configuración cargada correctamente');
                    } else {
                        mostrarMensaje('danger', 'Error al cargar: ' + response.message);
                    }
                },
                error: function() {
                    mostrarMensaje('danger', 'Error de conexión al cargar la configuración');
                }
            });
        });
        
        // Guardar configuración
        $('#caracteristicasForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = $(this).serialize();
            
            $('#btnGuardar').prop('disabled', true);
            $('#btnGuardar').html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...');
            
            $.ajax({
                url: 'guardar_caracteristicas.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        mostrarMensaje('success', response.message);
                    } else {
                        mostrarMensaje('danger', 'Error: ' + response.message);
                    }
                },
                error: function() {
                    mostrarMensaje('danger', 'Error de conexión al guardar la configuración');
                },
                complete: function() {
                    $('#btnGuardar').prop('disabled', false);
                    $('#btnGuardar').html('<i class="fas fa-save me-2"></i>Guardar Configuración');
                }
            });
        });
        
        // Función para mostrar mensajes
        function mostrarMensaje(tipo, mensaje) {
            const alertHtml = `
                <div class="alert alert-${tipo} alert-dismissible fade show" role="alert">
                    <i class="fas fa-${tipo === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                    ${mensaje}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            $('#mensajeContainer').html(alertHtml);
            
            // Auto cerrar después de 5 segundos
            setTimeout(() => {
                $('.alert').alert('close');
            }, 5000);
        }
        
        // =============================================
        // FUNCIONALIDAD DE SWIPE AUTOMÁTICO PARA SIDEBAR
        // =============================================
        
        // Variables para controlar el swipe
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        let isSidebarTouch = false;
        const SWIPE_THRESHOLD = 50;
        const SWIPE_EDGE_ZONE = 30;
        const VERTICAL_THRESHOLD = 30;
        
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
        
        // Detectar inicio del touch
        document.addEventListener('touchstart', function(e) {
            if (window.innerWidth >= 768) return;
            
            const touchX = e.touches[0].clientX;
            const touchY = e.touches[0].clientY;
            
            if (touchX <= SWIPE_EDGE_ZONE) {
                isSidebarTouch = true;
                touchStartX = touchX;
                touchStartY = touchY;
                touchEndX = touchStartX;
                touchEndY = touchStartY;
            }
        }, { passive: true });
        
        // Detectar movimiento del touch
        document.addEventListener('touchmove', function(e) {
            if (window.innerWidth >= 768) return;
            
            if (isSidebarTouch) {
                touchEndX = e.touches[0].clientX;
                touchEndY = e.touches[0].clientY;
                
                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;
                
                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                    e.preventDefault();
                }
            }
        }, { passive: false });
        
        // Detectar fin del touch
        document.addEventListener('touchend', function(e) {
            if (window.innerWidth >= 768) return;
            
            if (isSidebarTouch) {
                isSidebarTouch = false;
                
                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;
                
                if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
                    return;
                }
                
                const sidebar = document.getElementById('sidebar');
                const isSidebarOpen = sidebar && sidebar.classList.contains('show');
                
                if (deltaX > SWIPE_THRESHOLD) {
                    if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                        openSidebarAuto();
                    }
                } else if (deltaX < -SWIPE_THRESHOLD) {
                    if (isSidebarOpen) {
                        closeSidebarAuto();
                    }
                }
            }
            
            touchStartX = 0;
            touchStartY = 0;
            touchEndX = 0;
            touchEndY = 0;
        }, { passive: true });
        
        // Control del sidebar
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
        
        // Cerrar sidebar al hacer clic en un enlace (en móvil)
        const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth < 768) {
                    closeSidebarAuto();
                }
            });
        });
        
        // Ajustar sidebar en redimensionamiento
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                closeSidebarAuto();
            }
        });
    });
    </script>
</body>
</html>