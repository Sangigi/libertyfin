<?php
// includes/navbar.php

// Valor por defecto por si alguna página no define $empresa_plan antes de
// incluir este navbar (evita warnings de variable indefinida -- ej.
// cuentas_por_cobrar.php no lo define).
$empresa_plan = $empresa_plan ?? 'prueba';

// Alias: valores legados del enum `plan` en la BD -> claves del catálogo de
// planes vigente (mismo mapeo que ya usan cuenta.php y dashboard.php), para
// que el badge muestre el nombre de plan actual y no uno viejo como
// "Premium" que ya no existe en la tabla de precios.
$plan_alias_navbar = [
    'prueba'      => null,
    'basico'      => 'basico',
    'starter'     => 'basico',
    'emprendedor' => 'profesional',
    'premium'     => 'empresarial',
    'profesional' => 'profesional',
    'empresarial' => 'empresarial',
    'plus'        => 'plus',
];
$plan_nombres_navbar = [
    'basico'      => 'Básico',
    'profesional' => 'Profesional',
    'empresarial' => 'Empresarial',
    'plus'        => 'Empresarial Plus',
];
$plan_key_navbar = $plan_alias_navbar[$empresa_plan] ?? null;
$empresa_plan_nombre_navbar = $empresa_plan === 'prueba'
    ? 'Prueba'
    : ($plan_nombres_navbar[$plan_key_navbar] ?? ucfirst($empresa_plan));
$empresa_plan_color_navbar = match ($plan_key_navbar) {
    'plus', 'empresarial' => 'primary',
    'profesional' => 'success',
    'basico' => 'warning',
    default => ($empresa_plan === 'prueba' ? 'info' : 'secondary'),
};
?>
<style>
/* Mantener pulsado y mover el mouse sobre un enlace del sidebar o el del
   nombre de cuenta inicia el arrastre nativo del navegador para ese <a>
   (el mismo gesto que sirve para crear un marcador). En Microsoft Edge esa
   operación a veces se queda "pegada" cuando el enlace vive dentro de un
   contenedor con position: sticky -- como el sidebar -- y el resto de la
   página deja de responder hasta soltar o presionar Escape. Como estos
   enlaces nunca necesitan arrastrarse, se desactiva ese arrastre aquí. */
.navbar, .sidebar {
    -webkit-user-drag: none;
}
.navbar a, .sidebar a, .navbar img, .sidebar img {
    -webkit-user-drag: none;
    user-select: none;
}
</style>
<script>
// Refuerzo: por si el navegador ignora la regla CSS de arriba, se cancela
// explícitamente cualquier "dragstart" nativo sobre la barra superior y el
// sidebar -- justo el clic sostenido + arrastre que congelaba Edge.
document.addEventListener('dragstart', function (e) {
    if (e.target.closest('.navbar, #sidebar')) {
        e.preventDefault();
    }
});
</script>
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <!-- Botón hamburguesa para móvil -->
        <button class="sidebar-toggle" type="button" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>

        <a class="navbar-brand d-flex align-items-center" href="dashboard.php" draggable="false">
            <?php if ($logo_src_base64): ?>
                <img src="<?php echo $logo_src_base64; ?>"
                     alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                     class="me-2" draggable="false">
                <span>
                    <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    <span class="badge bg-<?php echo $empresa_plan_color_navbar; ?> ms-2" style="font-size: 0.5rem;">
                        <?php echo htmlspecialchars($empresa_plan_nombre_navbar); ?>
                    </span>
                </span>
            <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                     alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                     class="me-2" draggable="false"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                <i class="fas fa-cash-register me-2" style="display: none;"></i>
                <span>
                    <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    <span class="badge bg-<?php echo $empresa_plan_color_navbar; ?> ms-2" style="font-size: 0.5rem;">
                        <?php echo htmlspecialchars($empresa_plan_nombre_navbar); ?>
                    </span>
                </span>
            <?php else: ?>
                <i class="fas fa-cash-register me-2"></i>
                <span>
                    <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    <span class="badge bg-<?php echo $empresa_plan_color_navbar; ?> ms-2" style="font-size: 0.5rem;">
                        <?php echo htmlspecialchars($empresa_plan_nombre_navbar); ?>
                    </span>
                </span>
            <?php endif; ?>
        </a>

        <div class="navbar-nav ms-auto">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" draggable="false">
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
                    <li><a class="dropdown-item" href="cuenta.php">
                            <i class="fas fa-id-card me-2"></i>Mi Cuenta
                        </a></li>
                    <li><a class="dropdown-item" href="planes.php">
                            <i class="fas fa-crown me-2"></i>Planes
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                </ul>
            </li>
        </div>
    </div>
</nav>