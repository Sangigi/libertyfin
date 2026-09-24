<?php
// suscripcion_expirada.php 
session_start();

// Verificar si el usuario está logueado (permitir cualquier rol para ver esta página)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Si el usuario NO tiene suscripción expirada, redirigir al dashboard
if (!isset($_SESSION['suscripcion_expirada']) || $_SESSION['suscripcion_expirada'] !== true) {
    header("Location: Inicio");
    exit();
}

// Cargar configuración y funciones de base de datos
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env_loader.php';

// OBTENER EL PLAN DE LA EMPRESA DESDE LA BASE DE DATOS PRINCIPAL
$conn_main = getDBConnection();

// Valores por defecto
$empresa_plan = "prueba";

if ($conn_main) {
    $sql_empresa = "SELECT plan FROM empresas WHERE id = ?";
    $stmt_empresa = $conn_main->prepare($sql_empresa);
    $stmt_empresa->execute([$_SESSION['empresa_id']]);
    $result_empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);

    if ($result_empresa) {
        $empresa_plan = $result_empresa['plan'];
    }
    $stmt_empresa = null;
    $conn_main = null;
}

// Guardar el plan en la sesión
$_SESSION['empresa_plan'] = $empresa_plan;

// ============================================================
// VARIABLES PARA EL LOGO Y COLORES
// ============================================================
$logo_empresa = null;
$logo_src_base64 = null;
$color_primario = '#27ae60';
$color_secundario = '#2ecc71';
$nombre_empresa = $_SESSION['empresa_nombre'] ?? 'Mi Empresa';

// Conectar a la base de datos de la empresa para obtener configuración
try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);

    // Obtener configuración actual
    $sql_config = "SELECT * FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $config = $result_config->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        // Insertar configuración por defecto si no existe
        $sql_insert = "INSERT INTO sistema_config (nombre_empresa) VALUES ('Mi Empresa')";
        $conn->exec($sql_insert);
        $config = [
            'nombre_empresa' => 'Mi Empresa',
            'rfc' => '',
            'telefono' => '',
            'email' => '',
            'direccion' => '',
            'logo' => '',
            'iva' => '16.00',
            'moneda' => 'MXN',
            'color_primario' => '#27ae60',
            'color_secundario' => '#2ecc71'
        ];
        // Recargar la configuración
        $result_config = $conn->query($sql_config);
        $config = $result_config->fetch(PDO::FETCH_ASSOC);
    }

    $nombre_empresa = $config['nombre_empresa'] ?? $_SESSION['empresa_nombre'] ?? 'Mi Empresa';
    $color_primario = $config['color_primario'] ?? '#27ae60';
    $color_secundario = $config['color_secundario'] ?? '#2ecc71';

    // Cargar logo si existe
    if (!empty($config['logo'])) {
        $empresa_logo = $config['logo'];
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
} catch (Exception $e) {
    // Si falla la conexión a la BD de la empresa, continuar con valores por defecto
    error_log("Error cargando configuración en suscripcion_expirada.php: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.ico" type="image/x-icon">
    <title>Activar suscripción - <?php echo htmlspecialchars($nombre_empresa); ?></title>
    
    <!-- Color de marca por empresa -->
    <style>
        :root {
            --primary-color: <?php echo $color_primario; ?>;
            --secondary-color: <?php echo $color_secundario; ?>;
        }
        /* Ajustes específicos para esta página */
        main {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 1.5rem 1.75rem !important;
            min-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .activation-container {
            max-width: 720px;
            width: 100%;
            text-align: center;
            padding: 2rem 1rem;
        }
        .activation-header {
            margin-bottom: 2rem;
        }
        .activation-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--lf-ink);
            letter-spacing: -0.5px;
        }
        .activation-header h1 span {
            color: var(--primary-color);
        }
        .activation-header .user-name {
            font-size: 1.1rem;
            color: var(--lf-muted);
            margin-top: 0.25rem;
        }
        .activation-logo {
            max-width: 280px;
            max-height: 90px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin-bottom: 0.75rem;
        }
        @media (max-width: 576px) {
            .activation-logo {
                max-width: 220px;
                max-height: 70px;
            }
        }
        @media (min-width: 1200px) {
            .activation-logo {
                max-width: 320px;
                max-height: 100px;
            }
        }
        .activation-divider {
            width: 60px;
            height: 3px;
            background: var(--primary-color);
            margin: 1.5rem auto;
            border-radius: 4px;
        }
        .activation-message {
            background: var(--lf-surface-2);
            border-radius: var(--lf-r);
            padding: 2rem 1.5rem;
            margin-bottom: 2rem;
            border-left: 5px solid var(--primary-color);
            text-align: left;
        }
        .activation-message h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--lf-ink);
            margin-bottom: 0.75rem;
        }
        .activation-message p {
            color: var(--lf-ink-2);
            line-height: 1.7;
            font-size: 1rem;
        }
        .activation-message .danger-warning {
            color: #dc3545;
            font-weight: 600;
        }
        .activation-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }
        .activation-actions .btn {
            padding: 0.75rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            min-width: 200px;
            transition: all 0.2s ease;
        }
        .btn-primary-custom {
            background: var(--primary-color);
            border: 2px solid var(--primary-color);
            color: white;
        }
        .btn-primary-custom:hover {
            background: transparent;
            color: var(--primary-color);
        }
        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--lf-border);
            color: var(--lf-ink);
        }
        .btn-outline-custom:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        .activation-footer {
            margin-top: 2.5rem;
            font-size: 0.9rem;
            color: var(--lf-muted);
        }
        .activation-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        .activation-footer a:hover {
            text-decoration: underline;
        }
        /* Ajuste para el navbar - se mantiene igual */
        .navbar-brand span .badge {
            font-size: 0.6rem;
            padding: 0.25rem 0.6rem;
        }
    </style>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Tema CRM -->
    <link rel="stylesheet" href="/css/crm-theme.css">
</head>

<body>
    <!-- Navbar (similar al original pero sin elementos extra) -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                         alt="<?php echo htmlspecialchars($nombre_empresa); ?>"
                         class="me-2"
                         style="height: 32px; width: auto; border-radius: 8px; object-fit: contain;">
                    <span>
                        <?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php
                            echo match ($empresa_plan) {
                                'premium' => 'primary',
                                'emprendedor' => 'success',
                                'basico' => 'warning',
                                'prueba' => 'info',
                                default => 'secondary'
                            };
                        ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                         alt="<?php echo htmlspecialchars($nombre_empresa); ?>"
                         class="me-2"
                         style="height: 32px; width: auto; border-radius: 8px; object-fit: contain;"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span>
                        <?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php
                            echo match ($empresa_plan) {
                                'premium' => 'primary',
                                'emprendedor' => 'success',
                                'basico' => 'warning',
                                'prueba' => 'info',
                                default => 'secondary'
                            };
                        ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2" style="font-size: 1.2rem;"></i>
                    <span>
                        <?php echo htmlspecialchars($nombre_empresa); ?>
                        <span class="badge bg-<?php
                            echo match ($empresa_plan) {
                                'premium' => 'primary',
                                'emprendedor' => 'success',
                                'basico' => 'warning',
                                'prueba' => 'info',
                                default => 'secondary'
                            };
                        ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($nombre_empresa); ?></small>
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

    <!-- MAIN CONTENT: Activación de suscripción -->
    <main>
        <div class="activation-container">
            <div class="activation-header">
                <img src="img/logo-libertyfin.png" alt="LibertyFin" class="activation-logo">
                <p class="user-name"><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></p>
            </div>

            <div class="activation-divider"></div>

            <div class="activation-message">
                <h2>Tu periodo de prueba ha concluido</h2>
                <p>
                    Esperamos verte por aquí de nuevo muy pronto. Por favor considera las siguientes opciones para continuar.
                    En caso de no elegir ninguna, tu cuenta será eliminada en <span class="danger-warning">-90 días</span>.
                </p>
                <p class="mt-3 mb-0">
                    Suscríbete a uno de nuestros planes y continúa automatizando tus ventas.
                </p>
            </div>

            <div class="activation-actions">
                <a href="planes.php" class="btn btn-primary-custom">
                    <i class="fas fa-rocket me-2"></i>Contratar un plan
                </a>
                <!-- <button class="btn btn-outline-custom" onclick="noInteresado()">
                    <i class="fas fa-times me-2"></i>No estoy interesado
                </button> -->
            </div>

            <div class="activation-footer">
                ¿Necesitas ayuda para elegir un plan? <a href="#">Contacta a ventas</a>
            </div>
        </div>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function noInteresado() {
            Swal.fire({
                title: '¿Estás seguro?',
                text: "Si no eliges un plan, tu cuenta será eliminada. ¿Deseas continuar?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, no estoy interesado',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Aquí puedes redirigir a una página de cancelación o logout
                    Swal.fire(
                        'Cuenta marcada para eliminación',
                        'Tu cuenta será eliminada en los próximos días. Si cambias de opinión, contacta a soporte.',
                        'info'
                    );
                    // Ejemplo: window.location.href = 'logout.php';
                }
            });
        }
    </script>
</body>

</html>