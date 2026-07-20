<?php
// ajax_pagos.php

// Configuración de error reporting para debug
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1);

// Configuración de sesión
$custom_session_path = '/home2/juanc141/tmp_sessions';
if (!is_dir($custom_session_path)) {
    mkdir($custom_session_path, 0777, true);
}
session_save_path($custom_session_path);
session_start();

// Función para respuesta JSON
function sendJSONResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// Verificar autenticación
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    sendJSONResponse(['success' => false, 'message' => 'No autorizado']);
}

// Configuración de conexión
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Conexión a la base de datos
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    sendJSONResponse(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]);
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
    
    sendJSONResponse([
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
    
    // Filtro estado
    if (!empty($filtro_estado)) {
        if ($filtro_estado === 'COMPLETED') {
            $where .= " AND response = 'approved'";
        } elseif ($filtro_estado === 'FAILED') {
            $where .= " AND response = 'denied'";
        }
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
    
    sendJSONResponse([
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
    
    // Verificar si existe la tabla
    $check_table = $conn->query("SHOW TABLES LIKE 'pagos_spei_recibidos'");
    if ($check_table->num_rows === 0) {
        sendJSONResponse([
            'success' => true,
            'transacciones' => [],
            'total_registros' => 0,
            'total_paginas' => 0,
            'pagina_actual' => 1,
            'message' => 'Tabla pagos_spei_recibidos no existe'
        ]);
    }
    
    // Construir WHERE
    $where = "WHERE 1=1";
    $params = [];
    $types = "";
    
    // Filtro estado para pagos_spei_recibidos
    if (!empty($filtro_estado)) {
        if ($filtro_estado === 'COMPLETED') {
            $where .= " AND estado IN ('confirmado')";
        } elseif ($filtro_estado === 'PENDING') {
            $where .= " AND estado = 'pendiente'";
        } elseif ($filtro_estado === 'FAILED') {
            $where .= " AND estado IN ('rechazado', 'cancelado')";
        }
    }
    
    // Búsqueda
    if (!empty($busqueda)) {
        $where .= " AND (clabe LIKE ? OR referencia LIKE ? OR numero_autorizacion LIKE ? OR transaccion LIKE ? OR nombre_empresa LIKE ?)";
        $busqueda_param = "%" . $busqueda . "%";
        for ($i = 0; $i < 5; $i++) {
            $params[] = $busqueda_param;
            $types .= "s";
        }
    }
    
    // Filtros fecha
    if (!empty($filtro_fecha_inicio)) {
        $where .= " AND DATE(fecha_recibido) >= ?";
        $params[] = $filtro_fecha_inicio;
        $types .= "s";
    }
    
    if (!empty($filtro_fecha_fin)) {
        $where .= " AND DATE(fecha_recibido) <= ?";
        $params[] = $filtro_fecha_fin;
        $types .= "s";
    }
    
    // Contar total
    $sql_count = "SELECT COUNT(*) as total FROM pagos_spei_recibidos $where";
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
                clabe_id,
                clabe,
                monto_recibido as monto,
                fecha_recibido as fecha_solicitud,
                referencia as transaccion_externa,
                numero_autorizacion as autorizacion,
                estado,
                nombre_empresa,
                fecha_confirmacion
            FROM pagos_spei_recibidos
            $where 
            ORDER BY fecha_recibido DESC 
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
    
    sendJSONResponse([
        'success' => true,
        'transacciones' => $transacciones,
        'total_registros' => $total_registros,
        'total_paginas' => $total_paginas,
        'pagina_actual' => $pagina
    ]);
    
} else {
    sendJSONResponse(['success' => false, 'message' => 'Acción no válida: ' . $action]);
}

$conn->close();
?>