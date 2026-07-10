<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();
// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo "No autorizado";
    exit();
}

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    http_response_code(500);
    echo "Error de conexión";
    exit();
}

// Obtener ID del distribuidor
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    http_response_code(400);
    echo "ID no válido";
    exit();
}

try {
    // Consultar datos del distribuidor
    $sql = "SELECT 
                id,
                numero_control,
                nombre_distribuidor,
                telefono,
                email,
                rfc,
                banco,
                numero_cuenta,
                constancia_fiscal,
                credencial_identificacion,
                fecha_subida_constancia,
                fecha_subida_credencial,
                declaracion_veracidad,
                estado_verificacion,
                observaciones_verificacion,
                fecha_verificacion,
                correo_enviado,
                fecha_envio_correo,
                fecha_registro,
                fecha_actualizacion,
                activo
            FROM distribuidores 
            WHERE id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo '<div class="alert alert-warning">No se encontró el distribuidor</div>';
        exit();
    }
    
    $dist = $result->fetch_assoc();
    
    // Función para formatear fecha
    function formatearFechaDetalle($fecha) {
        if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
            return '<span class="text-muted">No registrada</span>';
        }
        return date('d/m/Y H:i', strtotime($fecha));
    }
    
    // Función para obtener badge de estado
    function badgeEstado($estado) {
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
        
        return "<span class='badge bg-{$clase}'>{$texto}</span>";
    }
    
    // Función para escape seguro con manejo de null
    function safeHtml($value) {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    ?>
    
    <div class="container-fluid">
        <!-- Información básica -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h5 class="border-bottom pb-2">Información General</h5>
            </div>
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">Número de Control:</th>
                        <td><span class="badge bg-secondary"><?php echo safeHtml($dist['numero_control']); ?></span></td>
                    </tr>
                    <tr>
                        <th>Nombre:</th>
                        <td><?php echo safeHtml($dist['nombre_distribuidor']); ?></td>
                    </tr>
                    <tr>
                        <th>RFC:</th>
                        <td><?php echo safeHtml($dist['rfc']); ?></td>
                    </tr>
                    <tr>
                        <th>Estado Verificación:</th>
                        <td><?php echo badgeEstado($dist['estado_verificacion'] ?? 'pendiente'); ?></td>
                    </tr>
                    <tr>
                        <th>Estado:</th>
                        <td>
                            <?php if ($dist['activo'] ?? false): ?>
                                <span class="badge bg-success">Activo</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactivo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">Teléfono:</th>
                        <td><?php echo safeHtml($dist['telefono']); ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?php echo safeHtml($dist['email']); ?></td>
                    </tr>
                    <tr>
                        <th>Banco:</th>
                        <td><?php echo safeHtml($dist['banco']); ?></td>
                    </tr>
                    <tr>
                        <th>Número de Cuenta:</th>
                        <td><?php echo safeHtml($dist['numero_cuenta']); ?></td>
                    </tr>
                    <tr>
                        <th>Declaración Veracidad:</th>
                        <td>
                            <?php if ($dist['declaracion_veracidad'] ?? false): ?>
                                <span class="badge bg-success">Aceptada</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Pendiente</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Documentos -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h5 class="border-bottom pb-2">Documentos</h5>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Constancia Fiscal</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($dist['constancia_fiscal'])): ?>
                            <div class="text-center mb-3">
                                <?php
                                $es_pdf = strpos($dist['constancia_fiscal'], '.pdf') !== false;
                                $tipo = $es_pdf ? 'pdf' : 'imagen';
                                $ruta_completa = '/Distribuidor/uploads/distribuidores/constancias/' . $dist['constancia_fiscal'];
                                ?>
                                <i class="fas fa-<?php echo $es_pdf ? 'file-pdf' : 'file-image'; ?> fa-3x text-<?php echo $es_pdf ? 'danger' : 'primary'; ?> mb-2"></i>
                                <p class="mb-2">
                                    <small class="text-muted">Subido: <?php echo formatearFechaDetalle($dist['fecha_subida_constancia'] ?? null); ?></small>
                                </p>
                                <button type="button" class="btn btn-sm btn-primary ver-archivo"
                                        data-archivo="<?php echo safeHtml($ruta_completa); ?>"
                                        data-tipo="<?php echo $tipo; ?>"
                                        data-nombre="constancia_<?php echo safeHtml($dist['numero_control']); ?>.pdf"
                                        data-titulo="Constancia Fiscal - <?php echo safeHtml($dist['nombre_distribuidor']); ?>">
                                    <i class="fas fa-eye me-1"></i> Ver Documento
                                </button>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No se ha subido constancia fiscal</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Credencial/Identificación</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($dist['credencial_identificacion'])): ?>
                            <div class="text-center mb-3">
                                <?php
                                $es_pdf = strpos($dist['credencial_identificacion'], '.pdf') !== false;
                                $tipo = $es_pdf ? 'pdf' : 'imagen';
                                $ruta_completa = '/Distribuidor/uploads/distribuidores/credenciales/' . $dist['credencial_identificacion'];
                                ?>
                                <i class="fas fa-<?php echo $es_pdf ? 'file-pdf' : 'id-card'; ?> fa-3x text-<?php echo $es_pdf ? 'danger' : 'info'; ?> mb-2"></i>
                                <p class="mb-2">
                                    <small class="text-muted">Subido: <?php echo formatearFechaDetalle($dist['fecha_subida_credencial'] ?? null); ?></small>
                                </p>
                                <button type="button" class="btn btn-sm btn-primary ver-archivo"
                                        data-archivo="<?php echo safeHtml($ruta_completa); ?>"
                                        data-tipo="<?php echo $tipo; ?>"
                                        data-nombre="credencial_<?php echo safeHtml($dist['numero_control']); ?>.pdf"
                                        data-titulo="Credencial/Identificación - <?php echo safeHtml($dist['nombre_distribuidor']); ?>">
                                    <i class="fas fa-eye me-1"></i> Ver Documento
                                </button>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No se ha subido identificación</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Verificación y Observaciones -->
        <?php if (!empty($dist['observaciones_verificacion']) || !empty($dist['fecha_verificacion'])): ?>
        <div class="row">
            <div class="col-md-12">
                <h5 class="border-bottom pb-2">Información de Verificación</h5>
            </div>
            <div class="col-md-12">
                <table class="table table-sm">
                    <?php if (!empty($dist['fecha_verificacion'])): ?>
                    <tr>
                        <th width="20%">Fecha Verificación:</th>
                        <td><?php echo formatearFechaDetalle($dist['fecha_verificacion']); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (!empty($dist['observaciones_verificacion'])): ?>
                    <tr>
                        <th>Observaciones:</th>
                        <td><?php echo nl2br(safeHtml($dist['observaciones_verificacion'])); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (!empty($dist['correo_enviado'])): ?>
                    <tr>
                        <th>Correo Enviado:</th>
                        <td>
                            <span class="badge bg-success">Sí</span>
                            <small class="text-muted ms-2"><?php echo formatearFechaDetalle($dist['fecha_envio_correo'] ?? null); ?></small>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Fechas de registro -->
        <div class="row mt-3">
            <div class="col-md-12">
                <small class="text-muted">
                    Registrado: <?php echo formatearFechaDetalle($dist['fecha_registro'] ?? null); ?>
                    <?php if (($dist['fecha_actualizacion'] ?? null) != ($dist['fecha_registro'] ?? null)): ?>
                        | Actualizado: <?php echo formatearFechaDetalle($dist['fecha_actualizacion'] ?? null); ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    
    <?php
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al cargar los detalles</div>';
}

$conn->close();
?>