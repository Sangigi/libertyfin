<?php

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Verificar que sucursal_id esté definido
if (!isset($_SESSION['sucursal_id']) || !isset($_SESSION['usuario_id'])) {
    die("Error: Datos de sesión incompletos. Por favor, inicie sesión nuevamente.");
}

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

    // Obtener información de la empresa para el header
    $sql_config = "SELECT nombre_empresa,logo  FROM sistema_config LIMIT 1";
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

    // Obtener caja abierta actual
    $sql_caja = "SELECT c.*, u.nombre as usuario_nombre, s.nombre as sucursal_nombre 
                 FROM caja c 
                 JOIN usuarios u ON c.usuario_id = u.id 
                 JOIN sucursales s ON c.sucursal_id = s.id 
                 WHERE c.usuario_id = ? AND c.sucursal_id = ? AND c.estado = 'abierta'";
    $stmt = $conn->prepare($sql_caja);
    $stmt->bind_param("ii", $_SESSION['usuario_id'], $_SESSION['sucursal_id']);
    $stmt->execute();
    $caja_actual = $stmt->get_result()->fetch_assoc();

    if (!$caja_actual) {
        // Si no hay caja abierta, redirigir a apertura
        $_SESSION['error_message'] = "No hay caja abierta para cerrar. Primero debe abrir una caja.";
        header("Location: caja_apertura.php");
        exit();
    }

    $caja_id = $caja_actual['id'];

    // Calcular totales de ventas por método de pago PARA ESTA CAJA ESPECÍFICA
    $sql_ventas = "SELECT 
                    metodo_pago,
                    COUNT(*) as cantidad,
                    SUM(total) as total
                   FROM ventas 
                   WHERE caja_id = ? 
                   AND estado = 'completada'
                   GROUP BY metodo_pago";
    $stmt = $conn->prepare($sql_ventas);
    $stmt->bind_param("i", $caja_id);
    $stmt->execute();
    $ventas_result = $stmt->get_result();

    $ventas_efectivo = 0;
    $ventas_tarjeta = 0;
    $ventas_transferencia = 0;
    $total_ventas = 0;
    $total_cantidad = 0;

    while ($venta = $ventas_result->fetch_assoc()) {
        $monto_venta = $venta['total'] ?? 0;
        $cantidad_venta = $venta['cantidad'] ?? 0;

        switch ($venta['metodo_pago']) {
            case 'efectivo':
                $ventas_efectivo = $monto_venta;
                break;
            case 'tarjeta':
                $ventas_tarjeta = $monto_venta;
                break;
            case 'transferencia':
                $ventas_transferencia = $monto_venta;
                break;
        }
        $total_ventas += $monto_venta;
        $total_cantidad += $cantidad_venta;
    }

    // Calcular otros movimientos (ingresos/egresos)
    $sql_movimientos = "SELECT 
                        tipo,
                        SUM(monto) as total
                       FROM movimientos_caja 
                       WHERE caja_id = ? 
                       AND referencia_tipo != 'venta'
                       GROUP BY tipo";
    $stmt = $conn->prepare($sql_movimientos);
    $stmt->bind_param("i", $caja_id);
    $stmt->execute();
    $movimientos_result = $stmt->get_result();

    $otros_ingresos = 0;
    $otros_egresos = 0;

    while ($movimiento = $movimientos_result->fetch_assoc()) {
        $monto = $movimiento['total'] ?? 0;
        if ($movimiento['tipo'] == 'ingreso') {
            $otros_ingresos = $monto;
        } elseif ($movimiento['tipo'] == 'egreso') {
            $otros_egresos = $monto;
        }
    }

    // Calcular totales
    $total_ingresos_efectivo = $ventas_efectivo + $otros_ingresos;
    $total_egresos_efectivo = $otros_egresos;

    // Monto esperado = (Apertura + Ventas en Efectivo + Otros Ingresos) - Otros Egresos
    $monto_esperado = $caja_actual['monto_apertura'] + $total_ingresos_efectivo - $total_egresos_efectivo;

    // Para debug
    error_log("=== CÁLCULOS CIERRE CAJA ===");
    error_log("Caja ID: " . $caja_id);
    error_log("Monto Apertura: " . $caja_actual['monto_apertura']);
    error_log("Ventas Efectivo: " . $ventas_efectivo);
    error_log("Otros Ingresos: " . $otros_ingresos);
    error_log("Otros Egresos: " . $otros_egresos);
    error_log("Monto Esperado: " . $monto_esperado);
    error_log("Total Ventas: " . $total_ventas);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Procesar cierre de caja
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto_cierre = floatval($_POST['monto_cierre']);
    $observaciones = trim($_POST['observaciones']);

    $diferencia = $monto_cierre - $monto_esperado;

    // Actualizar caja - CORREGIDO
    $sql_update = "UPDATE caja 
                   SET fecha_cierre = NOW(),
                       monto_cierre = ?,
                       monto_esperado = ?,
                       diferencia = ?,
                       ventas_efectivo = ?,
                       ventas_tarjeta = ?,
                       ventas_transferencia = ?,
                       total_ventas = ?,
                       otros_ingresos = ?,
                       otros_egresos = ?,
                       observaciones = ?,
                       estado = 'cerrada'
                   WHERE id = ?";

    $stmt = $conn->prepare($sql_update);

    if (!$stmt) {
        $error = "Error al preparar consulta: " . $conn->error;
    } else {
        // CORRECCIÓN: 9 decimales (d) + 1 string (s) + 1 integer (i) = 11 parámetros
        // Cambiado de "ddddddddddsi" a "dddddddddsi" (11 caracteres en lugar de 12)
        $stmt->bind_param(
            "dddddddddsi",  // 11 caracteres: 9 'd' + 1 's' + 1 'i'
            $monto_cierre,          // decimal
            $monto_esperado,        // decimal
            $diferencia,            // decimal
            $ventas_efectivo,       // decimal
            $ventas_tarjeta,        // decimal
            $ventas_transferencia,  // decimal
            $total_ventas,          // decimal
            $otros_ingresos,        // decimal
            $otros_egresos,         // decimal
            $observaciones,         // string
            $caja_id                // integer
        );

        if ($stmt->execute()) {
            // Limpiar sesión de caja
            unset($_SESSION['caja_actual_id']);
            unset($_SESSION['caja_actual']);

            $_SESSION['success_message'] = "Caja cerrada correctamente";

            // Redirigir al resumen con el ID correcto
            header("Location: caja_resumen.php?id=" . $caja_id);
            exit();
        } else {
            $error = "Error al cerrar la caja: " . $stmt->error;
            error_log("Error cierre caja: " . $stmt->error);
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre de Caja - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .cierre-container {
            max-width: 600px;
            margin: 30px auto;
        }

        .resumen-card {
            border-left: 4px solid #28a745;
        }

        .money-input {
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .money-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
        }

        .diferencia {
            font-size: 1.3rem;
            font-weight: bold;
        }

        .diferencia.positiva {
            color: #28a745;
        }

        .diferencia.negativa {
            color: #dc3545;
        }

        .diferencia.cero {
            color: #6c757d;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            border: none;
            color: white;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(241, 196, 15, 0.3);
            color: white;
        }

        .info-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-left: 4px solid var(--primary-color);
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 600;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-value.efectivo {
            color: #28a745;
        }

        .stat-value.tarjeta {
            color: #17a2b8;
        }

        .stat-value.transferencia {
            color: #6f42c1;
        }

        .input-group-text {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: bold;
        }
    </style>
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <!-- Mostrar logo en base64 -->
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <!-- Mostrar logo por ruta de archivo (fallback) -->
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php else: ?>
                    <!-- Mostrar icono por defecto -->
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto align-items-center">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                </span>
                <span class="badge bg-light text-dark me-3">
                    <i class="fas fa-store me-1"></i>Sucursal ID: <?php echo $_SESSION['sucursal_id']; ?>
                </span>
                <a href="caja.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Volver a Caja
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="cierre-container">
            <div class="card shadow">
                <div class="card-header bg-warning text-dark">
                    <h4 class="mb-0"><i class="fas fa-lock me-2"></i>Cierre de Caja</h4>
                </div>
                <div class="card-body p-4">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message'];
                                                                    unset($_SESSION['success_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error_message'];
                                                                            unset($_SESSION['error_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Información del Turno -->
                    <div class="card mb-4 info-card">
                        <div class="card-body">
                            <h5 class="card-title text-primary mb-3">
                                <i class="fas fa-info-circle me-2"></i>Información del Turno
                            </h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Usuario:</strong> <?php echo htmlspecialchars($caja_actual['usuario_nombre']); ?></p>
                                    <p class="mb-2"><strong>Sucursal:</strong> <?php echo htmlspecialchars($caja_actual['sucursal_nombre']); ?></p>
                                    <p class="mb-2"><strong>Apertura:</strong> <?php echo date('d/m/Y H:i', strtotime($caja_actual['fecha_apertura'])); ?></p>
                                    <p class="mb-0"><strong>ID Caja:</strong> <?php echo $caja_actual['id']; ?></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Monto Apertura:</strong></p>
                                    <h3 class="text-success fw-bold">$<?php echo number_format($caja_actual['monto_apertura'], 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Resumen del Día -->
                    <div class="card mb-4 resumen-card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Resumen del Día</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <!-- Ventas por Método de Pago -->
                                <div class="col-md-4">
                                    <div class="stat-card">
                                        <div class="stat-label">Ventas Efectivo</div>
                                        <div class="stat-value efectivo">$<?php echo number_format($ventas_efectivo, 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="stat-card">
                                        <div class="stat-label">Ventas Tarjeta</div>
                                        <div class="stat-value tarjeta">$<?php echo number_format($ventas_tarjeta, 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="stat-card">
                                        <div class="stat-label">Ventas Transferencia</div>
                                        <div class="stat-value transferencia">$<?php echo number_format($ventas_transferencia, 2); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <!-- Otros Movimientos -->
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-label">Otros Ingresos</div>
                                        <div class="stat-value text-success">$<?php echo number_format($otros_ingresos, 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-label">Otros Egresos</div>
                                        <div class="stat-value text-danger">$<?php echo number_format($otros_egresos, 2); ?></div>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-label">Total Ventas</div>
                                        <div class="stat-value text-primary">$<?php echo number_format($total_ventas, 2); ?></div>
                                        <small class="text-muted"><?php echo $total_cantidad; ?> transacciones</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card bg-light">
                                        <div class="stat-label">Efectivo Esperado en Caja</div>
                                        <div class="stat-value text-primary fw-bold">$<?php echo number_format($monto_esperado, 2); ?></div>
                                        <small class="text-muted">(Apertura + Ventas Efectivo + Otros Ingresos - Otros Egresos)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario de Cierre -->
                    <form method="POST" id="formCierre">
                        <div class="mb-4">
                            <label for="monto_cierre" class="form-label fw-bold">
                                <i class="fas fa-money-bill-wave me-2"></i>Monto Real en Efectivo *
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">$</span>
                                <input type="number"
                                    class="form-control money-input"
                                    id="monto_cierre"
                                    name="monto_cierre"
                                    step="0.01"
                                    min="0"
                                    required
                                    value="<?php echo number_format($monto_esperado, 2, '.', ''); ?>"
                                    placeholder="0.00">
                            </div>
                            <div id="diferencia" class="mt-3 diferencia cero">
                                <strong>Diferencia:</strong> $<span id="diferencia-valor">0.00</span>
                            </div>
                            <div class="form-text text-muted mt-2">
                                <i class="fas fa-info-circle me-1"></i>Conteo físico del efectivo en caja
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="observaciones" class="form-label fw-bold">
                                <i class="fas fa-sticky-note me-2"></i>Observaciones (Opcional)
                            </label>
                            <textarea class="form-control"
                                id="observaciones"
                                name="observaciones"
                                rows="3"
                                placeholder="Observaciones sobre el cierre de caja, notas importantes, etc..."
                                style="resize: none;"></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg py-3" id="btnCerrarCaja">
                                <i class="fas fa-lock me-2"></i>Cerrar Caja y Finalizar Turno
                            </button>
                            <a href="caja.php" class="btn btn-outline-secondary">Cancelar y Volver a Caja</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tarjeta de Información de Cálculo -->
            <div class="card mt-4 info-card">
                <div class="card-body">
                    <h6 class="fw-bold text-primary mb-3">
                        <i class="fas fa-calculator me-2"></i>Detalle del Cálculo
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled small">
                                <li class="mb-2">
                                    <span class="text-success">+</span> <strong>Monto Apertura:</strong> $<?php echo number_format($caja_actual['monto_apertura'], 2); ?>
                                </li>
                                <li class="mb-2">
                                    <span class="text-success">+</span> <strong>Ventas Efectivo:</strong> $<?php echo number_format($ventas_efectivo, 2); ?>
                                </li>
                                <li class="mb-2">
                                    <span class="text-success">+</span> <strong>Otros Ingresos:</strong> $<?php echo number_format($otros_ingresos, 2); ?>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled small">
                                <li class="mb-2">
                                    <span class="text-danger">-</span> <strong>Otros Egresos:</strong> $<?php echo number_format($otros_egresos, 2); ?>
                                </li>
                                <li class="mb-2">
                                    <span class="text-primary">=</span> <strong>Efectivo Esperado:</strong> $<?php echo number_format($monto_esperado, 2); ?>
                                </li>
                                <li class="mb-2">
                                    <span class="text-warning">±</span> <strong>Diferencia:</strong> Calculada automáticamente
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tarjeta de Estado del Sistema -->
            <div class="card mt-3">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-sync-alt me-1"></i>Estado del sistema:
                            <span class="badge bg-success">Conectado</span>
                        </small>
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i><?php echo date('d/m/Y H:i:s'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const montoCierre = document.getElementById('monto_cierre');
            const diferenciaElement = document.getElementById('diferencia');
            const diferenciaValor = document.getElementById('diferencia-valor');
            const montoEsperado = <?php echo $monto_esperado; ?>;
            const formCierre = document.getElementById('formCierre');
            const btnCerrarCaja = document.getElementById('btnCerrarCaja');

            function calcularDiferencia() {
                const montoReal = parseFloat(montoCierre.value) || 0;
                const diferencia = montoReal - montoEsperado;

                diferenciaValor.textContent = Math.abs(diferencia).toFixed(2);

                // Cambiar color según la diferencia
                diferenciaElement.className = 'mt-3 diferencia ';
                if (diferencia > 0) {
                    diferenciaElement.classList.add('positiva');
                    diferenciaElement.innerHTML = '<strong>Diferencia (SOBRA):</strong> $' + Math.abs(diferencia).toFixed(2);
                } else if (diferencia < 0) {
                    diferenciaElement.classList.add('negativa');
                    diferenciaElement.innerHTML = '<strong>Diferencia (FALTA):</strong> $' + Math.abs(diferencia).toFixed(2);
                } else {
                    diferenciaElement.classList.add('cero');
                    diferenciaElement.innerHTML = '<strong>Diferencia:</strong> $0.00';
                }
            }

            montoCierre.addEventListener('input', calcularDiferencia);
            montoCierre.addEventListener('change', function() {
                if (this.value !== '') {
                    this.value = parseFloat(this.value).toFixed(2);
                    calcularDiferencia();
                }
            });

            // Enfocar y seleccionar el input al cargar
            montoCierre.focus();
            montoCierre.select();
            calcularDiferencia();

            // Validación del formulario
            if (formCierre) {
                formCierre.addEventListener('submit', function(e) {
                    const montoReal = parseFloat(montoCierre.value);
                    const diferencia = montoReal - montoEsperado;

                    if (isNaN(montoReal)) {
                        e.preventDefault();
                        alert('Por favor ingrese un monto válido');
                        montoCierre.focus();
                        return false;
                    }

                    if (montoReal < 0) {
                        e.preventDefault();
                        alert('El monto de cierre no puede ser negativo');
                        montoCierre.focus();
                        return false;
                    }

                    // Mostrar confirmación con detalles
                    let confirmMessage = `¿Está seguro de cerrar la caja?\n\n`;
                    confirmMessage += `Monto Esperado: $${montoEsperado.toFixed(2)}\n`;
                    confirmMessage += `Monto Real: $${montoReal.toFixed(2)}\n`;
                    confirmMessage += `Diferencia: $${Math.abs(diferencia).toFixed(2)} ${diferencia > 0 ? '(SOBRA)' : diferencia < 0 ? '(FALTA)' : ''}`;

                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                        return false;
                    }

                    // Mostrar loading
                    if (btnCerrarCaja) {
                        btnCerrarCaja.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Cerrando caja...';
                        btnCerrarCaja.disabled = true;
                    }

                    return true;
                });
            }

            // Prevenir envío con Enter en el textarea
            const observaciones = document.getElementById('observaciones');
            if (observaciones) {
                observaciones.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                    }
                });
            }

            // Validar que el monto sea numérico
            montoCierre.addEventListener('keypress', function(e) {
                const charCode = e.which ? e.which : e.keyCode;
                const value = this.value;

                // Permitir números, punto decimal y teclas de control
                if (charCode != 46 && charCode > 31 && (charCode < 48 || charCode > 57)) {
                    e.preventDefault();
                    return false;
                }

                // Solo permitir un punto decimal
                if (charCode == 46 && value.indexOf('.') > -1) {
                    e.preventDefault();
                    return false;
                }

                // Limitar a 2 decimales
                if (value.indexOf('.') > -1 && (value.split('.')[1].length >= 2) && charCode != 8) {
                    e.preventDefault();
                    return false;
                }
            });
        });
    </script>
</body>

</html>