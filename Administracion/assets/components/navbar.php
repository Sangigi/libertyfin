<?php
// =============================================
// COMPONENTE NAVBAR (REUTILIZABLE)
// =============================================

// Variables esperadas:
// $usuario_nombre - Nombre del usuario logueado
// $usuario_rol - Rol del usuario (opcional)

$usuario_nombre = $usuario_nombre ?? 'Administrador';
$usuario_rol = $usuario_rol ?? 'admin';
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <!-- Botón hamburguesa para móvil (controla el sidebar) -->
        <button class="navbar-toggler sidebar-toggle" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Logo y nombre -->
        <a class="navbar-brand" href="dashboard.php">
            <img src="../images/LibertyfinBlanco.png" alt="LibertyFin">
            <span class="brand-text d-none d-sm-inline">Panel de Administración</span>
        </a>

        <!-- Menú de usuario en la derecha -->
        <div class="navbar-nav ms-auto">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="navbar-user">
                        <span class="user-avatar">
                            <i class="fas fa-user-circle"></i>
                        </span>
                        <span class="user-name"><?php echo htmlspecialchars($usuario_nombre); ?></span>
                    </span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <span class="dropdown-item-text text-muted small">
                            <i class="fas fa-user-tag me-2"></i>
                            <?php echo ucfirst($usuario_rol); ?>
                        </span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <!-- <li>
                        <a class="dropdown-item" href="perfil.php">
                            <i class="fas fa-user-cog me-2"></i>Mi Perfil
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="configuracion.php">
                            <i class="fas fa-cogs me-2"></i>Configuración
                        </a>
                    </li> -->
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
                        </a>
                    </li>
                </ul>
            </li>
        </div>
    </div>
</nav>

<!-- Backdrop para cerrar sidebar en móvil -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>