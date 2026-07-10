<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();
date_default_timezone_set('America/Mexico_City');
// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    exit('No autorizado');
}

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Verificar que se haya enviado el ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit('ID no válido');
}

$empresa_id = intval($_GET['id']);

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Error de conexión a la base de datos');
}

try {
    // Consulta modificada para obtener el nombre del giro comercial
    $sql = "SELECT 
                e.id,
                e.nombre_empresa,
                e.giro_comercial,
                g.nombre as nombre_giro_comercial,  -- Nombre del giro comercial
                e.rfc,
                e.telefono,
                e.email,
                e.direccion,
                e.nombre_contacto,
                e.usuario_admin,
                e.email_admin,
                e.nombre_base_datos,
                e.usuario_base_datos,
                e.constancia_fiscal,
                e.credencial_identificacion,
                e.fecha_subida_constancia,
                e.fecha_subida_credencial,
                e.declaracion_veracidad,
                e.estado_verificacion,
                e.observaciones_verificacion,
                e.fecha_verificacion,
                e.correo_enviado,
                e.fecha_envio_correo,
                e.fecha_creacion,
                e.fecha_vencimiento,
                e.plan,
                e.activo
            FROM empresas e
            LEFT JOIN giro_comercial g ON e.giro_comercial = g.id  -- JOIN con la tabla giro_comercial
            WHERE e.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo '<div class="alert alert-warning">No se encontró la empresa solicitada.</div>';
        exit;
    }

    $empresa = $result->fetch_assoc();
    $stmt->close();

    // OBTENER ESTADÍSTICAS DE LA BASE DE DATOS DE LA EMPRESA
    $estadisticas = [
        'usuarios' => 0,
        'sucursales' => 0,
        'productos' => 0,
        'proveedores' => 0,
        'clientes' => 0,
        'ventas_hoy' => 0,
        'ingresos_hoy' => 0,
        'total_ventas' => 0,
        'total_ingresos' => 0
    ];

    if (!empty($empresa['nombre_base_datos'])) {
        try {
            // Conectar a la base de datos de la empresa
            $conn_empresa = new mysqli($servername, $username, $password, $empresa['nombre_base_datos']);
            
            if (!$conn_empresa->connect_error) {
                // Consultar estadísticas básicas
                $sql_stats = "SELECT 
                    (SELECT COUNT(*) FROM usuarios WHERE activo = TRUE) as total_usuarios,
                    (SELECT COUNT(*) FROM clientes WHERE activo = TRUE) as total_clientes,
                    (SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()) as ventas_hoy,
                    (SELECT COALESCE(SUM(total), 0) FROM ventas WHERE DATE(fecha) = CURDATE()) as ingresos_hoy,
                    (SELECT COUNT(*) FROM ventas) as total_ventas,
                    (SELECT COALESCE(SUM(total), 0) FROM ventas) as total_ingresos";
                
                $result_stats = $conn_empresa->query($sql_stats);
                if ($result_stats && $result_stats->num_rows > 0) {
                    $row_stats = $result_stats->fetch_assoc();
                    $estadisticas['usuarios'] = $row_stats['total_usuarios'] ?? 0;
                    $estadisticas['clientes'] = $row_stats['total_clientes'] ?? 0;
                    $estadisticas['ventas_hoy'] = $row_stats['ventas_hoy'] ?? 0;
                    $estadisticas['ingresos_hoy'] = number_format($row_stats['ingresos_hoy'] ?? 0, 2);
                    $estadisticas['total_ventas'] = $row_stats['total_ventas'] ?? 0;
                    $estadisticas['total_ingresos'] = number_format($row_stats['total_ingresos'] ?? 0, 2);
                }
                
                // Contar sucursales (si existe la tabla)
                $sql_sucursales = "SELECT COUNT(*) as total FROM sucursales WHERE activo = TRUE";
                $result_sucursales = $conn_empresa->query($sql_sucursales);
                if ($result_sucursales && $result_sucursales->num_rows > 0) {
                    $row_suc = $result_sucursales->fetch_assoc();
                    $estadisticas['sucursales'] = $row_suc['total'] ?? 0;
                }
                
                // Contar productos (si existe la tabla)
                $sql_productos = "SELECT COUNT(*) as total FROM productos WHERE activo = TRUE";
                $result_productos = $conn_empresa->query($sql_productos);
                if ($result_productos && $result_productos->num_rows > 0) {
                    $row_prod = $result_productos->fetch_assoc();
                    $estadisticas['productos'] = $row_prod['total'] ?? 0;
                }
                
                // Contar proveedores (si existe la tabla)
                $sql_proveedores = "SELECT COUNT(*) as total FROM proveedores WHERE activo = TRUE";
                $result_proveedores = $conn_empresa->query($sql_proveedores);
                if ($result_proveedores && $result_proveedores->num_rows > 0) {
                    $row_prov = $result_proveedores->fetch_assoc();
                    $estadisticas['proveedores'] = $row_prov['total'] ?? 0;
                }
                
                $conn_empresa->close();
            }
        } catch (Exception $e) {
            // Silenciar error, las estadísticas serán 0
            error_log("Error conectando a BD de empresa {$empresa['nombre_base_datos']}: " . $e->getMessage());
        }
    }

    // Función para formatear fechas
    function formatearFechaDetalle($fecha)
    {
        if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
            return '<span class="text-muted">No registrada</span>';
        }
        return date('d/m/Y H:i', strtotime($fecha));
    }

    // Función para formatear solo fecha (no hora)
    function formatearSoloFecha($fecha)
    {
        if (empty($fecha) || $fecha == '0000-00-00') {
            return '<span class="text-muted">No establecida</span>';
        }
        return date('d/m/Y', strtotime($fecha));
    }

    // Función para mostrar estado con colores
    function mostrarEstadoDetalle($estado)
    {
        $clases = [
            'pendiente' => 'warning',
            'en_revision' => 'info',
            'aprobado' => 'success',
            'rechazado' => 'danger'
        ];

        $textos = [
            'pendiente' => 'Pendiente',
            'en_revision' => 'En Revisión',
            'aprobado' => 'Aprobado',
            'rechazado' => 'Rechazado'
        ];

        $clase = $clases[$estado] ?? 'secondary';
        $texto = $textos[$estado] ?? $estado;

        return '<span class="badge bg-' . $clase . '">' . $texto . '</span>';
    }

    // Función para mostrar el plan
    function mostrarPlan($plan)
    {
        $planes = [
            'prueba' => ['texto' => 'Prueba', 'clase' => 'secondary'],
            'emprendedor' => ['texto' => 'Emprendedor', 'clase' => 'info'],
            'premium' => ['texto' => 'Premium', 'clase' => 'success']
        ];
        
        if (isset($planes[$plan])) {
            return '<span class="badge bg-' . $planes[$plan]['clase'] . '">' . $planes[$plan]['texto'] . '</span>';
        }
        return '<span class="badge bg-secondary">' . htmlspecialchars($plan) . '</span>';
    }

    // Función para mostrar valores booleanos
    function mostrarBooleano($valor)
    {
        if ($valor == 1 || $valor === true) {
            return '<span class="badge bg-success">Sí</span>';
        } else {
            return '<span class="badge bg-secondary">No</span>';
        }
    }

    // Función para mostrar texto largo
    function mostrarTexto($texto, $maxLength = 200)
    {
        if (empty($texto)) {
            return '<span class="text-muted">No especificado</span>';
        }

        if (strlen($texto) > $maxLength) {
            return htmlspecialchars(substr($texto, 0, $maxLength)) . '...';
        }

        return htmlspecialchars($texto);
    }

    // Función para mostrar documentos
    function mostrarDocumento($nombreArchivo, $tipoDocumento)
    {
        if (empty($nombreArchivo)) {
            return '<span class="text-muted">No subido</span>';
        }

        // Determinar la ruta correcta según el tipo de documento
        if ($tipoDocumento == 'Constancia Fiscal') {
            $ruta = '../uploads/constancias/' . $nombreArchivo;
            $ruta_relativa = '../uploads/constancias/' . $nombreArchivo;
        } else if ($tipoDocumento == 'Credencial de Identificación') {
            $ruta = '../uploads/credenciales/' . $nombreArchivo;
            $ruta_relativa = '../uploads/credenciales/' . $nombreArchivo;
        } else {
            return '<span class="text-muted">Tipo de documento no válido</span>';
        }

        // Verificar si el archivo existe
        if (file_exists($ruta)) {
            $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
            $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $is_pdf = $extension == 'pdf';

            if ($is_image || $is_pdf) {
                return '<button type="button" class="btn btn-sm btn-outline-primary ver-archivo" 
                          data-archivo="' . htmlspecialchars($ruta_relativa) . '"
                          data-tipo="' . ($is_image ? 'imagen' : 'pdf') . '"
                          data-nombre="' . htmlspecialchars($nombreArchivo) . '"
                          data-titulo="' . $tipoDocumento . '">
                            <i class="fas fa-eye me-1"></i>
                            Ver ' . ($is_image ? 'Imagen' : 'PDF') . '
                        </button>';
            } else {
                return '<a href="' . htmlspecialchars($ruta_relativa) . '" 
                          target="_blank" 
                          class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file-download me-1"></i>Descargar
                        </a>';
            }
        } else {
            return '<span class="text-danger">
                        <i class="fas fa-exclamation-triangle me-1"></i>Archivo no encontrado
                    </span>';
        }
    }

    // Construir el HTML de los detalles
?>
<div class="detalle-empresa-container">
    <!-- Header con información principal -->
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h4 class="fw-bold text-primary mb-2"><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></h4>
        </div>
        <div class="text-end mt-2 mt-md-0">
            <div class="text-muted small">
                <i class="fas fa-calendar-plus me-1"></i>Registrado: <?php echo formatearFechaDetalle($empresa['fecha_creacion']); ?>
            </div>
            <?php if ($empresa['fecha_vencimiento'] && $empresa['fecha_vencimiento'] != '0000-00-00'): ?>
            <div class="text-muted small">
                <i class="fas fa-calendar-times me-1"></i>Vence: <?php echo formatearSoloFecha($empresa['fecha_vencimiento']); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tarjetas de información -->
    <div class="row g-4">
        <!-- ESTADÍSTICAS DE LA EMPRESA -->
        <div class="col-12">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light border-0 py-3">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-chart-bar me-2 text-info"></i>Estadísticas de la Empresa
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Usuarios -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-users fa-2x text-primary"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-primary">
                                    <?php echo $estadisticas['usuarios']; ?>
                                </div>
                                <div class="stat-label text-muted small">Usuarios Activos</div>
                            </div>
                        </div>

                        <!-- Sucursales -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-store fa-2x text-success"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-success">
                                    <?php echo $estadisticas['sucursales']; ?>
                                </div>
                                <div class="stat-label text-muted small">Sucursales</div>
                            </div>
                        </div>

                        <!-- Productos -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-box fa-2x text-warning"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-warning">
                                    <?php echo $estadisticas['productos']; ?>
                                </div>
                                <div class="stat-label text-muted small">Productos</div>
                            </div>
                        </div>

                        <!-- Proveedores -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-truck fa-2x text-info"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-info">
                                    <?php echo $estadisticas['proveedores']; ?>
                                </div>
                                <div class="stat-label text-muted small">Proveedores</div>
                            </div>
                        </div>

                        <!-- Clientes -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-user-friends fa-2x text-secondary"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-secondary">
                                    <?php echo $estadisticas['clientes']; ?>
                                </div>
                                <div class="stat-label text-muted small">Clientes</div>
                            </div>
                        </div>

                        <!-- Ventas Hoy -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-shopping-cart fa-2x text-danger"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-danger">
                                    <?php echo $estadisticas['ventas_hoy']; ?>
                                </div>
                                <div class="stat-label text-muted small">Ventas Hoy</div>
                            </div>
                        </div>

                        <!-- Ingresos Hoy -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-money-bill-wave fa-2x text-success"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-success">
                                    $<?php echo $estadisticas['ingresos_hoy']; ?>
                                </div>
                                <div class="stat-label text-muted small">Ingresos Hoy</div>
                            </div>
                        </div>

                        <!-- Total Ventas -->
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card bg-light p-3 text-center rounded">
                                <div class="stat-icon mb-2">
                                    <i class="fas fa-chart-line fa-2x text-primary"></i>
                                </div>
                                <div class="stat-value h4 mb-1 fw-bold text-primary">
                                    <?php echo $estadisticas['total_ventas']; ?>
                                </div>
                                <div class="stat-label text-muted small">Total Ventas</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Resumen Financiero -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="alert alert-success border-success border">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted d-block">Total Ingresos Generados</small>
                                            <div class="h3 mb-0 text-success">$<?php echo $estadisticas['total_ingresos']; ?> MXN</div>
                                        </div>
                                        <i class="fas fa-piggy-bank fa-2x text-success opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-info border-info border">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted d-block">Actividad del Día</small>
                                            <div class="h4 mb-0 text-info"><?php echo $estadisticas['ventas_hoy']; ?> ventas</div>
                                            <div class="text-success fw-bold">$<?php echo $estadisticas['ingresos_hoy']; ?> MXN</div>
                                        </div>
                                        <i class="fas fa-bolt fa-2x text-info opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Información General -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-light border-0 py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-building me-2 text-primary"></i>Información General
                    </h6>
                    <div class="text-muted small">
                        <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($empresa['rfc']); ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 mb-3">
                            <div class="info-item">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-industry me-2 fa-sm" style="width: 20px;"></i>Giro Comercial
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo mostrarTexto($empresa['nombre_giro_comercial'] ?? 'No especificado'); ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-envelope me-2 fa-sm" style="width: 20px;"></i>Email
                                </label>
                                <div class="fw-semibold ps-4 text-truncate" title="<?php echo htmlspecialchars($empresa['email']); ?>">
                                    <a href="mailto:<?php echo htmlspecialchars($empresa['email']); ?>" class="text-decoration-none text-primary">
                                        <?php echo htmlspecialchars($empresa['email']); ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-phone me-2 fa-sm" style="width: 20px;"></i>Teléfono
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php if (!empty($empresa['telefono'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($empresa['telefono']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($empresa['telefono']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">No especificado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-map-marker-alt me-2 fa-sm" style="width: 20px;"></i>Dirección
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo mostrarTexto($empresa['direccion'], 100); ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-user me-2 fa-sm" style="width: 20px;"></i>Contacto
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo htmlspecialchars($empresa['nombre_contacto']); ?>
                                </div>
                            </div>
                        </div>

                        <!-- <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-user-shield me-2 fa-sm" style="width: 20px;"></i>Usuario Admin
                                </label>
                                <div class="fw-semibold ps-4">
                                   
                                </div>
                            </div>
                        </div> -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Estado y Configuración -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-light border-0 py-3">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-cogs me-2 text-success"></i>Estado y Configuración
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Estado de Verificación -->
                        <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-clipboard-check me-2 fa-sm" style="width: 20px;"></i>Estado de Verificación
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo mostrarEstadoDetalle($empresa['estado_verificacion']); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Plan -->
                        <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-cube me-2 fa-sm" style="width: 20px;"></i>Plan
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo mostrarPlan($empresa['plan']); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Activo -->
                        <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-power-off me-2 fa-sm" style="width: 20px;"></i>Activo
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo mostrarBooleano($empresa['activo']); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Correo Enviado -->
                        <div class="col-md-6">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-paper-plane me-2 fa-sm" style="width: 20px;"></i>Correo Enviado
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo mostrarBooleano($empresa['correo_enviado']); ?>
                                    <?php if ($empresa['correo_enviado']): ?>
                                        <div class="text-muted small mt-1">
                                            <i class="fas fa-clock me-1"></i><?php echo formatearFechaDetalle($empresa['fecha_envio_correo']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Email Admin -->
                        <div class="col-md-12">
                            <div class="info-item mb-3">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-envelope-open me-2 fa-sm" style="width: 20px;"></i>Email Admin
                                </label>
                                <div class="fw-semibold ps-4 text-truncate" title="<?php echo htmlspecialchars($empresa['email_admin'] ?? ''); ?>">
                                    <?php if (!empty($empresa['email_admin'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($empresa['email_admin']); ?>" class="text-decoration-none text-primary">
                                            <?php echo htmlspecialchars($empresa['email_admin']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">No asignado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($empresa['fecha_verificacion'] && $empresa['fecha_verificacion'] != '0000-00-00 00:00:00'): ?>
                        <div class="col-12">
                            <div class="info-item">
                                <label class="form-label text-muted small mb-1 d-flex align-items-center">
                                    <i class="fas fa-check-circle me-2 fa-sm" style="width: 20px;"></i>Última Verificación
                                </label>
                                <div class="fw-semibold ps-4">
                                    <?php echo formatearFechaDetalle($empresa['fecha_verificacion']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documentación -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light border-0 py-3">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-file-alt me-2 text-warning"></i>Documentación
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="documento-card p-3 border rounded">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start mb-2">
                                    <div class="mb-2 mb-md-0">
                                        <h6 class="fw-semibold mb-1">
                                            <i class="fas fa-file-invoice-dollar text-primary me-2"></i>
                                            Constancia Fiscal
                                        </h6>
                                        <?php if (!empty($empresa['fecha_subida_constancia']) && $empresa['fecha_subida_constancia'] != '0000-00-00 00:00:00'): ?>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                Subido: <?php echo formatearFechaDetalle($empresa['fecha_subida_constancia']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php echo mostrarDocumento($empresa['constancia_fiscal'], 'Constancia Fiscal'); ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Documento oficial emitido por el SAT
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="documento-card p-3 border rounded">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start mb-2">
                                    <div class="mb-2 mb-md-0">
                                        <h6 class="fw-semibold mb-1">
                                            <i class="fas fa-id-card text-success me-2"></i>
                                            Credencial de Identificación
                                        </h6>
                                        <?php if (!empty($empresa['fecha_subida_credencial']) && $empresa['fecha_subida_credencial'] != '0000-00-00 00:00:00'): ?>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                Subido: <?php echo formatearFechaDetalle($empresa['fecha_subida_credencial']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php echo mostrarDocumento($empresa['credencial_identificacion'], 'Credencial de Identificación'); ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        INE o pasaporte del representante legal
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Observaciones (si existen) -->
        <?php if ($empresa['estado_verificacion'] === 'rechazado' || !empty($empresa['observaciones_verificacion'])): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light border-0 py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-comment-dots me-2 text-info"></i>Observaciones
                    </h6>
                    <span class="badge bg-<?php echo $empresa['estado_verificacion'] === 'rechazado' ? 'warning' : 'info'; ?>">
                        <?php echo $empresa['estado_verificacion'] === 'rechazado' ? 'Importante' : 'Información'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert <?php echo $empresa['estado_verificacion'] === 'rechazado' ? 'alert-warning border-warning' : 'alert-info border-info'; ?> border">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $empresa['estado_verificacion'] === 'rechazado' ? 'fa-exclamation-triangle' : 'fa-info-circle'; ?> fa-lg"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($empresa['observaciones_verificacion'] ?? 'Sin observaciones')); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Firma de veracidad -->
    <?php if ($empresa['declaracion_veracidad']): ?>
    <div class="mt-4 pt-3 border-top">
        <div class="alert alert-success border-success border d-inline-block">
            <div class="d-flex align-items-center">
                <i class="fas fa-file-signature me-2"></i>
                <span>El usuario aceptó la declaración de veracidad de la información</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.detalle-empresa-container {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.stat-card {
    transition: all 0.3s ease;
    border: 1px solid #dee2e6;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border-color: #adb5bd;
}

.stat-icon {
    opacity: 0.8;
}

.stat-value {
    font-size: 1.8rem;
}

.stat-label {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.documento-card {
    background: #f8f9fa;
    transition: all 0.3s ease;
}

.documento-card:hover {
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.card {
    border-radius: 10px;
    overflow: hidden;
}

.card-header {
    border-bottom: 2px solid #e9ecef;
}

.form-label {
    font-size: 0.85rem;
    font-weight: 500;
}

.fw-semibold {
    font-weight: 600;
}

.badge {
    font-weight: 500;
    padding: 0.35em 0.65em;
    font-size: 0.85em;
}

a.text-decoration-none:hover {
    text-decoration: underline !important;
}

.alert-light {
    background-color: #f8f9fa;
    border-color: #dee2e6;
}

.fa-sm {
    font-size: 0.875em;
}

.info-item {
    padding: 8px 0;
}

.text-truncate {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (max-width: 768px) {
    .detalle-empresa-container {
        font-size: 0.95rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .d-flex {
        flex-direction: column;
        align-items: flex-start !important;
    }
    
    .d-flex > * {
        margin-bottom: 5px;
    }
    
    .stat-value {
        font-size: 1.5rem;
    }
}

/* Estilo para los estados */
.badge.bg-success { background-color: #198754 !important; }
.badge.bg-warning { background-color: #ffc107 !important; color: #000; }
.badge.bg-danger { background-color: #dc3545 !important; }
.badge.bg-info { background-color: #0dcaf0 !important; color: #000; }
.badge.bg-secondary { background-color: #6c757d !important; }

/* Tooltip para emails largos */
[title] {
    cursor: help;
}

/* Colores para las tarjetas de estadísticas */
.text-primary { color: #0d6efd !important; }
.text-success { color: #198754 !important; }
.text-warning { color: #ffc107 !important; }
.text-info { color: #0dcaf0 !important; }
.text-danger { color: #dc3545 !important; }
.text-secondary { color: #6c757d !important; }
</style>

<?php

} catch (Exception $e) {
    echo '<div class="alert alert-danger border-0 shadow-sm">Error al cargar los detalles: ' . htmlspecialchars($e->getMessage()) . '</div>';
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>