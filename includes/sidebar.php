<?php
// includes/sidebar.php

// ------------------------------------------------------------
// 1. MAPEO DE ARCHIVOS PHP A SLUGS (para cuando se accede directamente al .php)
// ------------------------------------------------------------
$page_map = [
    'dashboard.php'          => 'Inicio',
    'usuarios.php'           => 'Usuarios',
    'caja.php'               => 'Caja',
    'productos.php'          => 'Productos',
    'clientes.php'           => 'Clientes',
    'ventas_lista.php'       => 'Ventas',
    'caja_historial.php'     => 'CortesCaja',
    'gastos.php'             => 'Gastos',
    'proveedores.php'        => 'Proveedores',
    'sucursales.php'         => 'Sucursales',
    'Facturacion/inicio.php' => 'Facturacion',
    'reportes.php'           => 'Reportes',
    'configuracion.php'      => 'Configuracion',
    'comisiones_config.php'  => 'comisiones_config', // O 'Comisiones' si prefieres
    'EmidaServicios/inicio.php' => 'EmidaServicios'
];

// ------------------------------------------------------------
// 2. DETERMINAR LA PÁGINA ACTUAL (slug)
// ------------------------------------------------------------
// Opción A: Desde la URL amigable (ej. /Productos, /CortesCaja)
$request_uri = $_SERVER['REQUEST_URI'];
$path = trim(parse_url($request_uri, PHP_URL_PATH), '/');

if (empty($path)) {
    // Página de inicio (raíz)
    $current_page = 'Inicio';
} else {
    // Si la ruta coincide directamente con un slug (ej. "Productos") lo usamos
    $current_page = $path;

    // Pero si la URL es un archivo PHP (ej. "productos.php") o ruta con subcarpeta,
    // usamos el mapeo para obtener el slug correspondiente
    $script_name = basename($_SERVER['SCRIPT_NAME']); // ej. "productos.php"
    if (isset($page_map[$script_name])) {
        $current_page = $page_map[$script_name];
    } else {
        // También intentamos con la ruta relativa completa (por si hay subcarpetas)
        $relative_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $_SERVER['SCRIPT_FILENAME']);
        $relative_path = ltrim($relative_path, '/');
        if (isset($page_map[$relative_path])) {
            $current_page = $page_map[$relative_path];
        }
    }
}

// ------------------------------------------------------------
// 3. VARIABLES QUE VIENEN DEL CONTEXTO (definidas en cada página o en el index)
//    - $_SESSION['usuario_rol']
//    - $_SESSION['sucursal_id']
//    - $empresa_plan
//    - $timbres_disponibles
//    - $terminal_emida
//    - $notification_status
//    (Estas variables deben estar disponibles porque el sidebar se incluye en páginas que ya las definen)
// ------------------------------------------------------------
?>

<div class="col-md-3 col-lg-2 sidebar" id="sidebar">
    <div class="position-sticky pt-3">
        <ul class="nav flex-column">
            <!-- INICIO -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Inicio') ? 'active' : ''; ?>" href="Inicio" draggable="false">
                    <i class="fas fa-tachometer-alt"></i>
                    Inicio
                </a>
            </li>

            <!-- USUARIOS (solo admin) -->
            <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page === 'Usuarios') ? 'active' : ''; ?>" href="Usuarios" draggable="false">
                        <i class="fas fa-user-cog"></i>
                        Usuarios
                    </a>
                </li>
            <?php endif; ?>

            <!-- CAJA -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Caja') ? 'active' : ''; ?>" href="Caja" draggable="false">
                    <i class="fas fa-cash-register"></i>
                    Caja
                </a>
            </li>

            <!-- PRODUCTOS -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Productos') ? 'active' : ''; ?>" href="Productos" draggable="false">
                    <i class="fas fa-boxes"></i>
                    Productos
                </a>
            </li>

            <!-- CLIENTES -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Clientes') ? 'active' : ''; ?>" href="Clientes" draggable="false">
                    <i class="fas fa-users"></i>
                    Clientes
                </a>
            </li>

            <!-- VENTAS -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Ventas') ? 'active' : ''; ?>" href="Ventas" draggable="false">
                    <i class="fas fa-receipt"></i>
                    Ventas
                </a>
            </li>

            <!-- CORTES DE CAJA -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'CortesCaja') ? 'active' : ''; ?>" href="CortesCaja" draggable="false">
                    <i class="fas fa-cash-register"></i>
                    Cortes de Caja
                </a>
            </li>

            <!-- GASTOS -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Gastos') ? 'active' : ''; ?>" href="Gastos" draggable="false">
                    <i class="fas fa-money-bill-wave"></i>
                    Gastos
                </a>
            </li>

            <!-- PROVEEDORES -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Proveedores') ? 'active' : ''; ?>" href="Proveedores" draggable="false">
                    <i class="fas fa-truck"></i>
                    Proveedores
                </a>
            </li>

<!-- SUCURSALES (solo admin y si el plan no es básico) -->
<?php if ($_SESSION['usuario_rol'] === 'admin' && $empresa_plan !== 'basico'): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($current_page === 'Sucursales') ? 'active' : ''; ?>" href="Sucursales" draggable="false">
            <i class="fas fa-store"></i>
            Sucursales
        </a>
    </li>
<?php endif; ?>

            <!-- FACTURACIÓN (solo admin, sucursal 1, timbres disponibles y plan premium) -->
            <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles > 0 && $empresa_plan === 'premium') : ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page === 'Facturacion') ? 'active' : ''; ?>" href="Facturacion" draggable="false">
                        <i class="fas fa-file-invoice-dollar"></i>
                        Facturación
                    </a>
                </li>
            <?php endif; ?>

            <!-- REPORTES -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'Reportes') ? 'active' : ''; ?>" href="Reportes" draggable="false">
                    <i class="fas fa-chart-bar"></i>
                    Reportes
                </a>
            </li>

            <!-- EMIDA SERVICIOS (si existe terminal) -->
            <?php if (!empty($terminal_emida)): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page === 'EmidaServicios') ? 'active' : ''; ?>" href="EmidaServicios" draggable="false">
                        <img src="../images/emidalogo.png" alt="" draggable="false" style="width: 20px; height: 20px; margin-right: 10px; object-fit: contain;">
                        Emida Servicios
                        <?php if ($notification_status && isset($notification_status['notification_status']) && !$notification_status['notification_status']['success']): ?>
                            <span class="badge bg-warning ms-2" style="font-size: 0.65rem;" title="Notificaciones no configuradas">
                                <i class="fas fa-exclamation-triangle"></i>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>

            <!-- COMISIONES (solo admin) -->
            <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page === 'comisiones_config') ? 'active' : ''; ?>" href="comisiones_config.php" draggable="false">
                        <i class="fas fa-percentage"></i>
                        Comisiones
                    </a>
                </li>
            <?php endif; ?>

            <!-- CONFIGURACIÓN (solo admin) -->
            <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page === 'Configuracion') ? 'active' : ''; ?>" href="Configuracion" draggable="false">
                        <i class="fas fa-cogs"></i>
                        Configuración
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>