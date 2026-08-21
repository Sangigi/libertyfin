<?php

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}
// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$servername_main = "libertyfin.com.mx";
$username_main = "juanc141_alexis";
$password_main = "Alexis1997";
$dbname_main = "juanc141_ventas";

$conn_main = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

// Valores por defecto
$empresa_plan = "prueba";
$timbres_totales = 0;
$timbres_disponibles = 0;

if ($conn_main) {
    $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->bind_param("i", $_SESSION['empresa_id']);
    $stmt_empresa->execute();
    $result_empresa = $stmt_empresa->get_result();

    if ($result_empresa->num_rows > 0) {
        $empresa_data = $result_empresa->fetch_assoc();
        $empresa_plan = $empresa_data['plan'];
        $timbres_totales = $empresa_data['timbres_totales'] ?? 0;
        $timbres_disponibles = $empresa_data['timbres_disponibles'] ?? 0;
    }
    $stmt_empresa->close();
    $conn_main->close();
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Conectar a la base de datos de la empresa
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener información de la empresa y colores personalizados
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch_assoc();

// OBTENER LOGO DE LA EMPRESA - COMO EN CAJA.PHP
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

        // Si encontramos el logo, convertirlo a base64
        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_empresa = $logo_path;

            // Obtener la extensión del archivo
            $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));

            // Verificar que sea una imagen válida
            $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($extension, $extensiones_validas)) {
                // Leer el archivo y convertirlo a base64
                $logo_data = base64_encode(file_get_contents($logo_path));
                $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
            }
        }
    }

    // Si no hay colores configurados, usar valores por defecto
    $color_primario = $empresa_info['color_primario'] ?? '#27ae60';
    $color_secundario = $empresa_info['color_secundario'] ?? '#2ecc71';

    // Convertir color hexadecimal a RGB para CSS
    function hexToRgb($hex)
    {
        $hex = str_replace("#", "", $hex);
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "$r, $g, $b";
    }

    $color_primario_rgb = hexToRgb($color_primario);

    // Obtener lista de usuarios para el filtro
    $sql_usuarios = "SELECT id, nombre FROM usuarios WHERE sucursal_id = ? ORDER BY nombre";
    $stmt_usuarios = $conn->prepare($sql_usuarios);
    $stmt_usuarios->bind_param("i", $_SESSION['sucursal_id']);
    $stmt_usuarios->execute();
    $usuarios_result = $stmt_usuarios->get_result();
    $usuarios = $usuarios_result->fetch_all(MYSQLI_ASSOC);

    // Construir consulta base con filtros
    $sql = "SELECT c.*, u.nombre as usuario_nombre, s.nombre as sucursal_nombre 
            FROM caja c 
            JOIN usuarios u ON c.usuario_id = u.id 
            JOIN sucursales s ON c.sucursal_id = s.id 
            WHERE c.sucursal_id = ?";

    $params = array($_SESSION['sucursal_id']);
    $types = "i";

    // Aplicar filtros si existen
    if (isset($_GET['fecha_desde']) && !empty($_GET['fecha_desde'])) {
        $sql .= " AND DATE(c.fecha_apertura) >= ?";
        $params[] = $_GET['fecha_desde'];
        $types .= "s";
    }

    if (isset($_GET['fecha_hasta']) && !empty($_GET['fecha_hasta'])) {
        $sql .= " AND DATE(c.fecha_apertura) <= ?";
        $params[] = $_GET['fecha_hasta'];
        $types .= "s";
    }

    if (isset($_GET['usuario']) && !empty($_GET['usuario'])) {
        $sql .= " AND c.usuario_id = ?";
        $params[] = $_GET['usuario'];
        $types .= "i";
    }

    if (isset($_GET['estado']) && !empty($_GET['estado'])) {
        $sql .= " AND c.estado = ?";
        $params[] = $_GET['estado'];
        $types .= "s";
    }

    $sql .= " ORDER BY c.fecha_apertura DESC LIMIT 50";

    // Preparar y ejecutar consulta
    $stmt = $conn->prepare($sql);

    if (count($params) > 1) {
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param("i", $_SESSION['sucursal_id']);
    }

    $stmt->execute();
    $cajas = $stmt->get_result();
    $cajas_data = $cajas->fetch_all(MYSQLI_ASSOC);

    // Contadores para estadísticas
    $cajas_abiertas = 0;
    $mi_caja_abierta = false;
    foreach ($cajas_data as $caja) {
        if ($caja['estado'] == 'abierta') {
            $cajas_abiertas++;
            if ($caja['usuario_id'] == $_SESSION['usuario_id']) {
                $mi_caja_abierta = true;
            }
        }
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Caja - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <link rel="stylesheet" href="css/crm-theme.css">
</head>

<body>
    <!-- Navbar -->
 <?php include 'includes/navbar.php'; ?>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
  <?php include 'includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-history me-2"></i>Historial de Cortes de Caja
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="dashboard.php" class="btn btn-secondary me-2">
                            <i class="fas fa-arrow-left me-1"></i>Volver al Dashboard
                        </a>
                    </div>
                </div>

                <!-- Alertas -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success'];
                                                                unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error'];
                                                                        unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                        </h5>
                        <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                            <span class="badge bg-primary">Filtros activos</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="fecha_desde" class="form-label">Fecha Desde</label>
                                <input type="date" class="form-control" id="fecha_desde" name="fecha_desde"
                                    value="<?php echo isset($_GET['fecha_desde']) ? htmlspecialchars($_GET['fecha_desde']) : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_hasta" class="form-label">Fecha Hasta</label>
                                <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta"
                                    value="<?php echo isset($_GET['fecha_hasta']) ? htmlspecialchars($_GET['fecha_hasta']) : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="usuario" class="form-label">Usuario</label>
                                <select class="form-select" id="usuario" name="usuario">
                                    <option value="">Todos los usuarios</option>
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <option value="<?php echo $usuario['id']; ?>"
                                            <?php echo (isset($_GET['usuario']) && $_GET['usuario'] == $usuario['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($usuario['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="estado" class="form-label">Estado</label>
                                <select class="form-select" id="estado" name="estado">
                                    <option value="">Todos</option>
                                    <option value="abierta" <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'abierta') ? 'selected' : ''; ?>>Abierta</option>
                                    <option value="cerrada" <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'cerrada') ? 'selected' : ''; ?>>Cerrada</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i>Filtrar
                                </button>
                                <a href="caja_historial.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo me-1"></i>Limpiar
                                </a>

                                <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                    <span class="ms-2 text-muted">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Mostrando resultados filtrados
                                        </small>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Estadísticas rápidas -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])) ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Cortes</div>
                                        <div class="metric-value text-primary"><?php echo count($cajas_data); ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-cash-register fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                                <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Filtrado</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'abierta') ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Cajas Abiertas</div>
                                        <div class="metric-value text-warning"><?php echo $cajas_abiertas; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-lock-open fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                                <?php if (isset($_GET['estado']) && $_GET['estado'] == 'abierta'): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Filtrado por abiertas</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card h-100 <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'cerrada') ? 'filter-active' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Cajas Cerradas</div>
                                        <div class="metric-value text-success"><?php echo count($cajas_data) - $cajas_abiertas; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-lock fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                                <?php if (isset($_GET['estado']) && $_GET['estado'] == 'cerrada'): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Filtrado por cerradas</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Mi Caja</div>
                                        <div class="metric-value text-info"><?php echo $mi_caja_abierta ? 'Abierta' : 'Cerrada'; ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user fa-2x text-info opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de historial -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Registros de Cortes de Caja
                            <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                <small class="text-muted ms-2">(resultados filtrados)</small>
                            <?php endif; ?>
                        </h5>
                        <span class="badge bg-primary"><?php echo count($cajas_data); ?> registros</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($cajas_data) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Usuario</th>
                                            <th>Apertura</th>
                                            <th>Cierre</th>
                                            <th>Ventas Total</th>
                                            <th>Diferencia</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cajas_data as $caja): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo date('d/m/Y', strtotime($caja['fecha_apertura'])); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($caja['fecha_apertura'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($caja['usuario_nombre']); ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($caja['sucursal_nombre']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-success">$<?php echo number_format($caja['monto_apertura'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($caja['monto_cierre']): ?>
                                                        <span class="fw-bold text-primary">$<?php echo number_format($caja['monto_cierre'], 2); ?></span>
                                                        <br>
                                                        <small class="text-muted"><?php echo $caja['fecha_cierre'] ? date('H:i', strtotime($caja['fecha_cierre'])) : '-'; ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-bold">$<?php echo number_format($caja['total_ventas'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($caja['diferencia']): ?>
                                                        <span class="badge bg-<?php
                                                                                if ($caja['diferencia'] > 0) echo 'success';
                                                                                elseif ($caja['diferencia'] < 0) echo 'danger';
                                                                                else echo 'secondary';
                                                                                ?>">
                                                            $<?php echo number_format($caja['diferencia'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $caja['estado'] == 'abierta' ? 'success' : 'secondary'; ?>">
                                                        <i class="fas fa-<?php echo $caja['estado'] == 'abierta' ? 'lock-open' : 'lock'; ?> me-1"></i>
                                                        <?php echo ucfirst($caja['estado']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="caja_resumen.php?id=<?php echo $caja['id']; ?>"
                                                            class="btn btn-outline-primary"
                                                            title="Ver resumen detallado">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if ($caja['estado'] == 'abierta' && $caja['usuario_id'] == $_SESSION['usuario_id']): ?>
                                                            <a href="caja_cierre.php"
                                                                class="btn btn-outline-warning"
                                                                title="Cerrar caja">
                                                                <i class="fas fa-lock"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-cash-register fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">
                                    <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                        No hay registros que coincidan con los filtros
                                    <?php else: ?>
                                        No hay registros de caja
                                    <?php endif; ?>
                                </h5>
                                <p class="text-muted mb-4">
                                    <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                        Intenta ajustar los criterios de búsqueda.
                                    <?php else: ?>
                                        No se han encontrado cortes de caja en el sistema.
                                    <?php endif; ?>
                                </p>
                                <?php if (isset($_GET['fecha_desde']) || isset($_GET['fecha_hasta']) || isset($_GET['usuario']) || isset($_GET['estado'])): ?>
                                    <a href="caja_historial.php" class="btn btn-outline-primary">
                                        <i class="fas fa-undo me-2"></i>Ver todos los registros
                                    </a>
                                <?php else: ?>
                                    <a href="caja_apertura.php" class="btn btn-primary">
                                        <i class="fas fa-lock-open me-2"></i>Abrir Primera Caja
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Indicador de swipe para nuevos usuarios -->
    <?php if (!isset($_COOKIE['swipe_hint_seen']) && !isset($_SESSION['swipe_hint_seen'])): ?>
        <div class="swipe-hint d-md-none">
            <i class="fas fa-arrows-left-right me-2"></i>Desliza para abrir/cerrar menú
        </div>
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            const mainContent = document.querySelector('main');

            // Variables para control de swipe
            let touchStartX = 0;
            let touchEndX = 0;
            let touchStartY = 0;
            let touchEndY = 0;
            let isSwiping = false;
            let swipeThreshold = 50; // Mínimo de píxeles para considerar swipe
            let verticalThreshold = 30; // Umbral vertical para evitar swipes accidentales

            // Función para mostrar/ocultar sidebar
            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';

                // Ocultar indicador de swipe si está visible
                const swipeHint = document.querySelector('.swipe-hint');
                if (swipeHint) {
                    swipeHint.style.display = 'none';
                    // Guardar en cookie para no mostrar de nuevo
                    document.cookie = "swipe_hint_seen=true; max-age=86400; path=/";
                    <?php $_SESSION['swipe_hint_seen'] = true; ?>
                }
            }

            // Event listeners básicos
            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarBackdrop.addEventListener('click', toggleSidebar);

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        toggleSidebar();
                    }
                });
            });

            // Función para detectar swipe
            function handleSwipe() {
                const distanceX = touchEndX - touchStartX;
                const distanceY = Math.abs(touchEndY - touchStartY);

                // Solo procesar si es principalmente horizontal y en móvil
                if (window.innerWidth >= 768) return false;

                // Solo procesar si es principalmente horizontal
                if (Math.abs(distanceX) > distanceY && distanceY < verticalThreshold) {
                    // Swipe de derecha a izquierda (cerrar sidebar)
                    if (distanceX < -swipeThreshold && sidebar.classList.contains('show')) {
                        toggleSidebar();
                        return true;
                    }
                    // Swipe de izquierda a derecha (abrir sidebar)
                    else if (distanceX > swipeThreshold && !sidebar.classList.contains('show')) {
                        toggleSidebar();
                        return true;
                    }
                }
                return false;
            }

            // Event listeners para swipe en todo el documento
            document.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
                touchStartY = e.changedTouches[0].screenY;
                isSwiping = true;
            }, {
                passive: true
            });

            document.addEventListener('touchmove', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;

                // Prevenir scroll vertical durante swipe horizontal
                const distanceX = Math.abs(touchEndX - touchStartX);
                const distanceY = Math.abs(touchEndY - touchStartY);

                if (distanceX > distanceY && distanceX > 10) {
                    e.preventDefault();
                }
            }, {
                passive: false
            });

            document.addEventListener('touchend', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;

                handleSwipe();
                isSwiping = false;

                // Remover clases de feedback
                document.body.classList.remove('swipe-right', 'swipe-left');
            }, {
                passive: true
            });

            // Event listener para cancelar swipe
            document.addEventListener('touchcancel', function() {
                isSwiping = false;
                document.body.classList.remove('swipe-right', 'swipe-left');
            }, {
                passive: true
            });

            // Swipe específico en el sidebar para cerrar
            sidebar.addEventListener('touchstart', function(e) {
                touchStartX = e.touches[0].clientX;
            }, {
                passive: true
            });

            // Configuración de fechas
            const today = new Date().toISOString().split('T')[0];
            const fechaDesdeInput = document.getElementById('fecha_desde');
            const fechaHastaInput = document.getElementById('fecha_hasta');

            if (fechaDesdeInput) fechaDesdeInput.max = today;
            if (fechaHastaInput) fechaHastaInput.max = today;

            // Validación de fechas
            if (fechaDesdeInput) {
                fechaDesdeInput.addEventListener('change', function() {
                    if (this.value && fechaHastaInput.value && this.value > fechaHastaInput.value) {
                        fechaHastaInput.value = this.value;
                    }
                });
            }

            if (fechaHastaInput) {
                fechaHastaInput.addEventListener('change', function() {
                    if (this.value && fechaDesdeInput.value && this.value < fechaDesdeInput.value) {
                        fechaDesdeInput.value = this.value;
                    }
                });
            }

            // Mejoras visuales para las métricas
            const metricValues = document.querySelectorAll('.metric-value');
            metricValues.forEach(metric => {
                metric.style.fontSize = '1.8rem';
                metric.style.fontWeight = '700';
            });

            const metricLabels = document.querySelectorAll('.metric-label');
            metricLabels.forEach(label => {
                label.style.fontSize = '0.875rem';
                label.style.color = '#6c757d';
                label.style.textTransform = 'uppercase';
                label.style.letterSpacing = '0.5px';
            });

            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                if (!card.classList.contains('filter-active')) {
                    card.style.borderLeft = '4px solid var(--primary-color)';
                }
            });

            // Prevenir scroll del body cuando el sidebar está abierto
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        if (sidebar.classList.contains('show')) {
                            document.body.style.overflow = 'hidden';
                        } else {
                            document.body.style.overflow = '';
                        }
                    }
                });
            });

            observer.observe(sidebar, {
                attributes: true
            });

            // Detectar cambios en el tamaño de la ventana
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768 && sidebar.classList.contains('show')) {
                    toggleSidebar();
                }
            });

            // Feedback visual durante el swipe
            document.addEventListener('touchmove', function(e) {
                if (!isSwiping || window.innerWidth >= 768) return;

                const touch = e.touches[0];
                const distanceX = touch.screenX - touchStartX;

                // Solo mostrar feedback si es un swipe horizontal significativo
                if (Math.abs(distanceX) > 10) {
                    // Agregar clase al body para feedback visual
                    if (distanceX > 0 && !sidebar.classList.contains('show')) {
                        document.body.classList.add('swipe-right');
                        document.body.classList.remove('swipe-left');
                    } else if (distanceX < 0 && sidebar.classList.contains('show')) {
                        document.body.classList.add('swipe-left');
                        document.body.classList.remove('swipe-right');
                    }
                }
            }, {
                passive: true
            });

            // Ocultar indicador de swipe después de 5 segundos
            const swipeHint = document.querySelector('.swipe-hint');
            if (swipeHint) {
                setTimeout(function() {
                    swipeHint.style.display = 'none';
                    // Guardar en cookie para no mostrar de nuevo
                    document.cookie = "swipe_hint_seen=true; max-age=86400; path=/";
                    <?php $_SESSION['swipe_hint_seen'] = true; ?>
                }, 5000);
            }

            // Asegurar que el sidebar se cierre al tocar fuera en móvil
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 768 &&
                    sidebar.classList.contains('show') &&
                    !sidebar.contains(e.target) &&
                    !sidebarToggle.contains(e.target)) {
                    toggleSidebar();
                }
            });

            // Mejorar accesibilidad del sidebar
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                    toggleSidebar();
                }
            });

            // Inicializar tooltips de Bootstrap
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>

</html>