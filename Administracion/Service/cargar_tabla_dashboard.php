<?php
// =============================================
// SERVICE: CARGAR TABLA DASHBOARD (AJAX)
// =============================================
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();

// Cargar configuración
require_once __DIR__ . '/../../config/database.php';

// Deshabilitar errores HTML
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $pdo = getDBConnection();
    
    // Obtener parámetros - asegurar que todos tienen valores
    $filtro_plan = isset($_GET['plan']) ? $_GET['plan'] : '';
    $filtro_busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
    $pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $registros_por_pagina = 10;
    
    // Construir consulta base con filtros
    $sql_where = "WHERE 1=1";
    $params = [];

    if (!empty($filtro_plan) && $filtro_plan !== 'todos' && $filtro_plan !== '') {
        $sql_where .= " AND e.plan = ?";
        $params[] = $filtro_plan;
    }

    if (!empty($filtro_busqueda) && $filtro_busqueda !== '') {
        $sql_where .= " AND (e.nombre_empresa LIKE ? OR e.email LIKE ? OR e.rfc LIKE ? OR e.telefono LIKE ?)";
        $busqueda_param = "%" . $filtro_busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
    }

    // Contar total de registros
    $sql_count = "SELECT COUNT(*) as total FROM empresas e $sql_where";
    $stmt = $pdo->prepare($sql_count);
    $stmt->execute($params);
    $total_registros = intval($stmt->fetchColumn());

    // Calcular paginación
    $total_paginas = max(1, ceil($total_registros / $registros_por_pagina));
    $pagina_actual = min($pagina_actual, $total_paginas);
    $offset = ($pagina_actual - 1) * $registros_por_pagina;

    // Consulta principal
    $sql = "SELECT 
                e.id,
                e.nombre_empresa,
                e.giro_comercial,
                g.nombre as nombre_giro,
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
                e.activo,
                e.plan
            FROM empresas e
            LEFT JOIN giro_comercial g ON e.giro_comercial = g.id
            $sql_where 
            ORDER BY e.fecha_creacion DESC 
            LIMIT " . intval($offset) . ", " . intval($registros_por_pagina);

    // Ejecutar consulta directamente con los parámetros en el LIMIT
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $empresas = $stmt->fetchAll();

    // Funciones auxiliares
    function formatearFecha($fecha) {
        if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
            return 'No registrada';
        }
        return date('d/m/Y H:i', strtotime($fecha));
    }

    function obtenerNombrePlan($plan) {
        $planes = [
            'prueba' => 'Prueba',
            'basico' => 'Básico',
            'starter' => 'Profesional',
            'emprendedor' => 'Empresarial',
            'premium' => 'Empresarial Plus'
        ];
        return $planes[$plan] ?? $plan;
    }

    function clasePlan($plan) {
        switch ($plan) {
            case 'prueba': return 'secondary';
            case 'basico': return 'info';
            case 'starter': return 'primary';
            case 'emprendedor': return 'warning';
            case 'premium': return 'success';
            default: return 'secondary';
        }
    }

    // Generar HTML de la tabla
    ob_start();
    ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Empresa</th>
                    <th class="d-none d-md-table-cell">Contacto</th>
                    <th class="d-none d-lg-table-cell">Email</th>
                    <th class="d-none d-sm-table-cell">Teléfono</th>
                    <th class="d-none d-md-table-cell">Plan</th>
                    <th class="d-none d-md-table-cell">Registro</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($empresas)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <div class="text-muted">
                                <i class="fas fa-building display-6 d-block mb-3"></i>
                                No se encontraron empresas
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($empresas as $empresa): ?>
                        <tr class="fila-clickeable" 
                            data-id="<?php echo $empresa['id']; ?>" 
                            onclick="abrirModalDirecto(<?php echo $empresa['id']; ?>)"
                            style="cursor: pointer;">
                            <td data-label="Empresa">
                                <strong><?php echo htmlspecialchars($empresa['nombre_empresa'] ?? ''); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($empresa['nombre_giro'] ?? 'No especificado'); ?></small>
                                <div class="d-md-none mt-1">
                                    <small>
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($empresa['nombre_contacto'] ?? ''); ?>
                                    </small>
                                </div>
                            </td>
                            <td data-label="Contacto" class="d-none d-md-table-cell">
                                <?php echo htmlspecialchars($empresa['nombre_contacto'] ?? ''); ?>
                            </td>
                            <td data-label="Email" class="d-none d-lg-table-cell">
                                <?php echo htmlspecialchars($empresa['email_admin'] ?? 'No especificado'); ?>
                            </td>
                            <td data-label="Teléfono" class="d-none d-sm-table-cell">
                                <?php echo htmlspecialchars($empresa['telefono'] ?? 'No especificado'); ?>
                            </td>
                            <td data-label="Plan" class="d-none d-md-table-cell">
                                <span class="badge bg-<?php echo clasePlan($empresa['plan']); ?>">
                                    <?php echo obtenerNombrePlan($empresa['plan']); ?>
                                </span>
                            </td>
                            <td data-label="Registro" class="d-none d-md-table-cell">
                                <small><?php echo formatearFecha($empresa['fecha_creacion']); ?></small>
                            </td>
                            <td data-label="Acciones">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-info accion-btn btn-ver-detalle"
                                        data-id="<?php echo $empresa['id']; ?>"
                                        title="Ver detalles"
                                        onclick="event.stopPropagation();">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning accion-btn btn-editar-empresa"
                                        data-id="<?php echo $empresa['id']; ?>"
                                        title="Editar"
                                        onclick="event.stopPropagation();">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger accion-btn btn-eliminar" 
                                        data-id="<?php echo $empresa['id']; ?>"
                                        title="Eliminar"
                                        onclick="event.stopPropagation();">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mensaje informativo -->
    <div class="text-muted small mt-2 mb-2 ps-2">
        <i class="fas fa-info-circle me-1"></i>
        Haz clic en cualquier fila para ver los detalles completos de la empresa
    </div>

    <!-- Paginación -->
    <?php if ($total_paginas > 1): ?>
        <div class="card-footer">
            <nav aria-label="Paginación">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link paginacion-link" href="#" data-pagina="<?php echo max(1, $pagina_actual - 1); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>

                    <?php 
                    $inicio = max(1, $pagina_actual - 2);
                    $fin = min($total_paginas, $pagina_actual + 2);
                    
                    if ($inicio > 1) {
                        echo '<li class="page-item"><a class="page-link paginacion-link" href="#" data-pagina="1">1</a></li>';
                        if ($inicio > 2) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }
                    
                    for ($i = $inicio; $i <= $fin; $i++): ?>
                        <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                            <a class="page-link paginacion-link" href="#" data-pagina="<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor;

                    if ($fin < $total_paginas) {
                        if ($fin < $total_paginas - 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        echo '<li class="page-item"><a class="page-link paginacion-link" href="#" data-pagina="' . $total_paginas . '">' . $total_paginas . '</a></li>';
                    }
                    ?>

                    <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                        <a class="page-link paginacion-link" href="#" data-pagina="<?php echo min($total_paginas, $pagina_actual + 1); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();
    
    // Devolver respuesta JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $html,
        'total' => $total_registros,
        'total_paginas' => $total_paginas,
        'pagina_actual' => $pagina_actual,
        'filtros' => [
            'plan' => $filtro_plan,
            'busqueda' => $filtro_busqueda
        ]
    ]);

} catch (PDOException $e) {
    error_log('Error en cargar_tabla_dashboard: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Error general en cargar_tabla_dashboard: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Error al procesar la solicitud: ' . $e->getMessage()
    ]);
}