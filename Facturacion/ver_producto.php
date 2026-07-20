<?php
// Archivo: ver_producto.php
require '../vendor/autoload.php';
use Facturapi\Facturapi;

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$api_key = "sk_test_3NGWy62UprCyUHgvXmJmmqwt3xmvHeALdjyotVP8U1";
$product_id = $_GET['id'] ?? null;

if (!$product_id) {
    header("Location: listar_productos.php");
    exit();
}

$facturapi = new Facturapi($api_key);
$producto = null;
$mensaje = '';
$tipo_mensaje = '';

try {
    $producto = $facturapi->Products->retrieve($product_id);
} catch (Exception $e) {
    $mensaje = '❌ Error: ' . $e->getMessage();
    $tipo_mensaje = 'danger';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalles del Producto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container mt-4">
        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        
        <?php if ($producto): ?>
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">
                        <i class="fas fa-box me-2"></i> Detalles del Producto
                    </h4>
                    <a href="listar_productos.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left me-2"></i> Volver
                    </a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Columna izquierda: Información básica -->
                        <div class="col-md-6">
                            <h5 class="border-bottom pb-2 mb-3">Información Básica</h5>
                            <div class="row mb-2">
                                <div class="col-4"><strong>Descripción:</strong></div>
                                <div class="col-8"><?php echo htmlspecialchars($producto->description); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4"><strong>SKU:</strong></div>
                                <div class="col-8"><?php echo htmlspecialchars($producto->sku ?? 'N/A'); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4"><strong>ID:</strong></div>
                                <div class="col-8"><code><?php echo htmlspecialchars($producto->id); ?></code></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4"><strong>Estado:</strong></div>
                                <div class="col-8">
                                    <span class="badge bg-<?php echo $producto->status === 'active' ? 'success' : 'danger'; ?>">
                                        <?php echo $producto->status === 'active' ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Columna derecha: Información fiscal -->
                        <div class="col-md-6">
                            <h5 class="border-bottom pb-2 mb-3">Información Fiscal</h5>
                            <div class="row mb-2">
                                <div class="col-4"><strong>Clave SAT:</strong></div>
                                <div class="col-8">
                                    <span class="badge bg-info"><?php echo htmlspecialchars($producto->product_key); ?></span>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4"><strong>Precio:</strong></div>
                                <div class="col-8">
                                    <strong>$<?php echo number_format($producto->price, 2); ?></strong>
                                    <?php if ($producto->tax_included): ?>
                                        <span class="badge bg-success ms-2">IVA incluído</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning ms-2">+ IVA</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4"><strong>Unidad:</strong></div>
                                <div class="col-8">
                                    <?php echo htmlspecialchars($producto->unit_name ?? 'Pieza'); ?>
                                    (<?php echo htmlspecialchars($producto->unit_key); ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Impuestos -->
                    <?php if (!empty($producto->taxes)): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h5 class="border-bottom pb-2 mb-3">Impuestos</h5>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Tasa</th>
                                            <th>Factor</th>
                                            <th>Retención</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($producto->taxes as $tax): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($tax->type); ?></td>
                                                <td><?php echo ($tax->rate * 100) . '%'; ?></td>
                                                <td><?php echo htmlspecialchars($tax->factor); ?></td>
                                                <td><?php echo $tax->withholding ? 'Sí' : 'No'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Fechas -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Fechas de Creación/Actualización</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-muted">Creado:</small><br>
                                            <?php 
                                            if (isset($producto->created_at)) {
                                                echo date('d/m/Y H:i:s', $producto->created_at);
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Actualizado:</small><br>
                                            <?php 
                                            if (isset($producto->updated_at)) {
                                                echo date('d/m/Y H:i:s', $producto->updated_at);
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Botones de acción -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <a href="listar_productos.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Volver a la lista
                                </a>
                                <div>
                                    <a href="editar_producto.php?id=<?php echo urlencode($producto->id); ?>" 
                                       class="btn btn-primary me-2">
                                        <i class="fas fa-edit me-2"></i> Editar
                                    </a>
                                    <a href="eliminar_producto.php?id=<?php echo urlencode($producto->id); ?>" 
                                       class="btn btn-danger"
                                       onclick="return confirm('¿Eliminar este producto?')">
                                        <i class="fas fa-trash me-2"></i> Eliminar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>