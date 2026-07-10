<?php
// ajax_pagos.php
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);
session_start();

// Verificar autenticación
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Configuración de conexión
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Conexión a la base de datos
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit();
}

// Obtener acción
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'get_paypal') {
    // Obtener parámetros
    $pagina = isset($_POST['pagina']) ? max(1, intval($_POST['pagina'])) : 1;
    $registros_por_pagina = isset($_POST['registros_por_pagina']) ? intval($_POST['registros_por_pagina']) : 5;
    $filtro_estado = isset($_POST['estado']) ? $_POST['estado'] : '';
    $filtro_fecha_inicio = isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : '';
    $filtro_fecha_fin = isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : '';
    $busqueda = isset($_POST['busqueda']) ? $_POST['busqueda'] : '';
    
    // Construir WHERE
    $where = "WHERE 1=1";
    $params = [];
    $types = "";
    
    // Filtro estado
    if (!empty($filtro_estado)) {
        if ($filtro_estado === 'COMPLETED') {
            $where .= " AND status = 'COMPLETED'";
        } elseif ($filtro_estado === 'PENDING') {
            $where .= " AND status = 'PENDING'";
        } elseif ($filtro_estado === 'FAILED') {
            $where .= " AND (status NOT IN ('COMPLETED', 'PENDING') OR status IS NULL)";
        }
    }
    
    // Búsqueda
    if (!empty($busqueda)) {
        $where .= " AND (payment_id LIKE ? OR payer_email LIKE ? OR payer_name LIKE ? OR transaction_id LIKE ?)";
        $busqueda_param = "%" . $busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $types .= "ssss";
    }
    
    // Filtros fecha
    if (!empty($filtro_fecha_inicio)) {
        $where .= " AND DATE(created_at) >= ?";
        $params[] = $filtro_fecha_inicio;
        $types .= "s";
    }
    
    if (!empty($filtro_fecha_fin)) {
        $where .= " AND DATE(created_at) <= ?";
        $params[] = $filtro_fecha_fin;
        $types .= "s";
    }
    
    // Contar total
    $sql_count = "SELECT COUNT(*) as total FROM pagos_paypal $where";
    $stmt_count = $conn->prepare($sql_count);
    
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_registros = $result_count->fetch_assoc()['total'];
    $stmt_count->close();
    
    // Calcular paginación
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina - 1) * $registros_por_pagina;
    
    // Consulta principal
    $sql = "SELECT 
                id,
                payment_id,
                payer_id,
                payer_email,
                payer_name,
                transaction_id,
                amount,
                currency,
                status,
                payment_data,
                cart_data,
                webhook_data,
                created_at,
                updated_at
            FROM pagos_paypal
            $where 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    
    // Preparar parámetros
    if (!empty($params)) {
        $params_with_limit = array_merge($params, [$registros_por_pagina, $offset]);
        $types_with_limit = $types . "ii";
        $stmt->bind_param($types_with_limit, ...$params_with_limit);
    } else {
        $stmt->bind_param("ii", $registros_por_pagina, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pagos = [];
    while ($row = $result->fetch_assoc()) {
        // Calcular items_count
        $items_count = 0;
        if (!empty($row['cart_data'])) {
            $cartData = json_decode($row['cart_data'], true);
            if (is_array($cartData)) {
                if (isset($cartData['productos']) && is_array($cartData['productos'])) {
                    $items_count = count($cartData['productos']);
                } elseif (isset($cartData['items']) && is_array($cartData['items'])) {
                    $items_count = count($cartData['items']);
                } elseif (isset($cartData['products']) && is_array($cartData['products'])) {
                    $items_count = count($cartData['products']);
                } else {
                    $items_count = count($cartData);
                }
            }
        }
        $row['items_count'] = $items_count;
        $pagos[] = $row;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'pagos' => $pagos,
        'total_registros' => $total_registros,
        'total_paginas' => $total_paginas,
        'pagina_actual' => $pagina
    ]);
    
} elseif ($action === 'get_liga') {
    // Obtener parámetros
    $pagina = isset($_POST['pagina']) ? max(1, intval($_POST['pagina'])) : 1;
    $registros_por_pagina = isset($_POST['registros_por_pagina']) ? intval($_POST['registros_por_pagina']) : 5;
    $filtro_estado = isset($_POST['estado']) ? $_POST['estado'] : '';
    $filtro_fecha_inicio = isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : '';
    $filtro_fecha_fin = isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : '';
    $busqueda = isset($_POST['busqueda']) ? $_POST['busqueda'] : '';
    
    // Construir WHERE
    $where = "WHERE 1=1";
    $params = [];
    $types = "";
    
    // Filtro estado - CORREGIDO con valores reales
    if (!empty($filtro_estado)) {
        if ($filtro_estado === 'COMPLETED') {
            $where .= " AND response = 'approved'";
        } elseif ($filtro_estado === 'FAILED') {
            $where .= " AND response = 'denied'";
        }
        // PENDING no tiene correspondencia en Liga
    }
    
    // Búsqueda
    if (!empty($busqueda)) {
        $where .= " AND (reference LIKE ? OR email LIKE ? OR cc_name LIKE ? OR foliocpagos LIKE ? OR auth LIKE ?)";
        $busqueda_param = "%" . $busqueda . "%";
        for ($i = 0; $i < 5; $i++) {
            $params[] = $busqueda_param;
            $types .= "s";
        }
    }
    
    // Filtros fecha
    if (!empty($filtro_fecha_inicio)) {
        $where .= " AND DATE(fecha_registro) >= ?";
        $params[] = $filtro_fecha_inicio;
        $types .= "s";
    }
    
    if (!empty($filtro_fecha_fin)) {
        $where .= " AND DATE(fecha_registro) <= ?";
        $params[] = $filtro_fecha_fin;
        $types .= "s";
    }
    
    // Contar total
    $sql_count = "SELECT COUNT(*) as total FROM pagos_liga $where";
    $stmt_count = $conn->prepare($sql_count);
    
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_registros = $result_count->fetch_assoc()['total'];
    $stmt_count->close();
    
    // Calcular paginación
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina - 1) * $registros_por_pagina;
    
    // Consulta principal
    $sql = "SELECT 
                id,
                reference,
                response,
                foliocpagos,
                auth,
                cd_response,
                cd_error,
                nb_error,
                time,
                date,
                nb_company,
                nb_merchant,
                cc_type,
                tp_operation,
                cc_name,
                cc_number,
                cc_expmonth,
                cc_expyear,
                amount,
                emv_key_date,
                id_url,
                email,
                payment_type,
                promocion,
                number_tkn,
                cc_mask,
                raw_response,
                fecha_registro
            FROM pagos_liga
            $where 
            ORDER BY fecha_registro DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    
    // Preparar parámetros
    if (!empty($params)) {
        $params_with_limit = array_merge($params, [$registros_por_pagina, $offset]);
        $types_with_limit = $types . "ii";
        $stmt->bind_param($types_with_limit, ...$params_with_limit);
    } else {
        $stmt->bind_param("ii", $registros_por_pagina, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pagos = [];
    while ($row = $result->fetch_assoc()) {
        $pagos[] = $row;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'pagos' => $pagos,
        'total_registros' => $total_registros,
        'total_paginas' => $total_paginas,
        'pagina_actual' => $pagina
    ]);
    
} elseif ($action === 'get_spei') {
    // Obtener parámetros
    $pagina = isset($_POST['pagina']) ? max(1, intval($_POST['pagina'])) : 1;
    $registros_por_pagina = isset($_POST['registros_por_pagina']) ? intval($_POST['registros_por_pagina']) : 5;
    $filtro_estado = isset($_POST['estado']) ? $_POST['estado'] : '';
    $filtro_fecha_inicio = isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : '';
    $filtro_fecha_fin = isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : '';
    $busqueda = isset($_POST['busqueda']) ? $_POST['busqueda'] : '';
    
    // Construir WHERE
    $where = "WHERE 1=1";
    $params = [];
    $types = "";
    
    // Filtro estado (según código_respuesta)
    if (!empty($filtro_estado)) {
        if ($filtro_estado === 'COMPLETED') {
            $where .= " AND codigo_respuesta = 200";
        } elseif ($filtro_estado === 'FAILED') {
            $where .= " AND codigo_respuesta != 200 AND codigo_respuesta != 0";
        }
        // PENDING no aplica para SPEI
    }
    
    // Búsqueda
    if (!empty($busqueda)) {
        $where .= " AND (clabe LIKE ? OR transaccion_externa LIKE ? OR autorizacion LIKE ? OR mensaje_respuesta LIKE ?)";
        $busqueda_param = "%" . $busqueda . "%";
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $params[] = $busqueda_param;
        $types .= "ssss";
    }
    
    // Filtros fecha (usando fecha_solicitud)
    if (!empty($filtro_fecha_inicio)) {
        $where .= " AND DATE(fecha_solicitud) >= ?";
        $params[] = $filtro_fecha_inicio;
        $types .= "s";
    }
    
    if (!empty($filtro_fecha_fin)) {
        $where .= " AND DATE(fecha_solicitud) <= ?";
        $params[] = $filtro_fecha_fin;
        $types .= "s";
    }
    
    // Contar total
    $sql_count = "SELECT COUNT(*) as total FROM spei_transacciones $where";
    $stmt_count = $conn->prepare($sql_count);
    
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_registros = $result_count->fetch_assoc()['total'];
    $stmt_count->close();
    
    // Calcular paginación
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina - 1) * $registros_por_pagina;
    
    // Consulta principal
    $sql = "SELECT 
                id,
                clabe,
                monto,
                fecha_solicitud,
                transaccion_externa,
                codigo_respuesta,
                autorizacion,
                mensaje_respuesta,
                fecha_respuesta,
                ip_origen,
                user_agent,
                fecha_registro
            FROM spei_transacciones
            $where 
            ORDER BY fecha_solicitud DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    
    // Preparar parámetros
    if (!empty($params)) {
        $params_with_limit = array_merge($params, [$registros_por_pagina, $offset]);
        $types_with_limit = $types . "ii";
        $stmt->bind_param($types_with_limit, ...$params_with_limit);
    } else {
        $stmt->bind_param("ii", $registros_por_pagina, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transacciones = [];
    while ($row = $result->fetch_assoc()) {
        $transacciones[] = $row;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'transacciones' => $transacciones,
        'total_registros' => $total_registros,
        'total_paginas' => $total_paginas,
        'pagina_actual' => $pagina
    ]);
    
} else {
    echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

$conn->close();
?>