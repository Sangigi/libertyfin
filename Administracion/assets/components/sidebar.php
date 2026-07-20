<?php
// =============================================
// COMPONENTE SIDEBAR (REUTILIZABLE)
// =============================================

// Variables esperadas:
// $current_page - Página actual para resaltar el link activo

$current_page = basename($_SERVER['PHP_SELF']);

// Función para verificar si un link está activo
function isActive($page, $current_page) {
    return $page === $current_page ? 'active' : '';
}

// Definir los items del menú
$menu_items = [
    'dashboard' => [
        'label' => 'Dashboard',
        'icon' => 'fa-tachometer-alt',
        'page' => 'dashboard.php'
    ],
    'usuarios' => [
        'label' => 'Usuarios Admin',
        'icon' => 'fa-user-cog',
        'page' => 'usuarios.php'
    ],
    'distribuidores' => [
        'label' => 'Distribuidores',
        'icon' => 'fa-users',
        'page' => 'distribuidores.php'
    ],
    'pagos' => [
        'label' => 'Pagos',
        'icon' => 'fa-money-bill-wave',
        'page' => 'pagos.php'
    ]
];
?>

<div class="sidebar" id="sidebar">
    <!-- Botón para cerrar en móvil -->
    <button class="sidebar-close" id="sidebarClose" aria-label="Cerrar sidebar">
        <i class="fas fa-times"></i>
    </button>

    <!-- Navegación -->
    <ul class="nav">
        <?php foreach ($menu_items as $key => $item): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo isActive($item['page'], $current_page); ?>" 
                   href="<?php echo $item['page']; ?>">
                    <i class="fas <?php echo $item['icon']; ?>"></i>
                    <?php echo $item['label']; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Separador con información extra (opcional) -->
    <div class="sidebar-divider"></div>
    <div class="sidebar-divider-text">Sistema v1.0</div>
</div>