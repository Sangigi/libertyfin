<?php
// Archivo: listar_productos.php
require '../vendor/autoload.php';
use Facturapi\Facturapi;

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Obtener API key y organization_id (debes tener estos en tu sesión o base de datos)
$api_key = "sk_test_3NGWy62UprCyUHgvXmJmmqwt3xmvHeALdjyotVP8U1"; // Tu API key
$organization_id = "org_id"; // Debes obtener esto de tu base de datos

// Inicializar Facturapi
$facturapi = new Facturapi($api_key);

// Parámetros de búsqueda/paginación
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$sku_filter = isset($_GET['sku']) ? trim($_GET['sku']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = isset($_GET['limit']) ? min(max(1, intval($_GET['limit'])), 100) : 50;

// Variables para los resultados
$productos = [];
$total_productos = 0;
$total_paginas = 0;
$mensaje = '';
$tipo_mensaje = '';

// Obtener productos de Facturapi
try {
    // Preparar parámetros para la API
    $params = [
        'page' => $page,
        'limit' => $limit
    ];
    
    // Agregar parámetros de búsqueda si existen
    if (!empty($search_query)) {
        $params['q'] = $search_query;
    }
    
    if (!empty($sku_filter)) {
        $params['sku'] = $sku_filter;
    }
    
    // Hacer la petición a la API
    $response = $facturapi->Products->all($params);
    
    // Procesar respuesta
    if (isset($response->data)) {
        $productos = $response->data;
        $total_productos = $response->total ?? count($productos);
        $total_paginas = ceil($total_productos / $limit);
    }
    
} catch (Exception $e) {
    $mensaje = '❌ Error al obtener productos: ' . $e->getMessage();
    $tipo_mensaje = 'danger';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Productos - Facturapi</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            border-radius: 10px 10px 0 0 !important;
            padding: 15px 20px;
        }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table th {
            background-color: #f1f5f9;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .badge {
            font-size: 0.75em;
            padding: 5px 10px;
        }
        
        .status-active {
            background-color: #d1f7c4;
            color: #2e7d32;
        }
        
        .status-inactive {
            background-color: #ffeaea;
            color: #c62828;
        }
        
        .search-box {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .pagination {
            justify-content: center;
            margin-top: 20px;
        }
        
        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .page-link {
            color: var(--primary-color);
        }
        
        .page-link:hover {
            color: var(--secondary-color);
        }
        
        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
        
        .actions-dropdown {
            min-width: 150px;
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stats-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(39, 174, 96, 0.25);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.9rem;
            }
            
            .actions-dropdown {
                min-width: auto;
            }
            
            .export-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Mensajes -->
        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Encabezado -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-boxes me-2"></i> Productos en Facturapi
                        </h4>
                        <a href="crear_producto.php" class="btn btn-light">
                            <i class="fas fa-plus me-2"></i> Nuevo Producto
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $total_productos; ?></div>
                                    <div class="stats-label">Total Productos</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo count(array_filter($productos, fn($p) => $p->status === 'active')); ?></div>
                                    <div class="stats-label">Activos</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo count(array_filter($productos, fn($p) => $p->status === 'deleted')); ?></div>
                                    <div class="stats-label">Eliminados</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $total_paginas; ?></div>
                                    <div class="stats-label">Páginas</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Búsqueda y Filtros -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="search-box">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-6">
                            <label for="q" class="form-label">
                                <i class="fas fa-search me-1"></i> Buscar producto
                            </label>
                            <input type="text" 
                                   class="form-control" 
                                   id="q" 
                                   name="q" 
                                   placeholder="Buscar por descripción o SKU..."
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="sku" class="form-label">
                                <i class="fas fa-barcode me-1"></i> SKU específico
                            </label>
                            <input type="text" 
                                   class="form-control" 
                                   id="sku" 
                                   name="sku" 
                                   placeholder="Ingresa SKU exacto"
                                   value="<?php echo htmlspecialchars($sku_filter); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label for="limit" class="form-label">
                                <i class="fas fa-list-ol me-1"></i> Por página
                            </label>
                            <select class="form-select" id="limit" name="limit">
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i> Aplicar Filtros
                                </button>
                                
                                <div class="export-buttons">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1, 'limit' => 100])); ?>" 
                                       class="btn btn-outline-secondary">
                                        <i class="fas fa-eye me-2"></i> Ver todos
                                    </a>
                                    
                                    <?php if (!empty($search_query) || !empty($sku_filter)): ?>
                                        <a href="?" class="btn btn-outline-danger">
                                            <i class="fas fa-times me-2"></i> Limpiar filtros
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Tabla de Productos -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body p-0">
                        <?php if (!empty($productos)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th width="50">#</th>
                                            <th>Descripción</th>
                                            <th>Clave SAT</th>
                                            <th>SKU</th>
                                            <th>Precio</th>
                                            <th>Unidad</th>
                                            <th>Impuestos</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $index => $producto): ?>
                                            <tr>
                                                <td><?php echo (($page - 1) * $limit) + $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (isset($producto->image_url) && !empty($producto->image_url)): ?>
                                                            <img src="<?php echo htmlspecialchars($producto->image_url); ?>" 
                                                                 alt="<?php echo htmlspecialchars($producto->description); ?>" 
                                                                 class="product-image me-3">
                                                        <?php else: ?>
                                                            <div class="product-image me-3">
                                                                <i class="fas fa-box"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($producto->description); ?></strong>
                                                            <?php if (isset($producto->id)): ?>
                                                                <div class="text-muted small">
                                                                    ID: <?php echo substr($producto->id, 0, 8); ?>...
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info" 
                                                          data-bs-toggle="tooltip" 
                                                          title="Clave SAT: <?php echo htmlspecialchars($producto->product_key); ?>">
                                                        <?php echo htmlspecialchars($producto->product_key); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($producto->sku)): ?>
                                                        <code><?php echo htmlspecialchars($producto->sku); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong>$<?php echo number_format($producto->price, 2); ?></strong>
                                                    <?php if ($producto->tax_included): ?>
                                                        <div class="text-success small">IVA incluído</div>
                                                    <?php else: ?>
                                                        <div class="text-warning small">+ IVA</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    echo htmlspecialchars($producto->unit_name ?? 'Pieza');
                                                    if (isset($producto->unit_key) && $producto->unit_key != 'H87'): 
                                                    ?>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($producto->unit_key); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    if (!empty($producto->taxes)) {
                                                        foreach ($producto->taxes as $tax) {
                                                            echo '<span class="badge bg-secondary me-1">';
                                                            echo $tax->type . ' ' . ($tax->rate * 100) . '%';
                                                            echo '</span>';
                                                        }
                                                    } else {
                                                        echo '<span class="badge bg-success">Exento</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($producto->status === 'active'): ?>
                                                        <span class="badge status-active">
                                                            <i class="fas fa-check-circle me-1"></i> Activo
                                                        </span>
                                                    <?php elseif ($producto->status === 'deleted'): ?>
                                                        <span class="badge status-inactive">
                                                            <i class="fas fa-times-circle me-1"></i> Eliminado
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">
                                                            <?php echo htmlspecialchars($producto->status); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" 
                                                                type="button" 
                                                                data-bs-toggle="dropdown"
                                                                aria-expanded="false">
                                                            <i class="fas fa-cog"></i>
                                                        </button>
                                                        <ul class="dropdown-menu actions-dropdown">
                                                            <li>
                                                                <a class="dropdown-item" 
                                                                   href="ver_producto.php?id=<?php echo urlencode($producto->id); ?>">
                                                                    <i class="fas fa-eye me-2"></i> Ver Detalles
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item" 
                                                                   href="editar_producto.php?id=<?php echo urlencode($producto->id); ?>">
                                                                    <i class="fas fa-edit me-2"></i> Editar
                                                                </a>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <a class="dropdown-item text-danger" 
                                                                   href="eliminar_producto.php?id=<?php echo urlencode($producto->id); ?>"
                                                                   onclick="return confirm('¿Estás seguro de eliminar este producto?')">
                                                                    <i class="fas fa-trash me-2"></i> Eliminar
                                                                </a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Paginación -->
                            <?php if ($total_paginas > 1): ?>
                                <nav aria-label="Paginación de productos" class="p-3 border-top">
                                    <ul class="pagination">
                                        <!-- Primera página -->
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Página anterior -->
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Páginas numeradas -->
                                        <?php 
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_paginas, $page + 2);
                                        
                                        for ($p = $start_page; $p <= $end_page; $p++):
                                        ?>
                                            <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>">
                                                    <?php echo $p; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <!-- Página siguiente -->
                                        <li class="page-item <?php echo $page >= $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_paginas, $page + 1)])); ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Última página -->
                                        <li class="page-item <?php echo $page >= $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_paginas])); ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                    
                                    <div class="text-center text-muted small mt-2">
                                        Página <?php echo $page; ?> de <?php echo $total_paginas; ?> 
                                        • Mostrando <?php echo count($productos); ?> de <?php echo $total_productos; ?> productos
                                    </div>
                                </nav>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                                <h4 class="text-muted">No se encontraron productos</h4>
                                <p class="text-muted mb-4">
                                    <?php if (!empty($search_query) || !empty($sku_filter)): ?>
                                        No hay resultados para tu búsqueda. Intenta con otros términos.
                                    <?php else: ?>
                                        No hay productos registrados en Facturapi.
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($search_query) || !empty($sku_filter)): ?>
                                    <a href="?" class="btn btn-primary">
                                        <i class="fas fa-list me-2"></i> Ver todos los productos
                                    </a>
                                <?php else: ?>
                                    <a href="crear_producto.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i> Crear primer producto
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Exportar datos -->
        <?php if (!empty($productos)): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-download me-2"></i> Exportar Datos</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <a href="exportar_productos.php?format=csv&<?php echo http_build_query($_GET); ?>" 
                                       class="btn btn-outline-success w-100">
                                        <i class="fas fa-file-csv me-2"></i> Exportar a CSV
                                    </a>
                                </div>
                                <div class="col-md-4">
                                    <a href="exportar_productos.php?format=excel&<?php echo http_build_query($_GET); ?>" 
                                       class="btn btn-outline-primary w-100">
                                        <i class="fas fa-file-excel me-2"></i> Exportar a Excel
                                    </a>
                                </div>
                                <div class="col-md-4">
                                    <a href="exportar_productos.php?format=pdf&<?php echo http_build_query($_GET); ?>" 
                                       class="btn btn-outline-danger w-100">
                                        <i class="fas fa-file-pdf me-2"></i> Exportar a PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicializar tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Auto-enfoque en campo de búsqueda
            document.getElementById('q').focus();
            
            // Confirmación antes de eliminar
            document.querySelectorAll('.delete-product').forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('¿Estás seguro de eliminar este producto?\n\nEsta acción no se puede deshacer.')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>