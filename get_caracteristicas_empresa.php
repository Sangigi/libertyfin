<?php
/**
 * Obtiene las características habilitadas para una empresa
 * @param mysqli $conn_main Conexión a la base de datos principal
 * @param int $empresa_id ID de la empresa
 * @return array Configuración de características
 */
function obtenerCaracteristicasEmpresa($conn_main, $empresa_id) {
    $caracteristicas = [
        'precio_compra' => true,
        'unidad_medida' => true,
        'proveedor' => true,
        'fecha_caducidad' => true,
        'categoria' => true,
        'config_unidad_medida' => 'pieza',
        'proveedores_permitidos' => [],
        'categorias_permitidas' => []
    ];
    
    $sql = "SELECT caracteristica, habilitado, configuracion_extra 
            FROM empresa_caracteristicas 
            WHERE empresa_id = ?";
    $stmt = $conn_main->prepare($sql);
    $stmt->bind_param("i", $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        switch ($row['caracteristica']) {
            case 'precio_compra':
                $caracteristicas['precio_compra'] = $row['habilitado'] == 1;
                break;
            case 'unidad_medida':
                $caracteristicas['unidad_medida'] = $row['habilitado'] == 1;
                if ($row['configuracion_extra']) {
                    $caracteristicas['config_unidad_medida'] = $row['configuracion_extra'];
                }
                break;
            case 'proveedor':
                $caracteristicas['proveedor'] = $row['habilitado'] == 1;
                if ($row['configuracion_extra']) {
                    $caracteristicas['proveedores_permitidos'] = json_decode($row['configuracion_extra'], true) ?: [];
                }
                break;
            case 'fecha_caducidad':
                $caracteristicas['fecha_caducidad'] = $row['habilitado'] == 1;
                break;
            case 'categoria':
                $caracteristicas['categoria'] = $row['habilitado'] == 1;
                if ($row['configuracion_extra']) {
                    $caracteristicas['categorias_permitidas'] = json_decode($row['configuracion_extra'], true) ?: [];
                }
                break;
        }
    }
    $stmt->close();
    
    return $caracteristicas;
}

/**
 * Filtra proveedores según configuración
 */
function filtrarProveedores($conn_empresa, $proveedores_permitidos) {
    if (empty($proveedores_permitidos)) {
        $sql = "SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre";
        $result = $conn_empresa->query($sql);
    } else {
        $ids = implode(',', array_map('intval', $proveedores_permitidos));
        $sql = "SELECT id, nombre FROM proveedores WHERE activo = 1 AND id IN ($ids) ORDER BY nombre";
        $result = $conn_empresa->query($sql);
    }
    
    $proveedores = [];
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = $row;
    }
    return $proveedores;
}

/**
 * Filtra categorías según configuración
 */
function filtrarCategorias($conn_empresa, $categorias_permitidas) {
    if (empty($categorias_permitidas)) {
        $sql = "SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre";
        $result = $conn_empresa->query($sql);
    } else {
        $ids = implode(',', array_map('intval', $categorias_permitidas));
        $sql = "SELECT id, nombre FROM categorias WHERE activo = 1 AND id IN ($ids) ORDER BY nombre";
        $result = $conn_empresa->query($sql);
    }
    
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row;
    }
    return $categorias;
}
?>