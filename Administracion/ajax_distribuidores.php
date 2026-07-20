<?php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'mensaje' => 'Sesión no válida']);
    exit();
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$config = [
    'servername' => 'libertyfin.com.mx',
    'username' => 'juanc141_alexis',
    'password' => 'Alexis1997',
    'dbname' => 'juanc141_ventas',
    'registros_por_pagina' => 5
];

$conn = new mysqli($config['servername'], $config['username'], $config['password'], $config['dbname']);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'mensaje' => 'Error de conexión']);
    exit();
}

try {
    $pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
    $estado_verificacion = isset($_GET['estado_verificacion']) ? $_GET['estado_verificacion'] : '';
    $estado_activo = isset($_GET['estado_activo']) ? $_GET['estado_activo'] : '';
    $limit = $config['registros_por_pagina'];
    $offset = ($pagina - 1) * $limit;
    
    // Construir condiciones WHERE de forma optimizada
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($busqueda)) {
        $where[] = "(nombre_distribuidor LIKE ? OR email LIKE ? OR rfc LIKE ? OR telefono LIKE ? OR numero_control LIKE ?)";
        $like = "%$busqueda%";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
        $types .= "sssss";
    }
    
    if (!empty($estado_verificacion)) {
        $where[] = "estado_verificacion = ?";
        $params[] = $estado_verificacion;
        $types .= "s";
    }
    
    if (!empty($estado_activo)) {
        $where[] = "activo = ?";
        $params[] = ($estado_activo === 'activos') ? 1 : 0;
        $types .= "i";
    }
    
    $where_clause = empty($where) ? "1=1" : implode(" AND ", $where);
    
    // Consulta optimizada para contar registros
    $sql_count = "SELECT COUNT(*) as total FROM distribuidores WHERE $where_clause";
    $stmt_count = $conn->prepare($sql_count);
    if (!empty($params)) $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_registros = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
    
    $total_paginas = ceil($total_registros / $limit);
    
    // Consulta principal optimizada (solo campos necesarios)
    $sql = "SELECT 
                id, numero_control, nombre_distribuidor, telefono, email, rfc,
                banco, numero_cuenta, constancia_fiscal, credencial_identificacion,
                estado_verificacion, activo
            FROM distribuidores 
            WHERE $where_clause 
            ORDER BY fecha_registro DESC 
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Función auxiliar para clase de estado con colores mejorados
    function claseEstado($estado) {
        $map = [
            'pendiente' => 'warning', 
            'en_revision' => 'purple', 
            'aprobado' => 'success', 
            'rechazado' => 'danger'
        ];
        return $map[$estado] ?? 'secondary';
    }
    
    function textoEstado($estado) {
        $map = [
            'pendiente' => 'Pendiente', 
            'en_revision' => 'En Revisión', 
            'aprobado' => 'Aprobado', 
            'rechazado' => 'Rechazado'
        ];
        return $map[$estado] ?? $estado;
    }
    
    function colorEstadoVerificacion($estado) {
        $map = [
            'pendiente' => '#ffc107',
            'en_revision' => '#6f42c1',
            'aprobado' => '#28a745',
            'rechazado' => '#dc3545'
        ];
        return $map[$estado] ?? '#6c757d';
    }
    
    // Generar HTML optimizado con colores mejorados
    ob_start();
    ?>
    <style>
        /* Estilos adicionales para mejor visualización */
        .badge-purple {
            background-color: #6f42c1;
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(39, 174, 96, 0.05);
            transition: all 0.3s ease;
        }
        .badge-activo-modern {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
            display: inline-block;
        }
        .badge-inactivo-modern {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
            display: inline-block;
        }
        .btn-documento {
            transition: all 0.3s ease;
        }
        .btn-documento:hover {
            transform: scale(1.05);
        }
        .numero-control-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-block;
        }
        .contacto-info i {
            color: #27ae60;
            width: 20px;
        }
        .card-distribuidor-movil {
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        .card-distribuidor-movil:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
    </style>
    
    <!-- Vista para Desktop: Tabla -->
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light" style="background: linear-gradient(135deg, #f8f9fa, #e9ecef);">
                <tr>
                    <th># Control</th>
                    <th>Distribuidor</th>
                    <th>Contacto</th>
                    <th>RFC</th>
                    <th>Banco/Cuenta</th>
                    <th>Verificación</th>
                    <th>Documentos</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows === 0): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="fas fa-users fa-3x text-muted"></i>
                            <p class="mt-2">No hay distribuidores</p>
                        </td>
                    </tr>
                <?php else: while ($row = $result->fetch_assoc()): ?>
                    <tr style="border-left: 3px solid <?php echo colorEstadoVerificacion($row['estado_verificacion'] ?? ''); ?>;">
                        <td>
                            <span class="numero-control-badge">
                                <i class="fas fa-hashtag me-1" style="font-size: 0.7rem;"></i>
                                <?php echo htmlspecialchars($row['numero_control'] ?? ''); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($row['nombre_distribuidor'] ?? ''); ?></strong>
                        </td>
                        <td class="contacto-info">
                            <div><i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($row['telefono'] ?? ''); ?></div>
                            <div><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($row['email'] ?? ''); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-secondary" style="font-family: monospace;">
                                <?php echo htmlspecialchars($row['rfc'] ?? ''); ?>
                            </span>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo htmlspecialchars($row['banco'] ?? ''); ?></small><br>
                            <small class="text-primary font-monospace"><?php echo htmlspecialchars($row['numero_cuenta'] ?? ''); ?></small>
                        </td>
                        <td>
                            <?php
                            $estado = $row['estado_verificacion'] ?? '';
                            $badgeClass = $estado === 'en_revision' ? 'badge-purple' : 'bg-' . claseEstado($estado);
                            ?>
                            <span class="badge <?php echo $badgeClass; ?> px-3 py-2">
                                <?php if($estado === 'pendiente'): ?>
                                    <i class="fas fa-clock me-1"></i>
                                <?php elseif($estado === 'en_revision'): ?>
                                    <i class="fas fa-search me-1"></i>
                                <?php elseif($estado === 'aprobado'): ?>
                                    <i class="fas fa-check-circle me-1"></i>
                                <?php elseif($estado === 'rechazado'): ?>
                                    <i class="fas fa-times-circle me-1"></i>
                                <?php endif; ?>
                                <?php echo textoEstado($estado); ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <?php if (!empty($row['constancia_fiscal'])): ?>
                                    <button class="btn btn-outline-primary btn-documento ver-archivo" 
                                        data-archivo="/Distribuidor/uploads/distribuidores/constancias/<?php echo htmlspecialchars($row['constancia_fiscal']); ?>" 
                                        data-tipo="<?php echo strpos($row['constancia_fiscal'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>" 
                                        data-nombre="<?php echo htmlspecialchars(basename($row['constancia_fiscal'])); ?>" 
                                        data-titulo="Constancia Fiscal"
                                        title="Ver constancia fiscal">
                                        <i class="fas fa-file-pdf"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($row['credencial_identificacion'])): ?>
                                    <button class="btn btn-outline-primary btn-documento ver-archivo" 
                                        data-archivo="/Distribuidor/uploads/distribuidores/credenciales/<?php echo htmlspecialchars($row['credencial_identificacion']); ?>" 
                                        data-tipo="<?php echo strpos($row['credencial_identificacion'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>" 
                                        data-nombre="<?php echo htmlspecialchars(basename($row['credencial_identificacion'])); ?>" 
                                        data-titulo="Identificación"
                                        title="Ver identificación">
                                        <i class="fas fa-id-card"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if($row['activo']): ?>
                                <span class="badge-activo-modern">
                                    <i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i>
                                    Activo
                                </span>
                            <?php else: ?>
                                <span class="badge-inactivo-modern">
                                    <i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i>
                                    Inactivo
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-info" onclick="verDetalle(<?php echo $row['id']; ?>)" title="Ver detalles">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="distribuidor_editar.php?id=<?php echo $row['id']; ?>" class="btn btn-warning" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button class="btn <?php echo $row['activo'] ? 'btn-danger' : 'btn-success'; ?>" 
                                    onclick="confirmarAccion(<?php echo $row['id']; ?>, '<?php echo $row['activo'] ? 'desactivar' : 'activar'; ?>')"
                                    title="<?php echo $row['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                    <i class="fas fa-<?php echo $row['activo'] ? 'ban' : 'check-circle'; ?>"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Vista para Móvil: Tarjetas con colores mejorados -->
    <div class="d-block d-md-none">
        <?php if ($result->num_rows === 0): ?>
            <div class="text-center py-5">
                <i class="fas fa-users fa-3x text-muted"></i>
                <p class="mt-2">No hay distribuidores</p>
            </div>
        <?php else: 
            // Resetear el puntero del resultado para recorrerlo nuevamente
            $result->data_seek(0);
            while ($row = $result->fetch_assoc()): 
                $borderColor = colorEstadoVerificacion($row['estado_verificacion'] ?? '');
        ?>
            <div class="card mb-3 distribuidor-card card-distribuidor-movil" style="border-left-color: <?php echo $borderColor; ?>;">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <div>
                        <span class="numero-control-badge">
                            <i class="fas fa-hashtag me-1"></i>
                            <?php echo htmlspecialchars($row['numero_control'] ?? ''); ?>
                        </span>
                        <h6 class="mb-0 mt-2"><?php echo htmlspecialchars($row['nombre_distribuidor'] ?? ''); ?></h6>
                    </div>
                    <?php
                    $estado = $row['estado_verificacion'] ?? '';
                    $badgeClass = $estado === 'en_revision' ? 'badge-purple' : 'bg-' . claseEstado($estado);
                    ?>
                    <span class="badge <?php echo $badgeClass; ?> px-3 py-2">
                        <?php if($estado === 'pendiente'): ?>
                            <i class="fas fa-clock me-1"></i>
                        <?php elseif($estado === 'en_revision'): ?>
                            <i class="fas fa-search me-1"></i>
                        <?php elseif($estado === 'aprobado'): ?>
                            <i class="fas fa-check-circle me-1"></i>
                        <?php elseif($estado === 'rechazado'): ?>
                            <i class="fas fa-times-circle me-1"></i>
                        <?php endif; ?>
                        <?php echo textoEstado($estado); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-5 text-muted"><i class="fas fa-phone-alt me-1"></i> Teléfono:</div>
                        <div class="col-7"><?php echo htmlspecialchars($row['telefono'] ?? ''); ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-5 text-muted"><i class="fas fa-envelope me-1"></i> Email:</div>
                        <div class="col-7"><?php echo htmlspecialchars($row['email'] ?? ''); ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-5 text-muted"><i class="fas fa-id-card me-1"></i> RFC:</div>
                        <div class="col-7"><code><?php echo htmlspecialchars($row['rfc'] ?? ''); ?></code></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-5 text-muted"><i class="fas fa-university me-1"></i> Banco:</div>
                        <div class="col-7"><?php echo htmlspecialchars($row['banco'] ?? ''); ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-5 text-muted"><i class="fas fa-credit-card me-1"></i> Cuenta:</div>
                        <div class="col-7"><strong><?php echo htmlspecialchars($row['numero_cuenta'] ?? ''); ?></strong></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-5 text-muted"><i class="fas fa-file-alt me-1"></i> Documentos:</div>
                        <div class="col-7">
                            <div class="btn-group btn-group-sm flex-wrap">
                                <?php if (!empty($row['constancia_fiscal'])): ?>
                                    <button class="btn btn-outline-primary ver-archivo mb-1 me-1" 
                                        data-archivo="/Distribuidor/uploads/distribuidores/constancias/<?php echo htmlspecialchars($row['constancia_fiscal']); ?>" 
                                        data-tipo="<?php echo strpos($row['constancia_fiscal'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>" 
                                        data-nombre="<?php echo htmlspecialchars(basename($row['constancia_fiscal'])); ?>" 
                                        data-titulo="Constancia Fiscal">
                                        <i class="fas fa-file-pdf"></i> Constancia
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($row['credencial_identificacion'])): ?>
                                    <button class="btn btn-outline-primary ver-archivo mb-1" 
                                        data-archivo="/Distribuidor/uploads/distribuidores/credenciales/<?php echo htmlspecialchars($row['credencial_identificacion']); ?>" 
                                        data-tipo="<?php echo strpos($row['credencial_identificacion'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>" 
                                        data-nombre="<?php echo htmlspecialchars(basename($row['credencial_identificacion'])); ?>" 
                                        data-titulo="Identificación">
                                        <i class="fas fa-id-card"></i> Credencial
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-5 text-muted"><i class="fas fa-power-off me-1"></i> Estado:</div>
                        <div class="col-7">
                            <?php if($row['activo']): ?>
                                <span class="badge-activo-modern">
                                    <i class="fas fa-circle me-1"></i> Activo
                                </span>
                            <?php else: ?>
                                <span class="badge-inactivo-modern">
                                    <i class="fas fa-circle me-1"></i> Inactivo
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-between gap-2">
                        <button class="btn btn-info btn-sm flex-fill" onclick="verDetalle(<?php echo $row['id']; ?>)">
                            <i class="fas fa-eye"></i> Ver
                        </button>
                        <a href="distribuidor_editar.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm flex-fill">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <button class="btn <?php echo $row['activo'] ? 'btn-danger' : 'btn-success'; ?> btn-sm flex-fill" 
                            onclick="confirmarAccion(<?php echo $row['id']; ?>, '<?php echo $row['activo'] ? 'desactivar' : 'activar'; ?>')">
                            <i class="fas fa-<?php echo $row['activo'] ? 'ban' : 'check-circle'; ?>"></i>
                            <?php echo $row['activo'] ? 'Desactivar' : 'Activar'; ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; endif; ?>
    </div>
    
    <?php if ($total_paginas > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination justify-content-center mb-0 flex-wrap">
                    <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" data-page="<?php echo $pagina - 1; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php 
                    // Generar páginas responsive
                    $max_pages_visible = 5;
                    $start_page = max(1, $pagina - floor($max_pages_visible / 2));
                    $end_page = min($total_paginas, $start_page + $max_pages_visible - 1);
                    $start_page = max(1, $end_page - $max_pages_visible + 1);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                            <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($end_page < $total_paginas): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <li class="page-item">
                            <a class="page-link" href="#" data-page="<?php echo $total_paginas; ?>"><?php echo $total_paginas; ?></a>
                        </li>
                    <?php endif; ?>
                    <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" data-page="<?php echo $pagina + 1; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="text-center mt-2">
                <small class="text-muted">
                    Mostrando <?php echo $result->num_rows; ?> de <?php echo $total_registros; ?> distribuidores
                </small>
            </div>
        </div>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();
    
    // Obtener estadísticas solo si es necesario
    $estadisticas = null;
    if ($pagina === 1 || !empty($busqueda) || !empty($estado_verificacion) || !empty($estado_activo)) {
        $sql_stats = "SELECT 
            COUNT(*) as total,
            SUM(estado_verificacion = 'aprobado') as aprobados,
            SUM(estado_verificacion = 'pendiente') as pendientes,
            SUM(estado_verificacion = 'en_revision') as en_revision,
            SUM(estado_verificacion = 'rechazado') as rechazados
            FROM distribuidores WHERE $where_clause";
        
        $stmt_stats = $conn->prepare($sql_stats);
        $params_stats = array_slice($params, 0, -2);
        $types_stats = substr($types, 0, -2);
        if (!empty($params_stats)) $stmt_stats->bind_param($types_stats, ...$params_stats);
        $stmt_stats->execute();
        $estadisticas = $stmt_stats->get_result()->fetch_assoc();
        $stmt_stats->close();
    }
    
    echo json_encode([
        'success' => true,
        'html' => $html,
        'estadisticas' => $estadisticas
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'mensaje' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>