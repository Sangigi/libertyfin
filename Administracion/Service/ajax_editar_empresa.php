<?php
// =============================================
// AJAX: OBTENER FORMULARIO DE EDICIÓN DE EMPRESA
// =============================================

// Deshabilitar reporte de errores HTML
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Configuración de sesión personalizada
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

// Cargar configuración centralizada
require_once __DIR__ . '/../../config/database.php';

// Verificar que se haya enviado el ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit('ID no válido');
}

$empresa_id = intval($_GET['id']);

try {
    // Obtener conexión a la base de datos principal
    $pdo = getDBConnection();
    
    // Consulta para obtener los datos de la empresa
    $sql = "SELECT 
                e.id,
                e.nombre_empresa,
                e.giro_comercial,
                e.rfc,
                e.telefono,
                e.email,
                e.direccion,
                e.nombre_contacto,
                e.usuario_admin,
                e.email_admin,
                e.nombre_base_datos,
                e.usuario_base_datos,
                e.plan,
                e.activo,
                e.estado_verificacion
            FROM empresas e
            WHERE e.id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresa_id]);
    $empresa = $stmt->fetch();

    if (!$empresa) {
        echo '<div class="alert alert-warning">No se encontró la empresa solicitada.</div>';
        exit;
    }

    // Obtener lista de giros comerciales para el select
    $sql_giros = "SELECT id, nombre FROM giro_comercial ORDER BY nombre ASC";
    $stmt_giros = $pdo->query($sql_giros);
    $giros = $stmt_giros->fetchAll();

    // Función segura para htmlspecialchars
    function safeHtml($value) {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    // Función para obtener nombre del plan
    function getPlanName($plan) {
        $planes = [
            'prueba' => 'Prueba',
            'basico' => 'Básico',
            'starter' => 'Profesional',
            'emprendedor' => 'Empresarial',
            'premium' => 'Empresarial Plus'
        ];
        return $planes[$plan] ?? $plan;
    }

?>
<div class="container-fluid">
    <form id="formEditarEmpresa" class="needs-validation" novalidate>
        <input type="hidden" name="id_empresa" value="<?php echo $empresa['id']; ?>">
        
        <!-- Mensaje de respuesta -->
        <div id="editMessage" style="display: none;"></div>
        
        <div class="row">
            <!-- Columna izquierda -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="nombre_empresa" class="form-label fw-bold">
                        <i class="fas fa-building me-1 text-primary"></i>Nombre de la Empresa *
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="nombre_empresa" 
                           name="nombre_empresa" 
                           value="<?php echo safeHtml($empresa['nombre_empresa']); ?>" 
                           required>
                    <div class="invalid-feedback">Por favor ingrese el nombre de la empresa</div>
                </div>

                <div class="mb-3">
                    <label for="giro_comercial" class="form-label fw-bold">
                        <i class="fas fa-industry me-1 text-primary"></i>Giro Comercial *
                    </label>
                    <select class="form-select" id="giro_comercial" name="giro_comercial" required>
                        <option value="">Seleccione un giro...</option>
                        <?php foreach ($giros as $giro): ?>
                            <option value="<?php echo $giro['id']; ?>" 
                                <?php echo ($giro['id'] == $empresa['giro_comercial']) ? 'selected' : ''; ?>>
                                <?php echo safeHtml($giro['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Por favor seleccione un giro comercial</div>
                </div>

                <div class="mb-3">
                    <label for="rfc" class="form-label fw-bold">
                        <i class="fas fa-id-card me-1 text-primary"></i>RFC
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="rfc" 
                           name="rfc" 
                           value="<?php echo safeHtml($empresa['rfc']); ?>">
                </div>

                <div class="mb-3">
                    <label for="telefono" class="form-label fw-bold">
                        <i class="fas fa-phone me-1 text-primary"></i>Teléfono *
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="telefono" 
                           name="telefono" 
                           value="<?php echo safeHtml($empresa['telefono']); ?>" 
                           required>
                    <div class="invalid-feedback">Por favor ingrese el teléfono</div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label fw-bold">
                        <i class="fas fa-envelope me-1 text-primary"></i>Email *
                    </label>
                    <input type="email" 
                           class="form-control" 
                           id="email" 
                           name="email" 
                           value="<?php echo safeHtml($empresa['email']); ?>" 
                           required>
                    <div class="invalid-feedback">Por favor ingrese un email válido</div>
                </div>
            </div>

            <!-- Columna derecha -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="direccion" class="form-label fw-bold">
                        <i class="fas fa-map-marker-alt me-1 text-primary"></i>Dirección
                    </label>
                    <textarea class="form-control" 
                              id="direccion" 
                              name="direccion" 
                              rows="2"><?php echo safeHtml($empresa['direccion']); ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="nombre_contacto" class="form-label fw-bold">
                        <i class="fas fa-user me-1 text-primary"></i>Nombre de Contacto *
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="nombre_contacto" 
                           name="nombre_contacto" 
                           value="<?php echo safeHtml($empresa['nombre_contacto']); ?>" 
                           required>
                    <div class="invalid-feedback">Por favor ingrese el nombre de contacto</div>
                </div>

                <div class="mb-3">
                    <label for="email_admin" class="form-label fw-bold">
                        <i class="fas fa-user-shield me-1 text-primary"></i>Email Administrador
                    </label>
                    <input type="email" 
                           class="form-control" 
                           id="email_admin" 
                           name="email_admin" 
                           value="<?php echo safeHtml($empresa['email_admin']); ?>">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="plan" class="form-label fw-bold">
                            <i class="fas fa-crown me-1 text-primary"></i>Plan *
                        </label>
                        <select class="form-select" id="plan" name="plan" required>
                            <option value="prueba" <?php echo $empresa['plan'] == 'prueba' ? 'selected' : ''; ?>>Prueba</option>
                            <option value="basico" <?php echo $empresa['plan'] == 'basico' ? 'selected' : ''; ?>>Básico</option>
                            <option value="starter" <?php echo $empresa['plan'] == 'starter' ? 'selected' : ''; ?>>Profesional</option>
                            <option value="emprendedor" <?php echo $empresa['plan'] == 'emprendedor' ? 'selected' : ''; ?>>Empresarial</option>
                            <option value="premium" <?php echo $empresa['plan'] == 'premium' ? 'selected' : ''; ?>>Empresarial Plus</option>
                        </select>
                        <div class="invalid-feedback">Por favor seleccione un plan</div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="activo" class="form-label fw-bold">
                            <i class="fas fa-power-off me-1 text-primary"></i>Estado
                        </label>
                        <select class="form-select" id="activo" name="activo">
                            <option value="1" <?php echo $empresa['activo'] == 1 ? 'selected' : ''; ?>>Activo</option>
                            <option value="0" <?php echo $empresa['activo'] == 0 ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="estado_verificacion" class="form-label fw-bold">
                        <i class="fas fa-check-circle me-1 text-primary"></i>Estado de Verificación
                    </label>
                    <select class="form-select" id="estado_verificacion" name="estado_verificacion">
                        <option value="pendiente" <?php echo $empresa['estado_verificacion'] == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                        <option value="en_revision" <?php echo $empresa['estado_verificacion'] == 'en_revision' ? 'selected' : ''; ?>>En Revisión</option>
                        <option value="aprobado" <?php echo $empresa['estado_verificacion'] == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                        <option value="rechazado" <?php echo $empresa['estado_verificacion'] == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                    </select>
                </div>
            </div>
        </div>


        <!-- Botones de acción -->
        <div class="row mt-4">
            <div class="col-12 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancelar
                </button>
                <button type="submit" class="btn btn-warning" id="btnGuardarEdicion">
                    <i class="fas fa-save me-2"></i>Guardar Cambios
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// Validación del formulario
(function() {
    'use strict';
    var form = document.getElementById('formEditarEmpresa');
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    }, false);
})();
</script>

<?php
} catch (Exception $e) {
    echo '<div class="alert alert-danger border-0 shadow-sm">Error al cargar el formulario: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>