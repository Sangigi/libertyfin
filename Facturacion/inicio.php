<?php
// Facturacion/inicio.php
require '../vendor/autoload.php';

use Facturapi\Facturapi;

session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Login");
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

// Variables para Facturapi
$organizacion = null;
$mensaje = '';
$tipo_mensaje = '';
$api_key = "sk_user_MD3D8JvfsNHvtiR65bGokbH34FQyXo7GU65w85z1qA";
$organization_id = '';
$test_api_key = null;

// Arreglo de regímenes fiscales del SAT
$regimenes_fiscales = [
    '601' => 'General de Ley Personas Morales',
    '603' => 'Personas Morales con Fines no Lucrativos',
    '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
    '606' => 'Arrendamiento',
    '607' => 'Régimen de Enajenación o Adquisición de Bienes',
    '608' => 'Demás ingresos',
    '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
    '611' => 'Ingresos por Dividendos (socios y accionistas)',
    '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
    '614' => 'Ingresos por intereses',
    '615' => 'Régimen de los ingresos por obtención de premios',
    '616' => 'Sin obligaciones fiscales',
    '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
    '621' => 'Incorporación Fiscal',
    '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
    '623' => 'Opcional para Grupos de Sociedades',
    '624' => 'Coordinados',
    '625' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
    '626' => 'Régimen Simplificado de Confianza'
];

// Variables para productos
$productos_facturapi = [];
$total_productos = 0;
$productos_error = null;
$result = null;

// Conectar a la base de datos de la empresa
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener configuración de colores, información de la empresa Y EL LOGO
    $sql_config = "SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo FROM sistema_config LIMIT 1";
    $result_config = $conn->query($sql_config);
    $empresa_info = $result_config->fetch_assoc();

    // Función segura para obtener valores de configuración
    function getConfigValue($config, $key, $default = '')
    {
        return isset($config[$key]) ? $config[$key] : $default;
    }

    // Obtener estadísticas básicas
    $sql_estadisticas = "
        SELECT 
            (SELECT COUNT(*) FROM productos WHERE activo = TRUE) as total_productos,
            (SELECT COUNT(*) FROM clientes WHERE activo = TRUE) as total_clientes,
            (SELECT COUNT(*) FROM usuarios WHERE activo = TRUE) as total_usuarios,
            (SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()) as ventas_hoy,
            (SELECT COALESCE(SUM(total), 0) FROM ventas WHERE DATE(fecha) = CURDATE()) as ingresos_hoy
    ";
    $result_estadisticas = $conn->query($sql_estadisticas);
    $estadisticas = $result_estadisticas->fetch_assoc();

    // OBTENER EL PLAN Y DATOS DE FACTURAPI DESDE LA BASE DE DATOS PRINCIPAL
    $servername_main = "libertyfin.com.mx";
    $username_main = "juanc141_alexis";
    $password_main = "Alexis1997";
    $dbname_main = "juanc141_ventas";

    $conn_main = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

    // Obtener el plan y datos de Facturapi de la empresa
    $empresa_plan = "prueba";
    if ($conn_main) {
        $sql_plan = "SELECT plan, facturapi_organization_id FROM empresas WHERE id = ?";
        $stmt_plan = $conn_main->prepare($sql_plan);
        $stmt_plan->bind_param("i", $_SESSION['empresa_id']);
        $stmt_plan->execute();
        $result_plan = $stmt_plan->get_result();
        if ($result_plan->num_rows > 0) {
            $plan_data = $result_plan->fetch_assoc();
            $empresa_plan = $plan_data['plan'];
            $organization_id = $plan_data['facturapi_organization_id'];
        }
        $stmt_plan->close();
        $conn_main->close();
    }

    $_SESSION['empresa_plan'] = $empresa_plan;

    // CARGAR DATOS DE LA ORGANIZACIÓN SI TENEMOS CREDENCIALES
    if (!empty($api_key) && !empty($organization_id)) {
        try {
            $facturapi = new Facturapi($api_key);
            $organizacion = $facturapi->Organizations->retrieve($organization_id);
            
            try {
                $test_api_key = $facturapi->Organizations->getTestApiKey($organization_id);
                $_SESSION['test_api_key'] = $test_api_key;
            } catch (Exception $e) {
                error_log("Error al obtener API Key de prueba: " . $e->getMessage());
            }
        } catch (Exception $e) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $mensaje = '❌ Error al cargar datos: ' . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    }
    
    // PROCESAMIENTO PARA FACTURAPI - DATOS FISCALES
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
        if ($_POST['accion'] === 'guardar') {
            try {
                if (empty($api_key)) {
                    throw new Exception('La API Key de Facturapi no está configurada');
                }

                if (!preg_match('/^sk_user_/', $api_key)) {
                    throw new Exception('Para editar datos fiscales necesitas una API Key de usuario (sk_user_)');
                }

                if (empty($organization_id)) {
                    throw new Exception('La organización no tiene un ID de Facturapi configurado');
                }

                $facturapi = new Facturapi($api_key);

                $datos_requeridos = ['name', 'legal_name', 'tax_system', 'street', 'exterior', 'neighborhood', 'city', 'state', 'zip'];
                foreach ($datos_requeridos as $campo) {
                    if (empty($_POST[$campo])) {
                        throw new Exception("El campo '{$campo}' es requerido");
                    }
                }

                $datos_fiscales = [
                    'name' => trim($_POST['name']),
                    'legal_name' => trim($_POST['legal_name']),
                    'tax_system' => trim($_POST['tax_system']),
                    'address' => [
                        'street' => trim($_POST['street']),
                        'exterior' => trim($_POST['exterior']),
                        'interior' => trim($_POST['interior'] ?? ''),
                        'zip' => trim($_POST['zip']),
                        'neighborhood' => trim($_POST['neighborhood']),
                        'city' => trim($_POST['city']),
                        'municipality' => trim($_POST['municipality'] ?? trim($_POST['city'])),
                        'state' => trim($_POST['state'])
                    ]
                ];

                if (!empty($_POST['website'])) {
                    $datos_fiscales['website'] = trim($_POST['website']);
                }

                if (!empty($_POST['phone'])) {
                    $datos_fiscales['phone'] = trim($_POST['phone']);
                }

                $support_email = trim($_POST['support_email'] ?? '');
                if (!empty($support_email)) {
                    $datos_fiscales['support_email'] = $support_email;
                }

                if (strlen($datos_fiscales['name']) > 100) {
                    throw new Exception('El nombre comercial no puede tener más de 100 caracteres');
                }

                if (strlen($datos_fiscales['legal_name']) > 100) {
                    throw new Exception('La razón social no puede tener más de 100 caracteres');
                }

                if (strlen($datos_fiscales['tax_system']) !== 3) {
                    throw new Exception('El código de régimen fiscal debe tener exactamente 3 caracteres');
                }

                if (!empty($support_email) && !filter_var($support_email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('El email de soporte no es válido');
                }

                try {
                    $organizacion_actualizada = $facturapi->Organizations->updateLegal($organization_id, $datos_fiscales);
                    $mensaje = '✅ Datos fiscales actualizados correctamente (incluyendo support_email)';
                    $tipo_mensaje = 'success';
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();

                    if (strpos($error_msg, 'support_email') !== false || strpos($error_msg, 'is not allowed') !== false) {
                        unset($datos_fiscales['support_email']);
                        $organizacion_actualizada = $facturapi->Organizations->updateLegal($organization_id, $datos_fiscales);

                        if (!empty($support_email)) {
                            try {
                                $currentSettings = $facturapi->Organizations->retrieve($organization_id);
                                $enabled = false;

                                if (isset($currentSettings->self_invoice) && isset($currentSettings->self_invoice->enabled)) {
                                    $enabled = $currentSettings->self_invoice->enabled;
                                }

                                $selfInvoiceData = [
                                    'enabled' => $enabled,
                                    'support_email' => $support_email
                                ];

                                $facturapi->Organizations->updateLegal($organization_id, $selfInvoiceData);
                                $mensaje = '✅ Datos fiscales actualizados correctamente. Email de soporte actualizado por separado.';
                            } catch (Exception $e2) {
                                $mensaje = '✅ Datos fiscales actualizados, pero no se pudo actualizar el email de soporte: ' . $e2->getMessage();
                                $tipo_mensaje = 'warning';
                            }
                        } else {
                            $mensaje = '✅ Datos fiscales actualizados correctamente';
                            $tipo_mensaje = 'success';
                        }
                    } else {
                        throw new Exception("Error al actualizar: " . $error_msg);
                    }
                }

                $organizacion = $facturapi->Organizations->retrieve($organization_id);
            } catch (Exception $e) {
                $mensaje = '❌ Error: ' . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    }
    
    // Cargar productos SOLO cuando estamos en la pestaña de productos
    if (isset($_GET['tab']) && $_GET['tab'] === 'productos') {
        class ProductManager {
            private $facturapi;
            
            public function __construct($test_api_key) {
                $this->facturapi = new Facturapi($test_api_key);
            }
            
            public function listarProductos($params = []) {
                try {
                    $defaultParams = [
                        "page" => 1,
                        "limit" => 50
                    ];
                    
                    $searchParams = array_merge($defaultParams, $params);
                    $searchResult = $this->facturapi->Products->all($searchParams);
                    
                    return [
                        'success' => true,
                        'data' => $searchResult
                    ];
                    
                } catch (Exception $e) {
                    return [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
        
        $test_api_key_working = $test_api_key;
        $productManager = new ProductManager($test_api_key_working);
        
        $params = [];
        $searchType = $_GET['search_type'] ?? 'all';
        $currentPage = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 12);
        
        switch ($searchType) {
            case 'text':
                if (!empty($_GET['q'])) {
                    $params['q'] = $_GET['q'];
                }
                break;
            case 'sku':
                if (!empty($_GET['sku'])) {
                    $params['sku'] = $_GET['sku'];
                }
                break;
        }
        
        $params['page'] = $currentPage;
        $params['limit'] = $limit;
        
        $result = $productManager->listarProductos($params);
        
        if ($result['success'] && isset($result['data']->data)) {
            $productos_facturapi = $result['data']->data;
            
            // CORRECCIÓN: Manejar diferentes estructuras de respuesta
            if (isset($result['data']->total)) {
                $total_productos = (int)$result['data']->total;
            } elseif (isset($result['data']->total_count)) {
                $total_productos = (int)$result['data']->total_count;
            } elseif (is_array($productos_facturapi)) {
                $total_productos = count($productos_facturapi);
            } else {
                $total_productos = 0;
            }
            
            // Calcular totalPages si no existe
            $total_pages = 1;
            if (isset($result['data']->totalPages)) {
                $total_pages = (int)$result['data']->totalPages;
            } elseif (isset($result['data']->total_pages)) {
                $total_pages = (int)$result['data']->total_pages;
            } elseif (isset($result['data']->last_page)) {
                $total_pages = (int)$result['data']->last_page;
            } elseif ($total_productos > 0) {
                $total_pages = (int)ceil($total_productos / $limit);
            }
            
            // Obtener página actual
            $current_page = (int)($result['data']->page ?? $currentPage);
            
            // Añadir propiedades faltantes al objeto para uso en la vista
            $result['data']->totalPages = $total_pages;
            $result['data']->total = $total_productos;
            $result['data']->page = $current_page;
            
        } else if (!$result['success']) {
            $productos_error = $result['error'];
        }
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Función para obtener logo en base64
$logo_src_base64 = null;
if (!empty($empresa_info['logo'])) {
    $logo_path = '';
    $rutas_posibles = [
        $empresa_info['logo'],
        '../' . $empresa_info['logo'],
        '../../' . $empresa_info['logo'],
        'admin/' . $empresa_info['logo'],
        '../admin/' . $empresa_info['logo'],
        'logos/' . $empresa_info['logo'],
        'img/' . $empresa_info['logo'],
        'images/' . $empresa_info['logo'],
        'assets/' . $empresa_info['logo'],
        'uploads/' . $empresa_info['logo'],
        '../logos/' . $empresa_info['logo'],
        '../img/' . $empresa_info['logo'],
        '../images/' . $empresa_info['logo'],
        '../assets/' . $empresa_info['logo'],
        '../uploads/' . $empresa_info['logo']
    ];

    foreach ($rutas_posibles as $ruta) {
        if (file_exists($ruta) && is_file($ruta)) {
            $logo_path = $ruta;
            break;
        }
    }

    if (!empty($logo_path) && file_exists($logo_path)) {
        $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
        $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        if (in_array($extension, $extensiones_validas)) {
            $logo_data = base64_encode(file_get_contents($logo_path));
            $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Personalizado -->
    <link rel="stylesheet" href="css/facturacion.css">
    
    <style>
        :root {
            --primary-color: <?php echo getConfigValue($empresa_info, 'color_primario', '#27ae60'); ?>;
            --secondary-color: <?php echo getConfigValue($empresa_info, 'color_secundario', '#2ecc71'); ?>;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="../dashboard.php">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
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
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
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
                    <ul class="dropdown-menu">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="../dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Inicio
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="../usuarios.php">
                                    <i class="fas fa-user-cog"></i> Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="../caja.php">
                                <i class="fas fa-cash-register"></i> Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../productos.php">
                                <i class="fas fa-boxes"></i> Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../clientes.php">
                                <i class="fas fa-users"></i> Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../ventas_lista.php">
                                <i class="fas fa-receipt"></i> Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../caja_historial.php">
                                <i class="fas fa-cash-register"></i> Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../proveedores.php">
                                <i class="fas fa-truck"></i> Proveedores
                            </a>
                        </li>

                        <?php if ($empresa_plan !== 'basico' && $_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="../sucursales.php">
                                    <i class="fas fa-store"></i> Sucursales
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <div class="nav-link text-muted" style="opacity: 0.6; cursor: not-allowed;">
                                    <i class="fas fa-store"></i> Sucursales
                                    <small class="d-block text-warning mt-1" style="font-size: 0.7rem;">
                                        <i class="fas fa-lock"></i> Solo en planes superiores
                                    </small>
                                </div>
                            </li>
                        <?php endif; ?>

                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1): ?>
                            <li class="nav-item">
                                <a class="nav-link active" href="#">
                                    <i class="fas fa-file-invoice-dollar"></i> Facturación
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="nav-item">
                            <a class="nav-link" href="../reportes.php">
                                <i class="fas fa-chart-bar"></i> Reportes
                            </a>
                        </li>
                          <?php if ($empresa_plan === 'premium'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="../EmidaServicios/inicio.php">
                                <img src="../images/emidalogo.png" alt="" style="width: 20px; height: 20px; margin-right: 10px; object-fit: contain;">
                                Emida Servicios
                                <?php if ($notification_status && isset($notification_status['notification_status']) && !$notification_status['notification_status']['success']): ?>
                                    <span class="badge bg-warning ms-2" style="font-size: 0.65rem;" title="Notificaciones no configuradas">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                         <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="../configuracion.php">
                                    <i class="fas fa-cogs"></i> Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <nav>
                    <div class="nav nav-tabs" id="nav-tab" role="tablist">
                        <button class="nav-link active" id="nav-home-tab" data-bs-toggle="tab" data-bs-target="#nav-home" type="button" role="tab" aria-selected="true">Datos Fiscales</button>
                        <button class="nav-link" id="nav-profile-tab" data-bs-toggle="tab" data-bs-target="#nav-profile" type="button" role="tab" aria-selected="false">Certificados</button>
                        <button class="nav-link" id="nav-contact-tab" data-bs-toggle="tab" data-bs-target="#nav-contact" type="button" role="tab" aria-selected="false">Personalización</button>
                        <button class="nav-link" id="nav-product-tab" data-bs-toggle="tab" data-bs-target="#nav-product" type="button" role="tab" aria-labelledby="nav-product-tab" aria-selected="false">Productos</button>   
                    </div>
                </nav>
                
                <div class="tab-content" id="nav-tabContent">
                    <!-- Pestaña Datos Fiscales -->
                    <div class="tab-pane fade show active" id="nav-home" role="tabpanel" aria-labelledby="nav-home-tab">
                        <?php if ($mensaje): ?>
                            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                                <?php echo $mensaje; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="formEditarFiscales">
                            <input type="hidden" name="accion" value="guardar">

                            <div class="form-section">
                                <h5><i class="fas fa-signature me-2"></i> Información Básica</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="name" class="form-label required">Nombre Comercial</label>
                                        <input type="text" class="form-control" id="name" name="name" maxlength="100"
                                            value="<?php echo htmlspecialchars($organizacion->legal->name ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="legal_name" class="form-label required">Razón Social</label>
                                        <input type="text" class="form-control" id="legal_name" name="legal_name" maxlength="100"
                                            value="<?php echo htmlspecialchars($organizacion->legal->legal_name ?? ''); ?>" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="tax_system" class="form-label required">Régimen Fiscal</label>
                                        <select class="form-select" id="tax_system" name="tax_system" required>
                                            <option value="">Selecciona un régimen</option>
                                            <?php foreach ($regimenes_fiscales as $codigo => $descripcion): ?>
                                                <option value="<?php echo $codigo; ?>"
                                                    <?php echo (isset($organizacion->legal->tax_system) && $organizacion->legal->tax_system == $codigo) ? 'selected' : ''; ?>>
                                                    <?php echo $codigo . ' - ' . $descripcion; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="phone" class="form-label">Teléfono</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            value="<?php echo htmlspecialchars($organizacion->legal->phone ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="website" class="form-label">Sitio Web</label>
                                        <input type="text" class="form-control" id="website" name="website"
                                            value="<?php echo htmlspecialchars($organizacion->legal->website ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="support_email" class="form-label">Email de Soporte</label>
                                    <input type="email" class="form-control" id="support_email" name="support_email"
                                        placeholder="soporte@empresa.com"
                                        value="<?php
                                            if (isset($organizacion->legal->support_email)) {
                                                echo htmlspecialchars($organizacion->legal->support_email);
                                            } elseif (isset($organizacion->self_invoice->support_email)) {
                                                echo htmlspecialchars($organizacion->self_invoice->support_email);
                                            }
                                        ?>">
                                </div>
                            </div>

                            <div class="form-section">
                                <h5><i class="fas fa-map-marker-alt me-2"></i> Domicilio Fiscal</h5>
                                <div class="address-grid">
                                    <div class="mb-3">
                                        <label for="street" class="form-label required">Calle</label>
                                        <input type="text" class="form-control" id="street" name="street"
                                            value="<?php echo htmlspecialchars($organizacion->legal->address->street ?? ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="exterior" class="form-label required">Número Exterior</label>
                                        <input type="text" class="form-control" id="exterior" name="exterior"
                                            value="<?php echo htmlspecialchars($organizacion->legal->address->exterior ?? ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="interior" class="form-label">Número Interior</label>
                                        <input type="text" class="form-control" id="interior" name="interior"
                                            value="<?php echo htmlspecialchars($organizacion->legal->address->interior ?? ''); ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="neighborhood" class="form-label required">Colonia</label>
                                        <input type="text" class="form-control" id="neighborhood" name="neighborhood"
                                            value="<?php echo htmlspecialchars($organizacion->legal->address->neighborhood ?? ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="city" class="form-label required">Ciudad</label>
                                        <input type="text" class="form-control" id="city" name="city"
                                            value="<?php echo htmlspecialchars($organizacion->legal->address->city ?? ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="municipality" class="form-label">Municipio</label>
                                        <input type="text" class="form-control" id="municipality" name="municipality"
                                            value="<?php echo htmlspecialchars($organizacion->legal->address->municipality ?? $organizacion->legal->address->city ?? ''); ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="state" class="form-label required">Estado</label>
                                        <select class="form-select" id="state" name="state" required>
                                            <option value="">Selecciona un estado</option>
                                            <?php
                                            $estados = ['Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche', 'Chiapas', 'Chihuahua', 'Ciudad de México', 'Coahuila', 'Colima', 'Durango', 'Estado de México', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco', 'Michoacán', 'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro', 'Quintana Roo', 'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz', 'Yucatán', 'Zacatecas'];
                                            $current_state = $organizacion->legal->address->state ?? '';
                                            foreach ($estados as $estado):
                                            ?>
                                                <option value="<?php echo $estado; ?>" <?php echo ($current_state == $estado) ? 'selected' : ''; ?>>
                                                    <?php echo $estado; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="zip" class="form-label required">Código Postal</label>
                                        <input type="text" class="form-control" id="zip" name="zip" maxlength="5"
                                            value="<?php echo htmlspecialchars($organizacion->legal->address->zip ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="note-alert" style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin: 20px 0;">
                                <h6><i class="fas fa-exclamation-triangle me-2"></i> Notas importantes:</h6>
                                <ul class="mb-0">
                                    <li>Algunos campos (como RFC) no son editables una vez configurados</li>
                                    <li>El email de soporte puede requerir actualización por separado</li>
                                    <li>Verifica que los datos sean correctos antes de guardar</li>
                                    <li>Después de guardar, recarga la página para ver los cambios</li>
                                </ul>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <a href="dashboard.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Volver al Inicio
                                </a>
                                <button type="submit" class="btn btn-custom">
                                    <i class="fas fa-save me-2"></i> Guardar Cambios
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Pestaña Certificados -->
                    <div class="tab-pane fade" id="nav-profile" role="tabpanel" aria-labelledby="nav-profile-tab">
                        <?php 
                        $mensaje_cert = isset($_SESSION['cert_mensaje']) ? $_SESSION['cert_mensaje'] : '';
                        $tipo_mensaje_cert = isset($_SESSION['cert_tipo_mensaje']) ? $_SESSION['cert_tipo_mensaje'] : '';
                        
                        if ($mensaje_cert): 
                            unset($_SESSION['cert_mensaje']);
                            unset($_SESSION['cert_tipo_mensaje']);
                        ?>
                            <div class="alert alert-<?php echo $tipo_mensaje_cert; ?> alert-dismissible fade show" role="alert">
                                <?php echo $mensaje_cert; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-lg-6 mb-4">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> Información del Certificado Actual</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        $cert_info = null;
                                        if (!empty($api_key) && !empty($organization_id)) {
                                            try {
                                                $facturapi = new Facturapi($api_key);
                                                $organizacion_info = $facturapi->Organizations->retrieve($organization_id);
                                                if (isset($organizacion_info->certificate)) {
                                                    $cert_info = $organizacion_info->certificate;
                                                }
                                            } catch (Exception $e) {
                                                // Error
                                            }
                                        }
                                        ?>
                                        
                                        <?php if ($cert_info): ?>
                                            <div class="current-data">
                                                <div class="data-row">
                                                    <span class="data-label">RFC del CSD:</span>
                                                    <span class="data-value"><?php echo htmlspecialchars($cert_info->rfc ?? 'No disponible'); ?></span>
                                                </div>
                                                <div class="data-row">
                                                    <span class="data-label">Número de Serie:</span>
                                                    <span class="data-value"><?php echo htmlspecialchars($cert_info->number ?? 'No disponible'); ?></span>
                                                </div>
                                                <div class="data-row">
                                                    <span class="data-label">Válido desde:</span>
                                                    <span class="data-value"><?php 
                                                        if (isset($cert_info->valid_from)) {
                                                            echo date('d/m/Y H:i:s', $cert_info->valid_from);
                                                        } else {
                                                            echo 'No disponible';
                                                        }
                                                    ?></span>
                                                </div>
                                                <div class="data-row">
                                                    <span class="data-label">Válido hasta:</span>
                                                    <span class="data-value"><?php 
                                                        if (isset($cert_info->valid_to)) {
                                                            echo date('d/m/Y H:i:s', $cert_info->valid_to);
                                                        } else {
                                                            echo 'No disponible';
                                                        }
                                                    ?></span>
                                                </div>
                                                <div class="data-row">
                                                    <span class="data-label">Estado:</span>
                                                    <span class="data-value">
                                                        <?php 
                                                        if (isset($cert_info->valid_to)) {
                                                            $valido_hasta = $cert_info->valid_to;
                                                            $hoy = time();
                                                            if ($valido_hasta > $hoy) {
                                                                $dias_restantes = floor(($valido_hasta - $hoy) / (60 * 60 * 24));
                                                                if ($dias_restantes <= 30) {
                                                                    echo '<span class="cert-status cert-expiring"><i class="fas fa-exclamation-triangle"></i> Vence en ' . $dias_restantes . ' días</span>';
                                                                } else {
                                                                    echo '<span class="cert-status cert-valid"><i class="fas fa-check-circle"></i> Vigente</span>';
                                                                }
                                                            } else {
                                                                echo '<span class="cert-status cert-expired"><i class="fas fa-times-circle"></i> Expirado</span>';
                                                            }
                                                        } else {
                                                            echo 'No disponible';
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                No se ha cargado ningún certificado digital (CSD) para esta organización.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-6 mb-4">
                                <div class="card">
                                    <div class="card-header bg-success text-white">
                                        <h5 class="mb-0"><i class="fas fa-upload me-2"></i> Cargar Nuevo Certificado (CSD)</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="certificado_procesar.php" enctype="multipart/form-data" id="formCertificado">
                                            <input type="hidden" name="accion" value="cargar_certificado">
                                            <input type="hidden" name="organization_id" value="<?php echo htmlspecialchars($organization_id); ?>">
                                            <input type="hidden" name="api_key" value="<?php echo htmlspecialchars($api_key); ?>">
                                            
                                            <div class="mb-4">
                                                <label for="cer_file" class="form-label required">
                                                    <i class="fas fa-file-certificate me-1"></i> Archivo .cer (Certificado)
                                                </label>
                                                <div class="file-upload-area" id="cerDropArea">
                                                    <i class="fas fa-cloud-upload-alt fa-3x text-success mb-3"></i>
                                                    <h5>Arrastra tu archivo .cer aquí</h5>
                                                    <p class="text-muted">o haz clic para seleccionar</p>
                                                    <input type="file" class="form-control d-none" id="cer_file" name="cer_file" accept=".cer,.CER" required>
                                                    <div id="cerFileName" class="mt-2"></div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-4">
                                                <label for="key_file" class="form-label required">
                                                    <i class="fas fa-key me-1"></i> Archivo .key (Llave privada)
                                                </label>
                                                <div class="file-upload-area key-area" id="keyDropArea">
                                                    <i class="fas fa-key fa-3x text-primary mb-3"></i>
                                                    <h5>Arrastra tu archivo .key aquí</h5>
                                                    <p class="text-muted">o haz clic para seleccionar</p>
                                                    <input type="file" class="form-control d-none" id="key_file" name="key_file" accept=".key,.KEY" required>
                                                    <div id="keyFileName" class="mt-2"></div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-4">
                                                <label for="password" class="form-label required">
                                                    <i class="fas fa-lock me-1"></i> Contraseña de la llave privada
                                                </label>
                                                <div class="input-group">
                                                    <input type="password" class="form-control" id="password" name="password" 
                                                        placeholder="Ingresa la contraseña de tu archivo .key" required>
                                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="alert alert-warning">
                                                <h6><i class="fas fa-exclamation-triangle me-2"></i> Importante:</h6>
                                                <ul class="mb-0 small">
                                                    <li>Ambos archivos (.cer y .key) deben ser del mismo CSD</li>
                                                    <li>El certificado debe estar vigente (no expirado)</li>
                                                    <li>La contraseña es sensible a mayúsculas y minúsculas</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between mt-4">
                                                <button type="button" class="btn btn-outline-secondary" id="btnLimpiar">
                                                    <i class="fas fa-times me-2"></i> Limpiar
                                                </button>
                                                <button type="submit" class="btn btn-success btn-lg" id="btnSubir">
                                                    <i class="fas fa-upload me-2"></i> Subir Certificado
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pestaña Personalización -->
                    <div class="tab-pane fade" id="nav-contact" role="tabpanel" aria-labelledby="nav-contact-tab">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="fas fa-palette me-2"></i> Personalización de Facturas</h5>
                            </div>
                            <div class="card-body">
                                <?php if (isset($_SESSION['personalizacion_mensaje'])): ?>
                                    <div class="alert alert-<?php echo $_SESSION['personalizacion_tipo_mensaje']; ?> alert-dismissible fade show" role="alert">
                                        <?php echo $_SESSION['personalizacion_mensaje']; ?>
                                        <?php 
                                        unset($_SESSION['personalizacion_mensaje']);
                                        unset($_SESSION['personalizacion_tipo_mensaje']);
                                        ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <div class="row">
                                    <div class="col-md-6 mb-4">
                                        <div class="card h-100">
                                            <div class="card-header bg-primary text-white">
                                                <h5 class="mb-0"><i class="fas fa-image me-2"></i> Logotipo de la Organización</h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="text-center mb-4">
                                                    <h6>Logo Actual</h6>
                                                    <?php if (isset($organizacion->logo_url) && !empty($organizacion->logo_url)): ?>
                                                        <img src="<?php echo htmlspecialchars($organizacion->logo_url); ?>" 
                                                             alt="Logo de la organización" 
                                                             class="img-fluid rounded mb-3" 
                                                             style="max-height: 150px;">
                                                    <?php else: ?>
                                                        <div class="bg-light rounded p-5 mb-3">
                                                            <i class="fas fa-building fa-3x text-muted"></i>
                                                        </div>
                                                        <p class="text-muted small">No hay logo cargado</p>
                                                    <?php endif; ?>
                                                </div>

                                                <form method="POST" action="personalizacion_procesar.php" enctype="multipart/form-data" id="formLogo">
                                                    <input type="hidden" name="accion" value="subir_logo">
                                                    <input type="hidden" name="organization_id" value="<?php echo htmlspecialchars($organization_id); ?>">
                                                    <input type="hidden" name="api_key" value="<?php echo htmlspecialchars($api_key); ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label for="logo_file" class="form-label required">
                                                            <i class="fas fa-upload me-1"></i> Seleccionar archivo de logo
                                                        </label>
                                                        <div class="file-upload-area" id="logoDropArea">
                                                            <i class="fas fa-cloud-upload-alt fa-2x text-primary mb-2"></i>
                                                            <p class="mb-1">Arrastra tu logo aquí</p>
                                                            <p class="text-muted small">o haz clic para seleccionar</p>
                                                            <input type="file" class="form-control d-none" id="logo_file" name="logo_file" 
                                                                   accept=".jpg,.jpeg,.png,.gif,.webp,.bmp" required>
                                                            <div id="logoFileName" class="mt-2"></div>
                                                        </div>
                                                        <div class="form-text">Formatos aceptados: JPG, PNG, GIF, WebP, BMP (Máximo 2MB)</div>
                                                    </div>
                                                    
                                                    <button type="submit" class="btn btn-primary w-100" id="btnSubirLogo">
                                                        <i class="fas fa-upload me-2"></i> Subir Logo
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6 mb-4">
                                        <div class="card h-100">
                                            <div class="card-header bg-success text-white">
                                                <h5 class="mb-0"><i class="fas fa-cogs me-2"></i> Configuración de Personalización</h5>
                                            </div>
                                            <div class="card-body">
                                                <form method="POST" action="personalizacion_procesar.php" id="formPersonalizacion">
                                                    <input type="hidden" name="accion" value="actualizar_personalizacion">
                                                    <input type="hidden" name="organization_id" value="<?php echo htmlspecialchars($organization_id); ?>">
                                                    <input type="hidden" name="api_key" value="<?php echo htmlspecialchars($api_key); ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label for="color" class="form-label">
                                                            <i class="fas fa-palette me-1"></i> Color distintivo de la marca
                                                        </label>
                                                        <div class="input-group">
                                                            <input type="color" class="form-control form-control-color" id="color" name="color"
                                                                   value="<?php echo htmlspecialchars($organizacion->color ?? '#27ae60'); ?>">
                                                            <input type="text" class="form-control" id="color_hex" name="color_hex"
                                                                   value="<?php echo htmlspecialchars($organizacion->color ?? '#27ae60'); ?>"
                                                                   maxlength="7" placeholder="#RRGGBB">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mb-3">
                                                        <div class="col-md-6">
                                                            <label for="next_folio_number" class="form-label">
                                                                <i class="fas fa-file-alt me-1"></i> Siguiente folio (Live)
                                                            </label>
                                                            <input type="number" class="form-control" id="next_folio_number" name="next_folio_number"
                                                                   min="1" value="<?php echo htmlspecialchars($organizacion->next_folio_number ?? 1); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label for="next_folio_number_test" class="form-label">
                                                                <i class="fas fa-file-alt me-1"></i> Siguiente folio (Test)
                                                            </label>
                                                            <input type="number" class="form-control" id="next_folio_number_test" name="next_folio_number_test"
                                                                   min="1" value="<?php echo htmlspecialchars($organizacion->next_folio_number_test ?? 1); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-4">
                                                        <h6><i class="fas fa-file-pdf me-2"></i> Configuración del PDF</h6>
                                                        
                                                        <?php 
                                                        $pdf_extra = (array)($organizacion->pdf_extra ?? []);
                                                        $default_pdf_config = [
                                                            'codes' => true,
                                                            'product_key' => true,
                                                            'round_unit_price' => false,
                                                            'tax_breakdown' => true,
                                                            'ieps_breakdown' => true,
                                                            'render_carta_porte' => false
                                                        ];
                                                        
                                                        foreach ($default_pdf_config as $key => $default_value) {
                                                            $pdf_extra[$key] = $pdf_extra[$key] ?? $default_value;
                                                        }
                                                        ?>
                                                        
                                                        <div class="form-check form-switch mb-2">
                                                            <input class="form-check-input" type="checkbox" id="codes" name="pdf_extra[codes]" value="1" <?php echo $pdf_extra['codes'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="codes">Mostrar códigos de catálogos del SAT</label>
                                                        </div>
                                                        
                                                        <div class="form-check form-switch mb-2">
                                                            <input class="form-check-input" type="checkbox" id="product_key" name="pdf_extra[product_key]" value="1" <?php echo $pdf_extra['product_key'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="product_key">Mostrar clave de producto-servicio</label>
                                                        </div>
                                                        
                                                        <div class="form-check form-switch mb-2">
                                                            <input class="form-check-input" type="checkbox" id="round_unit_price" name="pdf_extra[round_unit_price]" value="1" <?php echo $pdf_extra['round_unit_price'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="round_unit_price">Redondear precio unitario en PDF</label>
                                                        </div>
                                                        
                                                        <div class="form-check form-switch mb-2">
                                                            <input class="form-check-input" type="checkbox" id="tax_breakdown" name="pdf_extra[tax_breakdown]" value="1" <?php echo $pdf_extra['tax_breakdown'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="tax_breakdown">Mostrar desglose de impuestos</label>
                                                        </div>
                                                        
                                                        <div class="form-check form-switch mb-2">
                                                            <input class="form-check-input" type="checkbox" id="ieps_breakdown" name="pdf_extra[ieps_breakdown]" value="1" <?php echo $pdf_extra['ieps_breakdown'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="ieps_breakdown">Mostrar desglose de IEPS</label>
                                                        </div>
                                                        
                                                        <div class="form-check form-switch mb-2">
                                                            <input class="form-check-input" type="checkbox" id="render_carta_porte" name="pdf_extra[render_carta_porte]" value="1" <?php echo $pdf_extra['render_carta_porte'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="render_carta_porte">Renderizar complemento Carta Porte 3.1</label>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="d-grid gap-2">
                                                        <button type="submit" class="btn btn-success">
                                                            <i class="fas fa-save me-2"></i> Guardar Configuración
                                                        </button>
                                                        <button type="button" class="btn btn-outline-secondary" id="btnResetPDF">
                                                            <i class="fas fa-undo me-2"></i> Restaurar valores por defecto
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pestaña Productos -->
                    <div class="tab-pane fade" id="nav-product" role="tabpanel" aria-labelledby="nav-product-tab">
                        <div class="container">
                            <div class="search-container">
                                <?php
                                $searchType = $_GET['search_type'] ?? 'all';
                                ?>
                                <div class="search-tabs">
                                    <div class="tab <?php echo $searchType === 'all' ? 'active' : ''; ?>" data-tab="all">
                                        <i class="fas fa-list"></i> Todos
                                    </div>
                                    <div class="tab <?php echo $searchType === 'text' ? 'active' : ''; ?>" data-tab="text">
                                        <i class="fas fa-search"></i> Búsqueda por texto
                                    </div>
                                    <div class="tab <?php echo $searchType === 'sku' ? 'active' : ''; ?>" data-tab="sku">
                                        <i class="fas fa-barcode"></i> Buscar por SKU
                                    </div>
                                </div>
                                
                                <form method="GET" class="search-form">
                                    <input type="hidden" name="tab" value="productos">
                                    <input type="hidden" name="search_type" id="search_type" value="<?php echo $searchType; ?>">
                                    <input type="hidden" name="page" value="1">
                                    
                                    <div class="form-group" id="text-search-group" style="<?php echo $searchType !== 'text' ? 'display: none;' : ''; ?>">
                                        <label for="q"><i class="fas fa-search"></i> Términos de búsqueda</label>
                                        <input type="text" id="q" name="q" class="form-control" 
                                               placeholder="Buscar en descripción o SKU del producto" 
                                               value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group" id="sku-search-group" style="<?php echo $searchType !== 'sku' ? 'display: none;' : ''; ?>">
                                        <label for="sku"><i class="fas fa-barcode"></i> SKU específico</label>
                                        <input type="text" id="sku" name="sku" class="form-control" 
                                               placeholder="Ejemplo: DELL-INSP15-2024"
                                               value="<?php echo htmlspecialchars($_GET['sku'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="limit"><i class="fas fa-list-ol"></i> Productos por página</label>
                                        <select id="limit" name="limit" class="form-control">
                                            <option value="6" <?php echo ($_GET['limit'] ?? 12) == 6 ? 'selected' : ''; ?>>6</option>
                                            <option value="12" <?php echo ($_GET['limit'] ?? 12) == 12 ? 'selected' : ''; ?>>12</option>
                                            <option value="24" <?php echo ($_GET['limit'] ?? 12) == 24 ? 'selected' : ''; ?>>24</option>
                                            <option value="48" <?php echo ($_GET['limit'] ?? 12) == 48 ? 'selected' : ''; ?>>48</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group" style="flex: 0 0 auto;">
                                        <button type="submit" class="btn">
                                            <i class="fas fa-search"></i> Buscar Productos
                                        </button>
                                    </div>
                                    
                                    <?php if (!empty($_GET) && isset($_GET['search_type'])): ?>
                                    <div class="form-group" style="flex: 0 0 auto;">
                                        <a href="?tab=productos" class="btn btn-secondary">
                                            <i class="fas fa-redo"></i> Limpiar
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                            
                            <div class="results-container">
                                <div class="results-header">
                                    <h2><i class="fas fa-box-open"></i> Productos Encontrados</h2>
                                    <?php if (isset($result) && $result['success'] && isset($result['data']->data)): ?>
                                    <div class="results-info">
                                        <i class="fas fa-chart-bar"></i> 
                                        Página <?php echo $result['data']->page ?? 1; ?> de <?php echo $result['data']->totalPages ?? 1; ?> 
                                        • Total: <?php echo $result['data']->total ?? 0; ?> productos
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (isset($result) && !$result['success']): ?>
                                    <div class="alert alert-error">
                                        <h3><i class="fas fa-exclamation-triangle"></i> Error al cargar productos</h3>
                                        <p><?php echo htmlspecialchars($result['error']); ?></p>
                                    </div>
                                    
                                <?php elseif (isset($result) && $result['success'] && isset($result['data']->data) && count($result['data']->data) > 0): ?>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-hover table-striped align-middle">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th style="width: 40%;">Descripción</th>
                                                    <th style="width: 15%;" class="text-center">SKU</th>
                                                    <th style="width: 15%;" class="text-center">Precio</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                foreach ($result['data']->data as $producto):
                                                    $iva = 0;
                                                    $precioFinal = $producto->price;
                                                    
                                                    if (isset($producto->taxes) && is_array($producto->taxes)) {
                                                        foreach ($producto->taxes as $tax) {
                                                            if (isset($tax->rate)) {
                                                                $iva += $tax->rate;
                                                            }
                                                        }
                                                    }
                                                    
                                                    if ($iva > 0) {
                                                        $precioFinal = $producto->price * (1 + ($iva / 100));
                                                    }
                                                ?>
                                                <tr>
                                                    <td data-label="Descripción">
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($producto->description); ?></div>
                                                    </td>
                                                    <td data-label="SKU" class="text-center">
                                                        <span class="badge bg-secondary">
                                                            <i class="fas fa-hashtag me-1"></i>
                                                            <?php echo htmlspecialchars($producto->sku ?? 'SIN-SKU'); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Precio Final" class="text-center fw-bold text-success">
                                                        $<?php echo number_format($precioFinal, 2); ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php 
                                    $totalPages = $result['data']->totalPages ?? 1;
                                    if ($totalPages > 1): 
                                    ?>
                                    <div class="pagination">
                                        <?php 
                                        $currentPage = $result['data']->page ?? 1;
                                        $totalPages = $result['data']->totalPages ?? 1;
                                        
                                        if ($currentPage > 1): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $currentPage - 1])); ?>">
                                                <i class="fas fa-chevron-left"></i> Anterior
                                            </a>
                                        <?php else: ?>
                                            <span class="disabled"><i class="fas fa-chevron-left"></i> Anterior</span>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        $startPage = max(1, $currentPage - 2);
                                        $endPage = min($totalPages, $startPage + 4);
                                        
                                        for ($p = $startPage; $p <= $endPage; $p++): 
                                        ?>
                                            <?php if ($p == $currentPage): ?>
                                                <span class="active"><?php echo $p; ?></span>
                                            <?php else: ?>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>">
                                                    <?php echo $p; ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        
                                        <?php if ($currentPage < $totalPages): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $currentPage + 1])); ?>">
                                                Siguiente <i class="fas fa-chevron-right"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="disabled">Siguiente <i class="fas fa-chevron-right"></i></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                <?php elseif (isset($result) && $result['success']): ?>
                                    <div class="no-results">
                                        <i class="fas fa-search"></i>
                                        <h3>No se encontraron productos</h3>
                                        <p>Intenta con otros términos de búsqueda o elimina los filtros.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Haz clic en "Buscar Productos" para cargar el catálogo.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- JavaScript Personalizado -->
    <script src="js/facturacion.js"></script>
</body>
</html>