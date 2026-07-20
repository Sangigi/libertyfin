<?php
    session_start();

    // Verificar si el usuario está logueado
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: login.php");
        exit();
    }

    // Configuración de la base de datos
    $servername = "libertyfin.com.mx";
    $username = "juanc141_alexis";
    $password = "Alexis1997";
    $dbname = $_SESSION['empresa_db'];

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

        // OBTENER LOGO DE LA EMPRESA
        $logo_empresa = null;
        $logo_src_base64 = null;

        if (!empty($empresa_info['logo'])) {
            $empresa_logo = $empresa_info['logo'];
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

            // Si encontramos el logo, convertirlo a base64
            if (!empty($logo_path) && file_exists($logo_path)) {
                $logo_empresa = $logo_path;

                // Obtener la extensión del archivo
                $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));

                // Verificar que sea una imagen válida
                $extensiones_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                if (in_array($extension, $extensiones_validas)) {
                    // Leer el archivo y convertirlo a base64
                    $logo_data = base64_encode(file_get_contents($logo_path));
                    $logo_src_base64 = 'data:image/' . $extension . ';base64,' . $logo_data;
                }
            }
        }

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

        // OBTENER EL PLAN DE LA EMPRESA Y DATOS DE TIMBRES DESDE LA BASE DE DATOS PRINCIPAL
        $servername_main = "libertyfin.com.mx";
        $username_main = "juanc141_alexis";
        $password_main = "Alexis1997";
        $dbname_main = "juanc141_ventas";

        $conn_main = new mysqli($servername_main, $username_main, $password_main, $dbname_main);

        // Valores por defecto
        $empresa_plan = "prueba";
        $timbres_totales = 0;
        $timbres_disponibles = 0;

        if ($conn_main) {
            $sql_empresa = "SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?";
            $stmt_empresa = $conn_main->prepare($sql_empresa);
            $stmt_empresa->bind_param("i", $_SESSION['empresa_id']);
            $stmt_empresa->execute();
            $result_empresa = $stmt_empresa->get_result();

            if ($result_empresa->num_rows > 0) {
                $empresa_data = $result_empresa->fetch_assoc();
                $empresa_plan = $empresa_data['plan'];
                $timbres_totales = $empresa_data['timbres_totales'] ?? 0;
                $timbres_disponibles = $empresa_data['timbres_disponibles'] ?? 0;
            }
            $stmt_empresa->close();
            $conn_main->close();
        }

        // Guardar el plan en la sesión
        $_SESSION['empresa_plan'] = $empresa_plan;

        // =============================================
        // INCLUIR Y PROCESAR LA RESPUESTA DE EMIDA
        // =============================================
        $servicios_emida = [];
        $error_emida = '';

        // Incluir el archivo de ProductFlowInfoService
        ob_start(); // Capturar cualquier salida
        include '../Emida/ProductFlowInfoService.php';
        $output = ob_get_clean();

        // Buscar la respuesta XML en el output
        if (preg_match('/<pre>(.*?)<\/pre>/s', $output, $matches)) {
            $response_xml = html_entity_decode($matches[1]);

            // Extraer el contenido XML de la respuesta SOAP
            if (preg_match('/<return xsi:type="xsd:string">(.*?)<\/return>/s', $response_xml, $matches_inner)) {
                $xml_contenido = html_entity_decode($matches_inner[1]);

                // Cargar el XML interno
                $internal_xml = simplexml_load_string($xml_contenido);

                if ($internal_xml) {
                    // Buscar todos los productos
                    $productos = $internal_xml->xpath('//Product');

                    foreach ($productos as $producto) {
                        // Procesar referencias para extraer información adicional
                        $referencias = [];
                        $refs = $producto->xpath('.//Reference1 | .//Reference2');
                        foreach ($refs as $ref) {
                            $referencias[] = [
                                'nombre' => (string)$ref->ReferenceName,
                                'tipo' => (string)$ref->FieldType,
                                'min' => (string)$ref->LengthMin,
                                'max' => (string)$ref->LengthMax,
                                'prefijo' => (string)$ref->Prefix,
                                'tooltip' => (string)$ref->ToolTip,
                                'imagen' => (string)$ref->URLImage
                            ];
                        }

                        $servicio = [
                            'id' => (string)$producto->ProductId,
                            'nombre' => (string)$producto->ProductName,
                            'categoria' => (string)$producto->ProductCategory,
                            'subcategoria' => (string)$producto->ProductSubCategory,
                            'carrier' => (string)$producto->CarrierName,
                            'flow_type' => (string)$producto->FlowType,
                            'comision' => (float)$producto->ProductUFee,
                            'moneda' => (string)$producto->CurrencyCode,
                            'monto' => (float)$producto->Amount,
                            'monto_min' => (float)$producto->AmountMin,
                            'monto_max' => (float)$producto->AmountMax,
                            'payment_type' => (string)$producto->PaymentType,
                            'referencias' => $referencias
                        ];

                        $servicios_emida[] = $servicio;
                    }
                } else {
                    $error_emida = 'Error al procesar el XML de respuesta';
                }
            } else {
                $error_emida = 'No se pudo extraer la respuesta XML';
            }
        } else {
            // Si no encontramos la respuesta en formato pre, tomamos toda la salida
            if (strpos($output, 'Error CURL') !== false) {
                $error_emida = $output;
            } else {
                $error_emida = 'No se pudo obtener respuesta de la API';
            }
        }

        // Obtener terminal_id y clerk_id de la sesión o configuración
        $terminal_id = $_SESSION['terminal_id'] ?? '4418653';
        $clerk_id = $_SESSION['clerk_id'] ?? 'e55it7';

        // =============================================
        // VERIFICAR ESTADO DE NOTIFICACIONES DE PAGO
        // =============================================
        $notification_status = null;
        try {
            if (file_exists('../Emida/SubmitPaymentNotificationUtil.php')) {
                include_once '../Emida/SubmitPaymentNotificationUtil.php';

                // Verificar si la función existe antes de llamarla
                if (function_exists('checkPaymentNotificationStatus')) {
                    $notification_status = checkPaymentNotificationStatus();

                    // Si no está configurado correctamente, registrar para debugging
                    if (
                        !$notification_status['success'] ||
                        (isset($notification_status['notification_status']) &&
                            !$notification_status['notification_status']['success'])
                    ) {
                        error_log("Advertencia: Notificaciones de pago Emida no configuradas");
                    }
                }
            } else {
                $notification_status = [
                    'success' => false,
                    'message' => 'Archivo de notificaciones no encontrado'
                ];
            }
        } catch (Exception $e) {
            error_log("Error al verificar notificaciones: " . $e->getMessage());
            $notification_status = [
                'success' => false,
                'message' => 'Error al verificar: ' . $e->getMessage()
            ];
        }
    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }
    ?>

    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Dashboard - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
        <link rel="icon" href="images/favicon.ico" type="image/x-icon">
        <!-- Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary-color: <?php echo getConfigValue($empresa_info, 'color_primario', '#27ae60'); ?>;
                --secondary-color: <?php echo getConfigValue($empresa_info, 'color_secundario', '#2ecc71'); ?>;
            }

            body {
                background-color: #f8f9fa;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                touch-action: pan-y;
                overflow-x: hidden;
            }

            .navbar {
                background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            }

            .navbar-brand img {
                height: 40px;
                width: auto;
                max-width: 120px;
                object-fit: contain;
                border-radius: 4px;
            }

            .sidebar {
                background: #2c3e50;
                color: white;
                min-height: calc(100vh - 56px);
                transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
                will-change: transform;
            }

            .sidebar .nav-link {
                color: #ecf0f1;
                padding: 12px 20px;
                border-left: 3px solid transparent;
                transition: all 0.3s ease;
            }

            .sidebar .nav-link:hover {
                background: rgba(255, 255, 255, 0.1);
                border-left-color: var(--secondary-color);
                color: white;
            }

            .sidebar .nav-link.active {
                background: rgba(255, 255, 255, 0.1);
                border-left-color: var(--secondary-color);
                color: white;
            }

            .sidebar .nav-link i {
                width: 20px;
                margin-right: 10px;
            }

            .card {
                border: none;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                transition: transform 0.3s ease;
            }

            .card:hover {
                transform: translateY(-2px);
            }

            .stat-card {
                border-left: 4px solid var(--primary-color);
            }

            .stat-card .card-body {
                padding: 1.5rem;
            }

            .welcome-card {
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
            }

            .welcome-card .logo-placeholder {
                width: 60px;
                height: 60px;
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.1);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
            }

            .ingresos-card {
                background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                color: white;
            }

            .btn-primary {
                background-color: var(--primary-color);
                border-color: var(--primary-color);
            }

            .btn-primary:hover {
                background-color: var(--secondary-color);
                border-color: var(--secondary-color);
            }

            .btn-success {
                background-color: var(--secondary-color);
                border-color: var(--secondary-color);
            }

            .btn-success:hover {
                background-color: var(--primary-color);
                border-color: var(--primary-color);
            }

            .text-primary {
                color: var(--primary-color) !important;
            }

            .text-success {
                color: var(--secondary-color) !important;
            }

            .bg-primary {
                background-color: var(--primary-color) !important;
            }

            .bg-success {
                background-color: var(--secondary-color) !important;
            }

            .border-primary {
                border-color: var(--primary-color) !important;
            }

            .sidebar-toggle {
                display: none;
                background: none;
                border: none;
                color: white;
                font-size: 1.25rem;
                padding: 0.5rem;
                margin-right: 1rem;
            }

            .sidebar-backdrop {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1040;
                opacity: 0;
                transition: opacity 0.3s ease;
            }

            .sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }

            @media (max-width: 767.98px) {
                .sidebar .nav-link {
                    padding: 15px 20px;
                    min-height: 50px;
                    display: flex;
                    align-items: center;
                    cursor: pointer;
                }

                .sidebar .nav-link i {
                    font-size: 1.1rem;
                    width: 25px;
                }

                .sidebar .nav-link:active {
                    background: rgba(255, 255, 255, 0.2);
                    transform: translateX(5px);
                    transition: all 0.1s ease;
                }

                .sidebar-toggle {
                    display: block;
                }

                .sidebar {
                    position: fixed;
                    top: 56px;
                    left: 0;
                    transform: translateX(-100%);
                    width: 280px;
                    height: calc(100vh - 56px);
                    z-index: 1050;
                    overflow-y: auto;
                    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
                }

                .sidebar.show {
                    transform: translateX(0);
                    box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
                }

                main {
                    margin-left: 0 !important;
                    padding: 1rem !important;
                    transition: transform 0.3s ease-out;
                }

                body.sidebar-open main {
                    transform: translateX(280px);
                }

                .stat-card .card-body {
                    padding: 1rem;
                }

                .metric-value {
                    font-size: 1.5rem;
                }

                .btn-group-actions .btn {
                    padding: 0.75rem 0.5rem;
                    font-size: 0.875rem;
                }
            }

            @media (max-width: 575.98px) {
                .col-md-2 {
                    flex: 0 0 50%;
                    max-width: 50%;
                }

                .col-md-4 {
                    flex: 0 0 100%;
                    max-width: 100%;
                }

                .btn-group-actions .btn {
                    padding: 0.75rem 0.5rem;
                    font-size: 0.875rem;
                }
            }

            .metric-value {
                font-size: 1.8rem;
                font-weight: 700;
            }

            .metric-label {
                font-size: 0.875rem;
                color: #6c757d;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .btn-group-actions .btn {
                border-radius: 8px;
                transition: all 0.3s ease;
            }

            .btn-group-actions .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            }

            .btn:active,
            .btn-group-actions .btn:active {
                transform: scale(0.98);
                transition: transform 0.1s ease;
            }

            .progress-bar {
                background-color: var(--primary-color);
            }

            .badge.bg-primary {
                background-color: var(--primary-color) !important;
            }

            .badge.bg-success {
                background-color: var(--secondary-color) !important;
            }

            .nav-tabs .nav-link {
                color: #495057;
                font-weight: 500;
                padding: 0.75rem 1.25rem;
            }

            .nav-tabs .nav-link:hover {
                border-color: #e9ecef #e9ecef #dee2e6;
                isolation: isolate;
            }

            .nav-tabs .nav-link.active {
                color: var(--primary-color);
                font-weight: 600;
                border-bottom: 3px solid var(--primary-color);
            }

            .nav-tabs .nav-link i {
                font-size: 1rem;
            }

            @media (max-width: 767.98px) {
                .nav-tabs {
                    flex-wrap: nowrap;
                    overflow-x: auto;
                    overflow-y: hidden;
                    -webkit-overflow-scrolling: touch;
                    scrollbar-width: thin;
                    padding-bottom: 2px;
                }

                .nav-tabs .nav-item {
                    flex: 0 0 auto;
                }

                .nav-tabs .nav-link {
                    white-space: nowrap;
                    padding: 0.75rem 1rem;
                }

                .nav-tabs .nav-link i {
                    margin-right: 0.5rem;
                }
            }

            .error-message {
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
                padding: 1rem;
                border-radius: 5px;
                margin-bottom: 1rem;
            }

            .modal.fade .modal-dialog {
                transform: scale(0.8);
                transition: transform 0.2s ease-out;
            }

            .modal.show .modal-dialog {
                transform: scale(1);
            }

            .modal-body {
                max-height: 70vh;
                overflow-y: auto;
                scrollbar-width: thin;
                scrollbar-color: var(--primary-color) #f1f1f1;
            }

            .modal-body::-webkit-scrollbar {
                width: 6px;
            }

            .modal-body::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 3px;
            }

            .modal-body::-webkit-scrollbar-thumb {
                background: var(--primary-color);
                border-radius: 3px;
            }

            .modal-body::-webkit-scrollbar-thumb:hover {
                background: var(--secondary-color);
            }

            .detail-section {
                background-color: #f8f9fa;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
                border-left: 4px solid var(--primary-color);
            }

            .detail-section h6 {
                color: var(--primary-color);
                font-weight: 600;
                margin-bottom: 15px;
                border-bottom: 1px solid #dee2e6;
                padding-bottom: 8px;
            }

            .detail-item {
                display: flex;
                margin-bottom: 10px;
                padding: 8px;
                background-color: white;
                border-radius: 6px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            }

            .detail-label {
                font-weight: 600;
                width: 140px;
                color: #495057;
                font-size: 0.9rem;
            }

            .detail-value {
                flex: 1;
                color: #212529;
            }

            .badge-feature {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.75rem;
                font-weight: 600;
                margin-right: 5px;
                margin-bottom: 5px;
            }

            .reference-card {
                background-color: white;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 12px;
                margin-bottom: 12px;
                transition: transform 0.2s;
                cursor: pointer;
            }

            .reference-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
                background-color: #f0f7ff;
                border-color: var(--primary-color);
            }

            .reference-title {
                font-weight: 600;
                color: var(--primary-color);
                margin-bottom: 8px;
                font-size: 1rem;
            }

            .reference-detail {
                display: flex;
                justify-content: space-between;
                font-size: 0.85rem;
                color: #6c757d;
            }

            .reference-image {
                max-width: 100%;
                max-height: 60px;
                margin-top: 8px;
                border-radius: 4px;
            }

            @media (max-width: 576px) {
                .detail-item {
                    flex-direction: column;
                }

                .detail-label {
                    width: 100%;
                    margin-bottom: 5px;
                }
            }

            .badge-feature {
                background-color: #e9ecef;
                color: #495057;
                border-radius: 20px;
                padding: 5px 12px;
                font-size: 0.8rem;
                margin: 0 5px 5px 0;
                display: inline-flex;
                align-items: center;
                transition: all 0.2s;
            }

            .badge-feature:hover {
                background-color: var(--primary-color);
                color: white;
                transform: translateY(-2px);
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            }

            .badge-feature i {
                margin-right: 4px;
                font-size: 0.7rem;
            }

            .prefijo-referencia {
                background-color: var(--primary-color);
                color: white;
                font-weight: bold;
            }

            .validacion-item {
                font-size: 0.9rem;
                padding: 0.25rem 0;
            }

            .validacion-item i {
                color: var(--primary-color);
                width: 20px;
            }

            @media print {
                .ticket-print {
                    font-family: 'Courier New', monospace;
                    font-size: 12px;
                    width: 80mm;
                    margin: 0 auto;
                }
            }

            .chat-container {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 9999;
            }

            .chat-button {
                background-color: #007bff;
                color: white;
                border: none;
                border-radius: 50px;
                padding: 12px 24px;
                cursor: pointer;
                font-size: 16px;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            }

            .chat-button:hover {
                background-color: #0056b3;
            }
        </style>
    </head>

    <body>
        <!-- Navbar -->
        <nav class="navbar navbar-expand-lg navbar-dark">
            <div class="container-fluid">
                <!-- Botón hamburguesa para móvil -->
                <button class="sidebar-toggle" type="button" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>

                <a class="navbar-brand d-flex align-items-center" href="#">
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
                    <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                        <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                            alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                            class="me-2"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                        <i class="fas fa-cash-register me-2" style="display: none;"></i>
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
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                        </ul>
                    </li>
                </div>
            </div>
        </nav>

        <!-- Backdrop para móvil -->
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <div class="container-fluid">
            <div class="row">
                <!-- Sidebar -->
                <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                    <div class="position-sticky pt-3">
                        <ul class="nav flex-column">
                            <li class="nav-item">
                                <a class="nav-link" href="../dashboard.php">
                                    <i class="fas fa-tachometer-alt"></i>
                                    Dashboard
                                </a>
                            </li>
                            <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="../usuarios.php">
                                        <i class="fas fa-user-cog"></i>
                                        Usuarios
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link" href="../caja.php">
                                    <i class="fas fa-cash-register"></i>
                                    Caja
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../productos.php">
                                    <i class="fas fa-boxes"></i>
                                    Productos
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../inventario.php">
                                    <i class="fas fa-clipboard-list"></i>
                                    Inventario
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../clientes.php">
                                    <i class="fas fa-users"></i>
                                    Clientes
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../ventas_lista.php">
                                    <i class="fas fa-receipt"></i>
                                    Ventas
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../caja_historial.php">
                                    <i class="fas fa-cash-register"></i>
                                    Cortes de Caja
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../proveedores.php">
                                    <i class="fas fa-truck"></i>
                                    Proveedores
                                </a>
                            </li>

                            <!-- MENÚ DE SUCURSALES CONDICIONAL -->
                            <?php if ($empresa_plan !== 'basico'): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="../sucursales.php">
                                        <i class="fas fa-store"></i>
                                        Sucursales
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles > 0) : ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="../Facturacion/inicio.php">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        Facturación
                                        <?php if ($timbres_disponibles > 0): ?>
                                            <span class="badge bg-success ms-2" style="font-size: 0.65rem;">
                                                <?php echo $timbres_disponibles; ?> timbres
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning ms-2" style="font-size: 0.65rem;">
                                                Sin timbres
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link" href="../reportes.php">
                                    <i class="fas fa-chart-bar"></i>
                                    Reportes
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link active" href="inicio.php">
                                    <img src="../images/emidalogo.png" alt="" style="width: 20px; height: 20px; margin-right: 10px; object-fit: contain;">
                                    Emida Servicios
                                    <?php if ($notification_status && isset($notification_status['notification_status']) && !$notification_status['notification_status']['success']): ?>
                                        <span class="badge bg-warning ms-2" style="font-size: 0.65rem;" title="Notificaciones no configuradas">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="../configuracion.php">
                                        <i class="fas fa-cogs"></i>
                                        Configuración
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- Main Content -->
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">

                    <!-- Título de la sección -->
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2"><i class="fas fa-bolt text-primary me-2"></i>Emida Servicios</h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <div class="btn-group me-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="actualizarSaldo()">
                                    <i class="fas fa-sync-alt me-1"></i>Actualizar Saldo
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ayudaEmida()">
                                    <i class="fas fa-question-circle me-1"></i>Ayuda
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjetas de resumen -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card stat-card border-primary">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Saldo Emida</h6>
                                            <h3 class="mb-0" id="saldoEmida">$0.00</h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-10 p-3 rounded">
                                            <i class="fas fa-wallet fa-2x text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card border-success">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Transacciones Hoy</h6>
                                            <h3 class="mb-0" id="transaccionesHoy">0</h3>
                                        </div>
                                        <div class="bg-success bg-opacity-10 p-3 rounded">
                                            <i class="fas fa-exchange-alt fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card border-info">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Comisiones Hoy</h6>
                                            <h3 class="mb-0" id="comisionesHoy">$0.00</h3>
                                        </div>
                                        <div class="bg-info bg-opacity-10 p-3 rounded">
                                            <i class="fas fa-percentage fa-2x text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card border-warning">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Ventas del Día</h6>
                                            <h3 class="mb-0" id="ventasDia">$0.00</h3>
                                        </div>
                                        <div class="bg-warning bg-opacity-10 p-3 rounded">
                                            <i class="fas fa-chart-line fa-2x text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pestañas de navegación -->
                    <ul class="nav nav-tabs mb-4" id="emidaTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="catalogo-tab" data-bs-toggle="tab" data-bs-target="#catalogo" type="button" role="tab" aria-controls="catalogo" aria-selected="true">
                                <i class="fas fa-box-open me-2"></i>Catálogo Servicios
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="reportes-tab" data-bs-toggle="tab" data-bs-target="#reportes" type="button" role="tab" aria-controls="reportes" aria-selected="false">
                                <i class="fas fa-chart-line me-2"></i>Reporte de Transacciones
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="buzon-tab" data-bs-toggle="tab" data-bs-target="#buzon" type="button" role="tab" aria-controls="buzon" aria-selected="false">
                                <i class="fas fa-envelope me-2"></i>Buzón de Mensajes
                                <span class="badge bg-danger ms-2" id="mensajesNoLeidos">0</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="parametros-tab" data-bs-toggle="tab" data-bs-target="#parametros" type="button" role="tab" aria-controls="parametros" aria-selected="false">
                                <i class="fas fa-cog me-2"></i>Parámetros
                            </button>
                        </li>
                    </ul>

                    <!-- Contenido de las pestañas -->
                    <div class="tab-content" id="emidaTabsContent">

                        <!-- ========================================= -->
                        <!-- Pestaña 1: Catálogo Servicios -->
                        <!-- ========================================= -->
                        <div class="tab-pane fade show active" id="catalogo" role="tabpanel" aria-labelledby="catalogo-tab">
                            <div class="card">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-box-open text-primary me-2"></i>Catálogo de Servicios</h5>
                                    <button class="btn btn-primary btn-sm" onclick="actualizarCatalogo()">
                                        <i class="fas fa-sync-alt me-1"></i>Actualizar Catálogo
                                    </button>
                                </div>
                                <div class="card-body">
                                    <!-- Mostrar error si existe -->
                                    <?php if (!empty($error_emida)): ?>
                                        <div class="error-message">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <?php echo htmlspecialchars($error_emida); ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Filtros de búsqueda -->
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <div class="input-group">
                                                <span class="input-group-text bg-white">
                                                    <i class="fas fa-search text-muted"></i>
                                                </span>
                                                <input type="text" class="form-control" id="buscarServicio" placeholder="Buscar servicio...">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <select class="form-select" id="filtroCategoria">
                                                <option value="">Todas las categorías</option>
                                                <?php
                                                $categorias = array_unique(array_column($servicios_emida, 'categoria'));
                                                sort($categorias);
                                                foreach ($categorias as $categoria):
                                                    if (!empty($categoria)):
                                                ?>
                                                        <option value="<?php echo htmlspecialchars($categoria); ?>"><?php echo htmlspecialchars($categoria); ?></option>
                                                <?php
                                                    endif;
                                                endforeach;
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <select class="form-select" id="filtroCarrier">
                                                <option value="">Todos los carriers</option>
                                                <?php
                                                $carriers = array_unique(array_column($servicios_emida, 'carrier'));
                                                sort($carriers);
                                                foreach ($carriers as $carrier):
                                                    if (!empty($carrier)):
                                                ?>
                                                        <option value="<?php echo htmlspecialchars($carrier); ?>"><?php echo htmlspecialchars($carrier); ?></option>
                                                <?php
                                                    endif;
                                                endforeach;
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <button class="btn btn-outline-secondary w-100" onclick="limpiarFiltros()">
                                                <i class="fas fa-undo me-1"></i>Limpiar
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Tabla de servicios -->
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle" id="tablaServicios">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Servicio</th>
                                                    <th>Categoría</th>
                                                    <th>Carrier</th>
                                                    <th>Comisión</th>
                                                    <th>Monto</th>
                                                    <th>Monto Mín.</th>
                                                    <th>Monto Máx.</th>
                                                    <th>Tipo</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody id="serviciosBody">
                                                <?php if (empty($servicios_emida)): ?>
                                                    <tr>
                                                        <td colspan="10" class="text-center py-4">
                                                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                                            <p class="text-muted mb-0">No hay servicios disponibles</p>
                                                            <p class="text-muted small">Haz clic en "Actualizar Catálogo" para obtener los servicios de Emida</p>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($servicios_emida as $servicio): ?>
                                                        <tr class="servicio-row"
                                                            data-categoria="<?php echo htmlspecialchars($servicio['categoria']); ?>"
                                                            data-carrier="<?php echo htmlspecialchars($servicio['carrier']); ?>"
                                                            data-nombre="<?php echo htmlspecialchars($servicio['nombre']); ?>">
                                                            <td>
                                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($servicio['id']); ?></span>
                                                            </td>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($servicio['nombre']); ?></strong>
                                                                <?php if (!empty($servicio['subcategoria']) && $servicio['subcategoria'] !== 'N/A'): ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($servicio['subcategoria']); ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-info"><?php echo htmlspecialchars($servicio['categoria']); ?></span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($servicio['carrier']); ?></td>
                                                            <td>
                                                                <?php if ($servicio['comision'] > 0): ?>
                                                                    <span class="text-success">$<?php echo number_format($servicio['comision'], 2); ?></span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($servicio['monto'] > 0): ?>
                                                                    <strong>$<?php echo number_format($servicio['monto'], 2); ?></strong>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Variable</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($servicio['monto_min'] > 0): ?>
                                                                    $<?php echo number_format($servicio['monto_min'], 2); ?>
                                                                <?php else: ?>
                                                                    -
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($servicio['monto_max'] > 0): ?>
                                                                    $<?php echo number_format($servicio['monto_max'], 2); ?>
                                                                <?php else: ?>
                                                                    -
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                $flowTypeLabels = [
                                                                    'A' => '<span class="badge bg-success">Venta Directa</span>',
                                                                    'B' => '<span class="badge bg-warning">Consulta/Pago</span>',
                                                                    'K' => '<span class="badge bg-primary">Recarga</span>',
                                                                    'F' => '<span class="badge bg-info">Pago Servicios</span>'
                                                                ];
                                                                echo $flowTypeLabels[$servicio['flow_type']] ?? '<span class="badge bg-secondary">' . $servicio['flow_type'] . '</span>';
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <button class="btn btn-sm btn-outline-primary" onclick="verDetalleServicio('<?php echo $servicio['id']; ?>')" title="Ver detalles">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-success" onclick="venderServicio('<?php echo $servicio['id']; ?>')" title="Vender">
                                                                    <i class="fas fa-shopping-cart"></i>
                                                                </button>
                                                                <?php if (!empty($servicio['referencias'])): ?>
                                                                    <button class="btn btn-sm btn-outline-info" onclick="verReferencias('<?php echo $servicio['id']; ?>')" title="Ver referencias">
                                                                        <i class="fas fa-list"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Información adicional -->
                                    <?php if (!empty($servicios_emida)): ?>
                                        <div class="row mt-3">
                                            <div class="col-md-12">
                                                <div class="alert alert-info mb-0">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    <strong>Total de servicios:</strong> <?php echo count($servicios_emida); ?> |
                                                    <strong>Última actualización:</strong> <?php echo date('d/m/Y H:i:s'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Pestaña 2: Reporte de Transacciones -->
                        <div class="tab-pane fade" id="reportes" role="tabpanel" aria-labelledby="reportes-tab">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-chart-line text-primary me-2"></i>Reporte de Transacciones</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-4">
                                        <div class="col-md-3">
                                            <label class="form-label">Fecha Inicio</label>
                                            <input type="date" class="form-control" id="fechaInicio" value="<?php echo date('Y-m-01'); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Fecha Fin</label>
                                            <input type="date" class="form-control" id="fechaFin" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Tipo</label>
                                            <select class="form-select" id="tipoTransaccion">
                                                <option value="">Todas</option>
                                                <option value="recarga">Recargas</option>
                                                <option value="pago">Pagos</option>
                                                <option value="venta">Ventas</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Estatus</label>
                                            <select class="form-select" id="estatusTransaccion">
                                                <option value="">Todos</option>
                                                <option value="completado">Completado</option>
                                                <option value="pendiente">Pendiente</option>
                                                <option value="cancelado">Cancelado</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button class="btn btn-primary w-100" onclick="cargarTransacciones()">
                                                <i class="fas fa-search me-1"></i>Filtrar
                                            </button>
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-12">
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-outline-secondary btn-sm" onclick="exportarPDF()">
                                                    <i class="fas fa-file-pdf me-1"></i>PDF
                                                </button>
                                                <button class="btn btn-outline-success btn-sm" onclick="exportarExcel()">
                                                    <i class="fas fa-file-excel me-1"></i>Excel
                                                </button>
                                                <button class="btn btn-outline-primary btn-sm" onclick="imprimirReporte()">
                                                    <i class="fas fa-print me-1"></i>Imprimir
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mb-4">
                                        <div class="col-md-3">
                                            <div class="card bg-primary text-white">
                                                <div class="card-body py-3">
                                                    <h6 class="card-title">Total Transacciones</h6>
                                                    <h3 class="mb-0" id="totalTransacciones">0</h3>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card bg-success text-white">
                                                <div class="card-body py-3">
                                                    <h6 class="card-title">Monto Total</h6>
                                                    <h3 class="mb-0" id="montoTotal">$0.00</h3>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card bg-info text-white">
                                                <div class="card-body py-3">
                                                    <h6 class="card-title">Comisiones</h6>
                                                    <h3 class="mb-0" id="totalComisiones">$0.00</h3>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card bg-warning text-white">
                                                <div class="card-body py-3">
                                                    <h6 class="card-title">Transacciones Exitosas</h6>
                                                    <h3 class="mb-0" id="exitosas">0</h3>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Fecha/Hora</th>
                                                    <th>Servicio</th>
                                                    <th>Referencia</th>
                                                    <th>Monto</th>
                                                    <th>Comisión</th>
                                                    <th>Estatus</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody id="transaccionesBody">
                                                <tr>
                                                    <td colspan="7" class="text-center py-4">
                                                        <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                                        <p class="text-muted mb-0">No hay transacciones en el período seleccionado</p>
                                                        <p class="text-muted small">Selecciona un rango de fechas y haz clic en Filtrar</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pestaña 3: Buzón de Mensajes -->
                        <div class="tab-pane fade" id="buzon" role="tabpanel" aria-labelledby="buzon-tab">
                            <div class="card">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-envelope text-primary me-2"></i>Buzón de Mensajes</h5>
                                    <div>
                                        <button class="btn btn-outline-primary btn-sm me-2" onclick="marcarTodosLeidos()">
                                            <i class="fas fa-check-double me-1"></i>Marcar todos como leídos
                                        </button>
                                        <button class="btn btn-primary btn-sm" onclick="enviarMensaje()">
                                            <i class="fas fa-pen me-1"></i>Redactar
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <ul class="nav nav-pills mb-3" id="mensajesPills" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="recibidos-tab" data-bs-toggle="pill" data-bs-target="#recibidos" type="button" role="tab">Recibidos</button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="enviados-tab" data-bs-toggle="pill" data-bs-target="#enviados" type="button" role="tab">Enviados</button>
                                        </li>
                                    </ul>

                                    <div class="tab-content" id="mensajesPillsContent">
                                        <div class="tab-pane fade show active" id="recibidos" role="tabpanel">
                                            <div class="list-group" id="listaMensajesRecibidos">
                                                <div class="text-center py-5">
                                                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                                                    <p class="text-muted mb-0">No hay mensajes en la bandeja de entrada</p>
                                                    <p class="text-muted small">Los mensajes nuevos aparecerán aquí</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="tab-pane fade" id="enviados" role="tabpanel">
                                            <div class="list-group" id="listaMensajesEnviados">
                                                <div class="text-center py-5">
                                                    <i class="fas fa-paper-plane fa-4x text-muted mb-3"></i>
                                                    <p class="text-muted mb-0">No hay mensajes enviados</p>
                                                    <p class="text-muted small">Los mensajes que envíes aparecerán aquí</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pestaña 4: Parámetros -->
                        <div class="tab-pane fade" id="parametros" role="tabpanel" aria-labelledby="parametros-tab">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-cog text-primary me-2"></i>Configuración de Parámetros Emida</h5>
                                </div>
                                <div class="card-body">
                                    <form id="formParametros">
                                        <h6 class="fw-bold mb-3">Configuración General</h6>
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label">Terminal ID</label>
                                                <input type="text" class="form-control" id="terminalId" value="<?php echo htmlspecialchars($terminal_id); ?>" placeholder="ID del punto de venta">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Clerk ID / Contraseña</label>
                                                <input type="password" class="form-control" id="clerkId" value="<?php echo htmlspecialchars($clerk_id); ?>" placeholder="Contraseña del punto de venta">
                                            </div>
                                        </div>

                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label">URL API (Pruebas)</label>
                                                <input type="url" class="form-control" id="urlPruebas" value="https://test.emida.com/api" placeholder="URL de pruebas">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">URL API (Producción)</label>
                                                <input type="url" class="form-control" id="urlProduccion" value="https://api.emida.com/v1" placeholder="URL de producción">
                                            </div>
                                        </div>

                                        <h6 class="fw-bold mb-3 mt-4">Comisiones por Defecto</h6>
                                        <div class="row mb-4">
                                            <div class="col-md-4">
                                                <label class="form-label">Comisión Recargas</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="comisionRecargas" value="0.00" step="0.01">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Comisión Pagos</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="comisionPagos" value="0.00" step="0.01">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Comisión Tiempo Aire</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="comisionTiempoAire" value="0.00" step="0.01">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                        </div>

                                        <h6 class="fw-bold mb-3">Configuración de Timeout</h6>
                                        <div class="row mb-4">
                                            <div class="col-md-4">
                                                <label class="form-label">Timeout Inicial (segundos)</label>
                                                <input type="number" class="form-control" id="timeoutInicial" value="90" min="5" max="120">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Intervalo de Consulta (segundos)</label>
                                                <input type="number" class="form-control" id="intervaloConsulta" value="5" min="1" max="30">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Número de Reintentos</label>
                                                <input type="number" class="form-control" id="numReintentos" value="3" min="1" max="10">
                                            </div>
                                        </div>

                                        <h6 class="fw-bold mb-3">Horarios de Operación</h6>
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label">Hora Apertura</label>
                                                <input type="time" class="form-control" id="horaApertura" value="09:00">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Hora Cierre</label>
                                                <input type="time" class="form-control" id="horaCierre" value="20:00">
                                            </div>
                                        </div>

                                        <h6 class="fw-bold mb-3">Opciones Adicionales</h6>
                                        <div class="row mb-4">
                                            <div class="col-md-12">
                                                <div class="form-check form-switch mb-2">
                                                    <input class="form-check-input" type="checkbox" id="modoPruebas" checked>
                                                    <label class="form-check-label" for="modoPruebas">Modo Pruebas</label>
                                                </div>
                                                <div class="form-check form-switch mb-2">
                                                    <input class="form-check-input" type="checkbox" id="notificacionesEmail">
                                                    <label class="form-check-label" for="notificacionesEmail">Enviar notificaciones por email</label>
                                                </div>
                                                <div class="form-check form-switch mb-2">
                                                    <input class="form-check-input" type="checkbox" id="registroTransacciones" checked>
                                                    <label class="form-check-label" for="registroTransacciones">Registro detallado de transacciones</label>
                                                </div>
                                                <div class="form-check form-switch mb-2">
                                                    <input class="form-check-input" type="checkbox" id="notificarStockBajo">
                                                    <label class="form-check-label" for="notificarStockBajo">Notificar cuando el saldo esté bajo</label>
                                                </div>
                                            </div>
                                        </div>

                                        <h6 class="fw-bold mb-3">Límites</h6>
                                        <div class="row mb-4">
                                            <div class="col-md-4">
                                                <label class="form-label">Monto máximo por transacción</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" class="form-control" id="montoMaximo" value="5000">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Monto mínimo por transacción</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" class="form-control" id="montoMinimo" value="10">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Saldo mínimo alerta</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" class="form-control" id="saldoMinimo" value="100">
                                                </div>
                                            </div>
                                        </div>

                                        <hr>

                                        <div class="text-end">
                                            <button type="button" class="btn btn-secondary me-2" onclick="cancelarParametros()">
                                                Cancelar
                                            </button>
                                            <button type="submit" class="btn btn-primary" onclick="guardarParametros(event)">
                                                <i class="fas fa-save me-1"></i>Guardar Configuración
                                            </button>
                                        </div>
                                    </form>

                                    <!-- Estado de Notificaciones -->
                                    <div class="card mt-4">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="fas fa-bell me-2"></i>Estado de Notificaciones de Pago</h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="notificationStatus">
                                                <?php if ($notification_status): ?>
                                                    <?php if ($notification_status['success'] && isset($notification_status['notification_status']) && $notification_status['notification_status']['success']): ?>
                                                        <div class="alert alert-success mb-0">
                                                            <i class="fas fa-check-circle me-2"></i>
                                                            <strong>Notificaciones configuradas correctamente</strong><br>
                                                            <small>Última verificación: <?php echo date('Y-m-d H:i:s'); ?></small>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="alert alert-warning mb-0">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                                            <strong>Notificaciones no configuradas</strong><br>
                                                            <small><?php echo $notification_status['message'] ?? 'Se requiere configuración adicional'; ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div class="alert alert-info mb-0">
                                                        <i class="fas fa-info-circle me-2"></i>
                                                        <strong>Verificando estado...</strong>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <button class="btn btn-sm btn-outline-primary mt-3" onclick="verificarEstadoNotificaciones()">
                                                <i class="fas fa-sync-alt me-1"></i>Verificar Estado
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>

        <!-- MODALES PARA DETALLES DE SERVICIOS -->

        <!-- Modal de Detalles del Servicio -->
        <div class="modal fade" id="detalleServicioModal" tabindex="-1" aria-labelledby="detalleServicioModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white;">
                        <h5 class="modal-title" id="detalleServicioModalLabel">
                            <i class="fas fa-info-circle me-2"></i>Detalles del Servicio
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <div id="detalleServicioContent">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Cargando...</span>
                                </div>
                                <p class="mt-2 text-muted">Cargando información del servicio...</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cerrar
                        </button>
                        <button type="button" class="btn btn-success" onclick="venderServicioModal()" id="btnVenderDesdeModal">
                            <i class="fas fa-shopping-cart me-1"></i>Vender Servicio
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal para Referencias -->
        <div class="modal fade" id="referenciasModal" tabindex="-1" aria-labelledby="referenciasModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-md">
                <div class="modal-content">
                    <div class="modal-header" style="background: var(--primary-color); color: white;">
                        <h5 class="modal-title" id="referenciasModalLabel">
                            <i class="fas fa-list me-2"></i>Campos de Referencia
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body" id="referenciasModalContent">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL PARA VENTA DE SERVICIO EMIDA -->
        <div class="modal fade" id="ventaServicioModal" tabindex="-1" aria-labelledby="ventaServicioModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white;">
                        <h5 class="modal-title" id="ventaServicioModalLabel">
                            <i class="fas fa-shopping-cart me-2"></i>Vender Servicio Emida
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <form id="formVentaServicio" onsubmit="procesarVentaServicio(event)">
                        <div class="modal-body">
                            <!-- Campos ocultos -->
                            <input type="hidden" id="venta_productId" name="productId">
                            <input type="hidden" id="venta_productName" name="productName">
                            <input type="hidden" id="venta_montoFijo" name="montoFijo">
                            <input type="hidden" id="venta_montoMin" name="montoMin">
                            <input type="hidden" id="venta_montoMax" name="montoMax">
                            <input type="hidden" id="venta_flowType" name="flowType">
                            <input type="hidden" id="venta_tipo_operacion" name="tipo_operacion" value="recarga">

                            <!-- Información del servicio -->
                            <div class="alert alert-info mb-4">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-info-circle fa-2x me-3"></i>
                                    <div>
                                        <strong id="venta_nombreServicio"></strong><br>
                                        <span id="venta_carrierServicio"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <!-- Número de cuenta/referencia -->
                                <div class="col-md-12 mb-3">
                                    <label class="form-label" id="labelReferencia">Número de Referencia <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white" id="prefijoReferencia"></span>
                                        <input type="text" class="form-control" id="venta_accountId" name="accountId"
                                            placeholder="Ingrese el número de referencia" required>
                                    </div>
                                    <small class="text-muted" id="referenciaTooltip"></small>
                                </div>

                                <!-- Monto (si es variable) -->
                                <div class="col-md-6 mb-3" id="campoMonto" style="display: none;">
                                    <label class="form-label">Monto <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white">$</span>
                                        <input type="number" class="form-control" id="venta_amount" name="amount"
                                            step="0.01" min="0" placeholder="0.00">
                                    </div>
                                    <small class="text-muted" id="rangoMonto"></small>
                                </div>

                                <!-- Número de factura/invoice (interno) -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Número de Factura/Invoice <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="venta_invoiceNo" name="invoiceNo"
                                        value="1" min="1" max="99999" required>
                                    <small class="text-muted">Número consecutivo (1-99999)</small>
                                </div>

                                <!-- Campos de referencia extra (si aplica) -->
                                <div class="col-md-12 mb-3" id="campoReferenciaExtra" style="display: none;">
                                    <label class="form-label" id="labelReferenciaExtra">Referencia Adicional</label>
                                    <input type="text" class="form-control" id="venta_extraReference" name="extraReference">
                                    <small class="text-muted" id="tooltipReferenciaExtra"></small>
                                </div>
                            </div>

                            <!-- Información de validación -->
                            <div class="bg-light p-3 rounded mt-3">
                                <h6 class="fw-bold mb-2"><i class="fas fa-clipboard-check me-2"></i>Validaciones</h6>
                                <ul class="small text-muted mb-0" id="listaValidaciones">
                                    <li>Verifique que el número de referencia sea correcto</li>
                                    <li>El número de factura debe ser consecutivo</li>
                                </ul>
                            </div>

                            <div id="mensajeVenta" style="display: none;" class="mt-3"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cancelar
                            </button>
                            <button type="submit" class="btn btn-success" id="btnProcesarVenta">
                                <i class="fas fa-shopping-cart me-1"></i>Procesar Venta
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal de confirmación/resultado -->
        <div class="modal fade" id="resultadoVentaModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" id="resultadoModalHeader">
                        <h5 class="modal-title" id="resultadoModalTitle">
                            <i class="fas fa-check-circle me-2"></i>Resultado de Venta
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body" id="resultadoModalBody">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                            <i class="fas fa-check me-1"></i>Aceptar
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="imprimirTicket()">
                            <i class="fas fa-print me-1"></i>Imprimir Ticket
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-container">
            <?php
            // Código del widget proporcionado por Emida
            $zoho_widget_code = "fe0dd9db5581ce9df4fc722576a714359a85becff42a97490a24b28ba30ad410";
            ?>

            <script type="text/javascript">
                var $zoho = $zoho || {};
                $zoho.salesiq = $zoho.salesiq || {
                    widgetcode: "<?php echo $zoho_widget_code; ?>",
                    values: {},
                    ready: function() {}
                };

                var d = document;
                var s = d.createElement("script");
                s.type = "text/javascript";
                s.id = "zsiqscript";
                s.defer = true;
                s.src = "https://salesiq.zoho.com/widget";

                var t = d.getElementsByTagName("script")[0];
                t.parentNode.insertBefore(s, t);

                d.write("<div id='zsiqwidget'></div>");
            </script>
        </div>

        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

        <script>
            // =============================================
            // VARIABLES GLOBALES
            // =============================================

            let servicioActual = null;
            let consultaActiva = false;
            let reintentosActivos = {};

            const terminalId = <?php echo json_encode($terminal_id); ?>;
            const clerkId = <?php echo json_encode($clerk_id); ?>;
            const serviciosData = <?php echo json_encode($servicios_emida); ?>;

            // =============================================
            // FUNCIONES DE SIDEBAR MÓVIL
            // =============================================

            function openSidebarAuto() {
                const sidebar = document.getElementById('sidebar');
                const backdrop = document.getElementById('sidebarBackdrop');
                if (sidebar && backdrop) {
                    sidebar.classList.add('show');
                    backdrop.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeSidebarAuto() {
                const sidebar = document.getElementById('sidebar');
                const backdrop = document.getElementById('sidebarBackdrop');
                if (sidebar && backdrop) {
                    sidebar.classList.remove('show');
                    backdrop.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }

            // =============================================
            // CONFIGURACIÓN DE TIMEOUT DESDE LOCALSTORAGE
            // =============================================

            function getTimeoutConfig() {
                return {
                    timeoutInicial: parseInt(localStorage.getItem('timeoutInicial') || '30'),
                    intervaloConsulta: parseInt(localStorage.getItem('intervaloConsulta') || '10'),
                    numReintentos: parseInt(localStorage.getItem('numReintentos') || '3')
                };
            }

            // =============================================
            // FUNCIONES DE NOTIFICACIÓN Y MENSAJES
            // =============================================

            function mostrarMensajeVenta(mensaje, tipo) {
                const divMensaje = document.getElementById('mensajeVenta');
                if (divMensaje) {
                    divMensaje.style.display = 'block';
                    divMensaje.className = 'alert alert-' + tipo + ' mt-3';
                    var icono = tipo === 'success' ? 'check-circle' : (tipo === 'danger' ? 'exclamation-circle' : 'info-circle');
                    divMensaje.innerHTML = '<i class="fas fa-' + icono + ' me-2"></i>' + mensaje;

                    if (tipo === 'success' || tipo === 'info') {
                        setTimeout(function() {
                            if (divMensaje) divMensaje.style.display = 'none';
                        }, 8000);
                    }
                }
            }

            function mostrarNotificacion(mensaje, tipo) {
                var notificacionExistente = document.querySelector('.alert-notificacion');
                if (notificacionExistente) notificacionExistente.remove();

                var notificacion = document.createElement('div');
                notificacion.className = 'alert alert-' + tipo + ' alert-dismissible fade show alert-notificacion position-fixed top-0 end-0 m-3';
                notificacion.style.zIndex = '9999';
                notificacion.style.maxWidth = '350px';
                notificacion.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                notificacion.style.borderLeft = '4px solid ' + (tipo === 'success' ? '#28a745' : '#dc3545');

                var icono = tipo === 'success' ? 'check-circle' : 'exclamation-circle';
                notificacion.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-${icono} me-2 fa-lg"></i>
                    <div class="flex-grow-1">${mensaje}</div>
                    <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;

                document.body.appendChild(notificacion);
                setTimeout(function() {
                    if (notificacion.parentNode) notificacion.remove();
                }, 4000);
            }

            function mostrarAlerta(mensaje, tipo) {
                var alerta = document.createElement('div');
                alerta.className = 'alert alert-' + tipo + ' alert-dismissible fade show position-relative mb-3';
                var icono = tipo === 'danger' ? 'exclamation-triangle' : 'info-circle';
                alerta.innerHTML = `
                <i class="fas fa-${icono} me-2"></i>
                ${mensaje}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

                var titulo = document.querySelector('.border-bottom h1');
                if (titulo && titulo.parentNode) {
                    titulo.parentNode.insertAdjacentElement('afterend', alerta);
                } else {
                    var mainContent = document.getElementById('mainContent');
                    if (mainContent) mainContent.insertAdjacentElement('afterbegin', alerta);
                }

                setTimeout(function() {
                    if (alerta.parentNode) alerta.remove();
                }, 5000);
            }

            // =============================================
            // FUNCIÓN PRINCIPAL CON TIMEOUT Y REINTENTOS
            // =============================================

            async function procesarVentaConTimeout(datosVenta) {
                var config = getTimeoutConfig();

                console.log('Configuración Timeout:', {
                    timeoutInicial: config.timeoutInicial + 's',
                    intervaloConsulta: config.intervaloConsulta + 's',
                    numReintentos: config.numReintentos
                });

                var esPago = (datosVenta.tipo_operacion === 'pago' || datosVenta.flow_type === 'B' || datosVenta.flow_type === 'F');
                var endpoint = esPago ? '../Emida/BillPaymentUserFee.php' : '../Emida/pinDistSale.php';

                var respuestaInicial = null;
                var timeoutOcurrido = false;
                var respuestaRecibida = false;
                var datosRespuesta = null;

                try {
                    console.log('Enviando transacción a: ' + endpoint);
                    console.log('Datos:', datosVenta);

                    var controller = new AbortController();
                    var timeoutId = setTimeout(function() {
                        if (!respuestaRecibida) {
                            controller.abort();
                            timeoutOcurrido = true;
                            console.log('Timeout inicial (' + config.timeoutInicial + 's) alcanzado. La transacción pudo haberse procesado.');
                        }
                    }, config.timeoutInicial * 1000);

                    var fetchPromise = fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(datosVenta),
                        signal: controller.signal
                    });

                    var response = await fetchPromise;
                    respuestaRecibida = true;
                    clearTimeout(timeoutId);
                    respuestaInicial = await response.json();
                    console.log('Respuesta inicial:', respuestaInicial);

                    // Si la transacción fue exitosa (código 00), retornar inmediatamente
                    if (respuestaInicial && respuestaInicial.success === true &&
                        respuestaInicial.responseCode === '00') {
                        console.log('✅ Transacción exitosa inmediata');
                        return respuestaInicial;
                    }

                    // Si la transacción fue exitosa pero con timeout (el servidor respondió tarde)
                    if (respuestaInicial && respuestaInicial.success === true) {
                        console.log('✅ Transacción exitosa (respuesta tardía pero exitosa)');
                        return respuestaInicial;
                    }

                    // Si la transacción falló con código específico, retornar error
                    if (respuestaInicial && respuestaInicial.success === false &&
                        respuestaInicial.responseCode && respuestaInicial.responseCode !== 'TIMEOUT') {
                        console.log('❌ Transacción fallida:', respuestaInicial.responseMessage);
                        return respuestaInicial;
                    }

                } catch (error) {
                    if (error.name === 'AbortError') {
                        timeoutOcurrido = true;
                        console.log('⚠️ Timeout alcanzado. La transacción PUDO haberse procesado. Verificando saldo...');
                    } else {
                        console.error('Error en llamada inicial:', error);
                        throw error;
                    }
                }

                // === NUEVO: Si hubo timeout, verificar si el saldo cambió ===
                if (timeoutOcurrido || (respuestaInicial && respuestaInicial.requires_lookup === true)) {
                    var invoiceNo = datosVenta.invoiceNo;
                    var montoOriginal = parseFloat(datosVenta.amount);

                    console.log('⏳ Timeout detectado. Verificando si hubo cambio en el saldo...');

                    // Obtener saldo actual
                    var saldoResponse = await fetch('../Emida/get_balance.php?_=' + new Date().getTime());
                    var saldoData = await saldoResponse.json();
                    var saldoActual = saldoData.success ? saldoData.balance : 0;

                    // Obtener saldo anterior de localStorage
                    var saldoAnteriorKey = 'ultimoSaldoEmida_' + terminalId;
                    var saldoAnterior = parseFloat(localStorage.getItem(saldoAnteriorKey) || '0');

                    // Guardar saldo actual para próxima comparación
                    localStorage.setItem(saldoAnteriorKey, saldoActual);

                    console.log('Comparación de saldos - Anterior:', saldoAnterior, 'Actual:', saldoActual, 'Diferencia:', (saldoAnterior - saldoActual));

                    // Si el saldo disminuyó aproximadamente por el monto de la transacción
                    var diferencia = saldoAnterior - saldoActual;
                    if (diferencia >= (montoOriginal * 0.9) && diferencia <= (montoOriginal * 1.1)) {
                        console.log('✅ ¡Transacción exitosa! El saldo disminuyó en ' + diferencia.toFixed(2));

                        return {
                            success: true,
                            confirmed_by_balance: true,
                            responseCode: '00',
                            h2hResultCode: '0',
                            responseMessage: 'Transacción exitosa (confirmada por cambio de saldo)',
                            productId: datosVenta.productId,
                            productName: datosVenta.product_name,
                            accountId: datosVenta.accountId,
                            amount: montoOriginal,
                            invoiceNo: invoiceNo,
                            tipoOperacion: datosVenta.tipo_operacion,
                            flowType: datosVenta.flow_type,
                            transactionId: 'BALANCE_CONFIRMED_' + Date.now(),
                            carrierControlNo: '',
                            pin: ''
                        };
                    }

                    // Si no hubo cambio de saldo, intentar lookup (aunque sabemos que puede no funcionar)
                    console.log('⚠️ No se detectó cambio de saldo. Intentando lookup (puede no funcionar)...');

                    mostrarMensajeVenta('Verificando estado de la transacción...', 'info');

                    for (var intento = 1; intento <= config.numReintentos; intento++) {
                        console.log('Intento ' + intento + ' de ' + config.numReintentos);

                        await new Promise(function(resolve) {
                            setTimeout(resolve, config.intervaloConsulta * 1000);
                        });

                        try {
                            var consultaResponse = await fetch('../Emida/lookup_transaction.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    terminalId: datosVenta.terminalId,
                                    clerkId: datosVenta.clerkId,
                                    invoiceNo: invoiceNo,
                                    amount: datosVenta.amount,
                                    accountId: datosVenta.accountId
                                })
                            });

                            var resultadoConsulta = await consultaResponse.json();
                            console.log('Intento ' + intento + ' - Resultado consulta:', resultadoConsulta);

                            // Si encontró la transacción (incluso con invoice diferente)
                            if (resultadoConsulta.success && resultadoConsulta.found === true && resultadoConsulta.approved === true) {
                                mostrarMensajeVenta('¡Transacción confirmada exitosamente!', 'success');

                                // Si hubo mismatch de invoice, mostrar advertencia
                                if (resultadoConsulta.invoice_mismatch) {
                                    mostrarNotificacion(`Nota: La transacción se registró con invoice ${resultadoConsulta.invoiceNo} en lugar de ${resultadoConsulta.original_invoice}`, 'info');
                                }

                                return {
                                    success: true,
                                    confirmed_by_lookup: true,
                                    responseCode: resultadoConsulta.responseCode || '00',
                                    responseMessage: resultadoConsulta.responseMessage || 'Transacción exitosa',
                                    transactionId: resultadoConsulta.transactionId,
                                    carrierControlNo: resultadoConsulta.carrierControlNo,
                                    pin: resultadoConsulta.pin,
                                    transactionDateTime: resultadoConsulta.transactionDateTime,
                                    productId: datosVenta.productId,
                                    productName: datosVenta.product_name,
                                    accountId: datosVenta.accountId,
                                    amount: datosVenta.amount,
                                    invoiceNo: resultadoConsulta.invoiceNo,
                                    tipoOperacion: datosVenta.tipo_operacion,
                                    flowType: datosVenta.flow_type
                                };
                            }

                            // Caso 2: Transacción encontrada pero rechazada (responseCode no es 00, y no es 32)
                            if (resultadoConsulta.success && resultadoConsulta.found === true && resultadoConsulta.approved === false && resultadoConsulta.responseCode !== '32') {
                                mostrarMensajeVenta('La transacción fue declinada: ' + resultadoConsulta.responseMessage, 'danger');
                                console.log('❌ Transacción DECLINADA');
                                return {
                                    ...resultadoConsulta,
                                    success: false,
                                    productId: datosVenta.productId,
                                    accountId: datosVenta.accountId,
                                    amount: datosVenta.amount,
                                    invoiceNo: invoiceNo,
                                    tipoOperacion: datosVenta.tipo_operacion,
                                    flowType: datosVenta.flow_type
                                };
                            }

                            // Caso 3: Transacción no encontrada aún (responseCode 32)
                            if (resultadoConsulta.success && resultadoConsulta.found === false) {
                                console.log('⏳ Transacción aún no disponible (código 32), reintentando...');
                                if (intento === config.numReintentos) {
                                    mostrarMensajeVenta('No se pudo confirmar el estado después de varios intentos. Verifique su saldo.', 'warning');
                                }
                                continue;
                            }

                            // Caso 4: Error en la consulta
                            if (!resultadoConsulta.success) {
                                console.warn('Error en consulta:', resultadoConsulta.message);
                                if (intento === config.numReintentos) {
                                    mostrarMensajeVenta('Error al consultar el estado. Verifique su saldo manualmente.', 'warning');
                                }
                            }

                        } catch (error) {
                            console.error('Error en consulta intento ' + intento + ':', error);
                        }
                    }

                    // Última verificación: comparar saldo nuevamente
                    var saldoFinalResponse = await fetch('../Emida/get_balance.php?_=' + new Date().getTime());
                    var saldoFinalData = await saldoFinalResponse.json();
                    var saldoFinal = saldoFinalData.success ? saldoFinalData.balance : 0;
                    var diferenciaFinal = saldoAnterior - saldoFinal;

                    if (diferenciaFinal >= (montoOriginal * 0.9)) {
                        console.log('✅ Transacción exitosa confirmada por saldo final');
                        return {
                            success: true,
                            confirmed_by_balance: true,
                            responseCode: '00',
                            responseMessage: 'Transacción exitosa (confirmada por cambio de saldo)',
                            productId: datosVenta.productId,
                            productName: datosVenta.product_name,
                            accountId: datosVenta.accountId,
                            amount: montoOriginal,
                            invoiceNo: invoiceNo
                        };
                    }

                    console.log('❌ No se pudo confirmar la transacción después de ' + config.numReintentos + ' intentos');
                    return {
                        success: false,
                        responseMessage: 'No se pudo confirmar la transacción. Verifique su saldo manualmente.',
                        responseCode: 'TIMEOUT',
                        productId: datosVenta.productId,
                        accountId: datosVenta.accountId,
                        amount: montoOriginal,
                        invoiceNo: invoiceNo
                    };
                }

                return respuestaInicial || {
                    success: false,
                    responseMessage: 'Error desconocido al procesar la transacción',
                    productId: datosVenta.productId,
                    accountId: datosVenta.accountId,
                    amount: datosVenta.amount,
                    invoiceNo: datosVenta.invoiceNo
                };
            }

            // =============================================
            // FUNCIÓN PARA PROCESAR VENTA
            // =============================================

            async function procesarVentaServicio(event) {
                event.preventDefault();

                var productId = document.getElementById('venta_productId').value;
                var productName = document.getElementById('venta_productName').value;
                var accountId = document.getElementById('venta_accountId').value;
                var invoiceNo = document.getElementById('venta_invoiceNo').value;
                var montoFijo = document.getElementById('venta_montoFijo').value;
                var montoVariable = document.getElementById('venta_amount').value;
                var tipoOperacion = document.getElementById('venta_tipo_operacion').value;
                var flowType = document.getElementById('venta_flowType').value;
                var extraReference = document.getElementById('venta_extraReference') ? document.getElementById('venta_extraReference').value : '';

                var amount;
                if (montoFijo && parseFloat(montoFijo) > 0) {
                    amount = montoFijo;
                } else {
                    amount = montoVariable;
                }

                if (!amount || parseFloat(amount) <= 0) {
                    mostrarMensajeVenta('Por favor ingrese un monto válido', 'danger');
                    return;
                }

                if (!invoiceNo || parseInt(invoiceNo) < 1 || parseInt(invoiceNo) > 99999) {
                    mostrarMensajeVenta('El número de invoice debe estar entre 1 y 99999', 'danger');
                    return;
                }

                if (!accountId || accountId.trim() === '') {
                    mostrarMensajeVenta('Por favor ingrese el número de referencia', 'danger');
                    return;
                }

                // Validaciones de referencia
                if (servicioActual && servicioActual.referencias && servicioActual.referencias.length > 0) {
                    var refPrincipal = servicioActual.referencias[0];
                    if (refPrincipal.min && refPrincipal.min > 0 && accountId.length < parseInt(refPrincipal.min)) {
                        mostrarMensajeVenta('La referencia debe tener al menos ' + refPrincipal.min + ' caracteres', 'danger');
                        return;
                    }
                    if (refPrincipal.max && refPrincipal.max > 0 && accountId.length > parseInt(refPrincipal.max)) {
                        mostrarMensajeVenta('La referencia no debe exceder ' + refPrincipal.max + ' caracteres', 'danger');
                        return;
                    }
                }

                var key = 'ultimoInvoice_' + terminalId;
                localStorage.setItem(key, invoiceNo);

                var btnProcesar = document.getElementById('btnProcesarVenta');
                var textoOriginal = btnProcesar.innerHTML;
                btnProcesar.disabled = true;
                btnProcesar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando...';

                // Enviar datos NORMALES - el backend se encargará del intercambio
                var datosVenta = {
                    version: "01",
                    terminalId: terminalId,
                    clerkId: clerkId,
                    productId: productId,
                    product_name: productName,
                    accountId: accountId, // Referencia (teléfono)
                    amount: amount, // Monto a pagar
                    invoiceNo: invoiceNo,
                    languageOption: "1",
                    tipo_operacion: tipoOperacion,
                    flow_type: flowType
                };

                if (extraReference && extraReference.trim() !== '') {
                    datosVenta.extraReference = extraReference;
                }

                try {
                    var resultado = await procesarVentaConTimeout(datosVenta);

                    resultado.productName = productName;
                    resultado.invoiceNo = invoiceNo;
                    resultado.tipoOperacion = tipoOperacion;
                    resultado.flowType = flowType;

                    var ventaModal = bootstrap.Modal.getInstance(document.getElementById('ventaServicioModal'));
                    if (ventaModal) ventaModal.hide();

                    mostrarResultadoVenta(resultado);

                    if (resultado.success === true) {
                        setTimeout(function() {
                            obtenerSaldoEmida(false);
                        }, 2000);
                    }

                } catch (error) {
                    console.error('Error en procesamiento:', error);
                    mostrarResultadoVenta({
                        success: false,
                        responseMessage: 'Error de conexión: ' + error.message,
                        productName: productName,
                        accountId: accountId,
                        amount: amount,
                        invoiceNo: invoiceNo,
                        tipoOperacion: tipoOperacion
                    });
                } finally {
                    btnProcesar.disabled = false;
                    btnProcesar.innerHTML = textoOriginal;
                }
            }

            function mostrarResultadoVenta(data) {
    var modalTitle = document.getElementById('resultadoModalTitle');
    var modalHeader = document.getElementById('resultadoModalHeader');
    var modalBody = document.getElementById('resultadoModalBody');

    var esPago = data.tipoOperacion === 'pago' || data.flowType === 'B' || data.flowType === 'F';
    var tipoTexto = esPago ? 'Pago de Servicio' : 'Recarga';
    var isDuplicate = data.isDuplicate === true;
    var confirmedByBalance = data.confirmed_by_balance === true;
    var confirmedByLookup = data.confirmed_by_lookup === true;

    // Obtener el número de autorización
    var authNumber = data.carrierControlNo || data.transactionId || 'N/A';
    
    // Obtener el mensaje de respuesta del proveedor
    var providerMessage = data.responseMessage || '';
    
    // Limpiar el mensaje para mostrar (reemplazar saltos de línea)
    if (providerMessage) {
        providerMessage = providerMessage.replace(/\n/g, '<br>');
    }

    if (data.success === true) {
        modalHeader.className = 'modal-header bg-success text-white';
        modalTitle.innerHTML = '<i class="fas fa-check-circle me-2"></i>¡' + tipoTexto + ' Exitoso!';

        var confirmationText = '';
        if (confirmedByLookup) {
            confirmationText = '<div class="alert alert-success mt-2"><i class="fas fa-check-circle me-2"></i>✓ Transacción confirmada por el proveedor</div>';
        } else if (confirmedByBalance) {
            confirmationText = '<div class="alert alert-info mt-2"><i class="fas fa-chart-line me-2"></i>✓ Transacción confirmada por cambio de saldo</div>';
        }

        var duplicateWarning = '';
        if (isDuplicate) {
            duplicateWarning = '<div class="alert alert-warning mt-3"><i class="fas fa-exclamation-triangle me-2"></i>Transacción duplicada - No se cobrará nuevamente</div>';
        }

        // Mostrar mensaje del proveedor si existe y no es el mensaje genérico
        var providerMessageHtml = '';
        if (providerMessage && 
            providerMessage !== 'Transacción exitosa (confirmada por cambio de saldo)' && 
            providerMessage !== 'Transacción exitosa' &&
            providerMessage !== 'Transacción exitosa (confirmada por lookup)') {
            providerMessageHtml = `
                <div class="alert alert-primary mt-3">
                    <i class="fas fa-building me-2"></i>
                    <strong>Mensaje del proveedor:</strong><br>
                    <small>${providerMessage}</small>
                </div>
            `;
        }

        modalBody.innerHTML = `
            <div class="text-center mb-4">
                <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
            </div>
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title fw-bold">Detalles de la transacción</h6>
                    <table class="table table-sm">
                        <tr><td><strong>Servicio:</strong></td><td>${escapeHtml(data.productName || 'N/A')}</td></tr>
                        <tr><td><strong>Tipo:</strong></td><td><span class="badge ${esPago ? 'bg-warning' : 'bg-success'}">${tipoTexto}</span></td></tr>
                        <tr><td><strong>Referencia:</strong></td><td>${escapeHtml(data.accountId || 'N/A')}</td></tr>
                        <tr><td><strong>No. Autorización:</strong></td><td><span class="badge bg-primary">${escapeHtml(authNumber)}</span></td></tr>
                        <tr><td><strong>Monto:</strong></td><td>$${parseFloat(data.amount || 0).toFixed(2)}</td></tr>
                        <tr><td><strong>Invoice No:</strong></td><td>${escapeHtml(data.invoiceNo || 'N/A')}</td></tr>
                        <tr><td><strong>Fecha/Hora:</strong></td><td>${data.transactionDateTime ? new Date(data.transactionDateTime).toLocaleString() : new Date().toLocaleString()}</td></tr>
                    </table>
                    ${providerMessageHtml}
                    ${confirmationText}
                    ${duplicateWarning}
                </div>
            </div>
        `;
    } else {
        modalHeader.className = 'modal-header bg-danger text-white';
        modalTitle.innerHTML = '<i class="fas fa-times-circle me-2"></i>' + tipoTexto + ' Fallido';

        modalBody.innerHTML = `
            <div class="text-center mb-4">
                <i class="fas fa-times-circle text-danger" style="font-size: 4rem;"></i>
            </div>
            <div class="alert alert-danger">
                <strong>Error:</strong> ${escapeHtml(data.responseMessage || 'No se pudo procesar la transacción')}
            </div>
            <table class="table table-sm">
                <tr><td><strong>Servicio:</strong></td><td>${escapeHtml(data.productName || 'N/A')}</td></tr>
                <tr><td><strong>Referencia:</strong></td><td>${escapeHtml(data.accountId || 'N/A')}</td></tr>
                <tr><td><strong>Monto:</strong></td><td>$${parseFloat(data.amount || 0).toFixed(2)}</td></tr>
                <tr><td><strong>Código:</strong></td><td>${escapeHtml(data.responseCode || 'N/A')} / ${escapeHtml(data.h2hResultCode || 'N/A')}</td></tr>
            </table>
            ${data.requires_lookup ? '<div class="alert alert-warning mt-3"><i class="fas fa-clock me-2"></i>La transacción está en proceso de verificación. Verifique su saldo antes de reintentar.</div>' : ''}
        `;
    }

    window.ultimaVentaData = data;
    var modal = new bootstrap.Modal(document.getElementById('resultadoVentaModal'));
    modal.show();
}

            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function imprimirTicket() {
                var data = window.ultimaVentaData;
                if (!data) {
                    mostrarNotificacion('No hay datos para imprimir', 'warning');
                    return;
                }

                var fecha = new Date();
                var esPago = data.tipoOperacion === 'pago' || data.flowType === 'B' || data.flowType === 'F';

                var ticketContent = `
                <html>
                <head>
                    <title>Ticket de Venta Emida</title>
                    <style>
                        body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 10px; }
                        .ticket { max-width: 80mm; margin: 0 auto; }
                        .header { text-align: center; font-weight: bold; margin-bottom: 10px; }
                        .line { border-top: 1px dashed #000; margin: 10px 0; }
                        .row { display: flex; justify-content: space-between; margin: 5px 0; }
                        .label { font-weight: bold; }
                        .success { color: green; font-weight: bold; }
                        .error { color: red; font-weight: bold; }
                        .footer { text-align: center; margin-top: 15px; font-size: 10px; }
                    </style>
                </head>
                <body>
                    <div class="ticket">
                        <div class="header">
                            <h3>COMPROBANTE DE VENTA</h3>
                            <p>EMIDA SERVICIOS</p>
                            <p>${fecha.toLocaleString()}</p>
                        </div>
                        <div class="line"></div>
                        <div class="row"><span class="label">Servicio:</span><span>${escapeHtml(data.productName || 'N/A')}</span></div>
                        <div class="row"><span class="label">Tipo:</span><span>${esPago ? 'PAGO' : 'RECARGA'}</span></div>
                        <div class="row"><span class="label">Referencia:</span><span>${escapeHtml(data.accountId || 'N/A')}</span></div>
                        <div class="row"><span class="label">Monto:</span><span>$${parseFloat(data.amount || 0).toFixed(2)}</span></div>
                        <div class="row"><span class="label">No. Autorización:</span><span>${escapeHtml(data.carrierControlNo || data.transactionId || 'N/A')}</span></div>
                        <div class="row"><span class="label">Invoice No:</span><span>${escapeHtml(data.invoiceNo || 'N/A')}</span></div>
                        <div class="line"></div>
                        <div class="row"><span class="label">Estado:</span><span class="${data.success ? 'success' : 'error'}">${data.success ? 'EXITOSA' : 'FALLIDA'}</span></div>
                        <p class="small">${escapeHtml(data.responseMessage || '')}</p>
                        <div class="footer"><p>¡Gracias por su compra!</p></div>
                    </div>
                    <script>window.onload = function() { window.print(); window.close(); }<\/script>
                </body>
                </html>
            `;

                var ventanaImpresion = window.open('', '_blank');
                ventanaImpresion.document.write(ticketContent);
                ventanaImpresion.document.close();
            }

            // =============================================
            // FUNCIONES DE SALDO Y ESTADÍSTICAS
            // =============================================

            function obtenerSaldoEmida(mostrarNotificacionFlag) {
                if (mostrarNotificacionFlag === undefined) mostrarNotificacionFlag = true;

                var saldoElement = document.getElementById('saldoEmida');
                if (!saldoElement) return;

                var btnActualizar = document.querySelector('button[onclick="actualizarSaldo()"]');
                var btnOriginalHtml = '';
                if (btnActualizar) {
                    btnOriginalHtml = btnActualizar.innerHTML;
                    btnActualizar.disabled = true;
                    btnActualizar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Actualizando...';
                }

                saldoElement.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span><span>Cargando...</span>';

                fetch('../Emida/get_balance.php?_=' + new Date().getTime())
                    .then(function(response) {
                        if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
                        return response.json();
                    })
                    .then(function(data) {
                        console.log('Respuesta de saldo:', data);
                        if (data.success) {
                            var saldoFormateado = new Intl.NumberFormat('es-MX', {
                                style: 'currency',
                                currency: 'MXN',
                                minimumFractionDigits: 2
                            }).format(data.balance);
                            saldoElement.textContent = saldoFormateado;

                            if (data.balance < 100) {
                                saldoElement.classList.add('text-danger');
                                if (mostrarNotificacionFlag) mostrarAlerta('Saldo crítico: ' + saldoFormateado, 'danger');
                            } else if (data.balance < 500) {
                                saldoElement.classList.add('text-warning');
                            } else {
                                saldoElement.classList.add('text-success');
                            }

                            if (mostrarNotificacionFlag) mostrarNotificacion('Saldo actualizado: ' + saldoFormateado, 'success');
                        } else {
                            saldoElement.textContent = '$0.00';
                            saldoElement.classList.add('text-danger');
                            if (mostrarNotificacionFlag) mostrarNotificacion('Error: ' + (data.message || 'No se pudo obtener el saldo'), 'danger');
                        }
                    })
                    .catch(function(error) {
                        console.error('Error al obtener saldo:', error);
                        saldoElement.textContent = '$0.00';
                        saldoElement.classList.add('text-danger');
                        if (mostrarNotificacionFlag) mostrarNotificacion('Error de conexión al obtener saldo', 'danger');
                    })
                    .finally(function() {
                        if (btnActualizar) {
                            btnActualizar.disabled = false;
                            btnActualizar.innerHTML = btnOriginalHtml || '<i class="fas fa-sync-alt me-1"></i>Actualizar Saldo';
                        }
                    });
            }

            function actualizarSaldo() {
                obtenerSaldoEmida(true);
            }

            function iniciarActualizacionAutomaticaSaldo() {
                obtenerSaldoEmida(false);
                setInterval(function() {
                    obtenerSaldoEmida(false);
                }, 300000);
                document.addEventListener('visibilitychange', function() {
                    if (!document.hidden) obtenerSaldoEmida(false);
                });
            }

            function cargarEstadisticasEmida() {
                obtenerSaldoEmida(false);
                var transaccionesEl = document.getElementById('transaccionesHoy');
                var ventasEl = document.getElementById('ventasDia');
                var comisionesEl = document.getElementById('comisionesHoy');
                if (transaccionesEl) transaccionesEl.textContent = '0';
                if (ventasEl) ventasEl.textContent = '$0.00';
                if (comisionesEl) comisionesEl.textContent = '$0.00';
            }

            // =============================================
            // FUNCIONES DEL CATÁLOGO DE SERVICIOS
            // =============================================

            function filtrarServicios() {
                var textoBusqueda = document.getElementById('buscarServicio').value.toLowerCase();
                var categoria = document.getElementById('filtroCategoria').value;
                var carrier = document.getElementById('filtroCarrier').value;
                var rows = document.querySelectorAll('.servicio-row');

                rows.forEach(function(row) {
                    var nombre = row.getAttribute('data-nombre').toLowerCase();
                    var rowCategoria = row.getAttribute('data-categoria');
                    var rowCarrier = row.getAttribute('data-carrier');
                    var coincideTexto = textoBusqueda === '' || nombre.indexOf(textoBusqueda) !== -1;
                    var coincideCategoria = categoria === '' || rowCategoria === categoria;
                    var coincideCarrier = carrier === '' || rowCarrier === carrier;
                    row.style.display = (coincideTexto && coincideCategoria && coincideCarrier) ? '' : 'none';
                });
            }

            function limpiarFiltros() {
                document.getElementById('buscarServicio').value = '';
                document.getElementById('filtroCategoria').value = '';
                document.getElementById('filtroCarrier').value = '';
                filtrarServicios();
            }

            function buscarServicioPorId(id) {
                return serviciosData.find(function(s) {
                    return s.id === id;
                });
            }

            function venderServicio(id) {
                var servicio = buscarServicioPorId(id);
                if (!servicio) {
                    mostrarNotificacion('No se encontró información del servicio', 'danger');
                    return;
                }

                servicioActual = servicio;
                var esPagoServicio = (servicio.flow_type === 'B' || servicio.flow_type === 'F');

                document.getElementById('venta_tipo_operacion').value = esPagoServicio ? 'pago' : 'recarga';
                document.getElementById('venta_productId').value = servicio.id;
                document.getElementById('venta_productName').value = servicio.nombre;

                var tipoBadge = esPagoServicio ? '<span class="badge bg-warning ms-2">Pago de Servicio</span>' : '<span class="badge bg-success ms-2">Recarga</span>';
                document.getElementById('venta_nombreServicio').innerHTML = servicio.nombre + tipoBadge;
                document.getElementById('venta_carrierServicio').textContent = servicio.carrier || 'Proveedor no especificado';
                document.getElementById('venta_montoFijo').value = servicio.monto;
                document.getElementById('venta_montoMin').value = servicio.monto_min;
                document.getElementById('venta_montoMax').value = servicio.monto_max;
                document.getElementById('venta_flowType').value = servicio.flow_type;

                configurarCampoReferencia(servicio);
                configurarCampoMonto(servicio);
                generarNumeroInvoice();
                mostrarValidaciones(servicio);

                document.getElementById('mensajeVenta').style.display = 'none';
                document.getElementById('mensajeVenta').innerHTML = '';

                var modal = new bootstrap.Modal(document.getElementById('ventaServicioModal'));
                modal.show();
            }

            function venderServicioModal() {
                if (servicioActual) {
                    venderServicio(servicioActual.id);
                    var modal = bootstrap.Modal.getInstance(document.getElementById('detalleServicioModal'));
                    if (modal) modal.hide();
                }
            }

            function configurarCampoReferencia(servicio) {
                var inputReferencia = document.getElementById('venta_accountId');
                var labelReferencia = document.getElementById('labelReferencia');
                var prefijoSpan = document.getElementById('prefijoReferencia');
                var tooltip = document.getElementById('referenciaTooltip');

                inputReferencia.value = '';
                inputReferencia.removeAttribute('pattern');
                inputReferencia.removeAttribute('minlength');
                inputReferencia.removeAttribute('maxlength');
                prefijoSpan.textContent = '';
                prefijoSpan.classList.remove('prefijo-referencia');

                if (servicio.referencias && servicio.referencias.length > 0) {
                    var refPrincipal = servicio.referencias[0];
                    labelReferencia.textContent = (refPrincipal.nombre || 'Número de Referencia') + ' *';

                    if (refPrincipal.prefijo && refPrincipal.prefijo !== 'NA' && refPrincipal.prefijo !== 'N/A') {
                        prefijoSpan.textContent = refPrincipal.prefijo;
                        prefijoSpan.classList.add('prefijo-referencia');
                    }

                    if (refPrincipal.tipo === 'NM') {
                        inputReferencia.type = 'tel';
                        inputReferencia.pattern = '\\d*';
                        inputReferencia.inputMode = 'numeric';
                    } else {
                        inputReferencia.type = 'text';
                        inputReferencia.removeAttribute('pattern');
                        inputReferencia.inputMode = 'text';
                    }

                    if (refPrincipal.min && refPrincipal.min > 0 && refPrincipal.min !== 'NA') {
                        inputReferencia.minLength = parseInt(refPrincipal.min);
                    }
                    if (refPrincipal.max && refPrincipal.max > 0 && refPrincipal.max !== 'NA') {
                        inputReferencia.maxLength = parseInt(refPrincipal.max);
                    }

                    tooltip.textContent = (refPrincipal.tooltip && refPrincipal.tooltip !== 'NA') ?
                        refPrincipal.tooltip :
                        'Longitud: ' + (refPrincipal.min || 0) + ' - ' + (refPrincipal.max || 'N/A') + ' caracteres';

                    if (servicio.referencias.length > 1) {
                        var refExtra = servicio.referencias[1];
                        document.getElementById('campoReferenciaExtra').style.display = 'block';
                        document.getElementById('labelReferenciaExtra').textContent = refExtra.nombre || 'Referencia Adicional';
                        var inputExtra = document.getElementById('venta_extraReference');
                        inputExtra.value = '';

                        if (refExtra.tipo === 'NM') {
                            inputExtra.type = 'tel';
                            inputExtra.pattern = '\\d*';
                            inputExtra.inputMode = 'numeric';
                        } else {
                            inputExtra.type = 'text';
                            inputExtra.removeAttribute('pattern');
                            inputExtra.inputMode = 'text';
                        }

                        if (refExtra.min && refExtra.min > 0 && refExtra.min !== 'NA') {
                            inputExtra.minLength = parseInt(refExtra.min);
                        }
                        if (refExtra.max && refExtra.max > 0 && refExtra.max !== 'NA') {
                            inputExtra.maxLength = parseInt(refExtra.max);
                        }

                        var tooltipExtra = document.getElementById('tooltipReferenciaExtra');
                        if (tooltipExtra) {
                            tooltipExtra.textContent = (refExtra.tooltip && refExtra.tooltip !== 'NA') ? refExtra.tooltip : '';
                        }
                    } else {
                        document.getElementById('campoReferenciaExtra').style.display = 'none';
                        document.getElementById('venta_extraReference').value = '';
                    }
                } else {
                    labelReferencia.textContent = 'Número de Referencia *';
                    prefijoSpan.textContent = '';
                    inputReferencia.type = 'text';
                    inputReferencia.removeAttribute('pattern');
                    inputReferencia.removeAttribute('minlength');
                    inputReferencia.removeAttribute('maxlength');
                    inputReferencia.inputMode = 'text';
                    tooltip.textContent = 'Ingrese el número de referencia';
                    document.getElementById('campoReferenciaExtra').style.display = 'none';
                    document.getElementById('venta_extraReference').value = '';
                }
            }

            function configurarCampoMonto(servicio) {
                var campoMonto = document.getElementById('campoMonto');
                var inputMonto = document.getElementById('venta_amount');
                var rangoMonto = document.getElementById('rangoMonto');

                inputMonto.value = '';

                if (servicio.monto === 0 || servicio.monto === '0') {
                    campoMonto.style.display = 'block';
                    inputMonto.required = true;
                    inputMonto.min = 0;

                    if (servicio.monto_min > 0) inputMonto.min = servicio.monto_min;
                    if (servicio.monto_max > 0) inputMonto.max = servicio.monto_max;

                    if (servicio.monto_min > 0 && servicio.monto_max > 0) {
                        rangoMonto.textContent = 'Rango válido: $' + servicio.monto_min.toFixed(2) + ' - $' + servicio.monto_max.toFixed(2);
                    } else if (servicio.monto_min > 0) {
                        rangoMonto.textContent = 'Monto mínimo: $' + servicio.monto_min.toFixed(2);
                    } else if (servicio.monto_max > 0) {
                        rangoMonto.textContent = 'Monto máximo: $' + servicio.monto_max.toFixed(2);
                    } else {
                        rangoMonto.textContent = '';
                    }
                } else {
                    campoMonto.style.display = 'none';
                    inputMonto.required = false;
                    inputMonto.value = servicio.monto;
                }
            }

            function generarNumeroInvoice() {
                var key = 'ultimoInvoice_' + terminalId;
                var ultimoInvoice = localStorage.getItem(key);
                ultimoInvoice = ultimoInvoice ? parseInt(ultimoInvoice) + 1 : 1;
                if (ultimoInvoice > 99999) ultimoInvoice = 1;
                document.getElementById('venta_invoiceNo').value = ultimoInvoice;
                localStorage.setItem(key, ultimoInvoice);
            }

            function mostrarValidaciones(servicio) {
                var listaValidaciones = document.getElementById('listaValidaciones');
                var validaciones = [];

                if (servicio.referencias && servicio.referencias.length > 0) {
                    var ref = servicio.referencias[0];
                    validaciones.push('• ' + ref.nombre + ': ' + (ref.min || 0) + ' - ' + (ref.max || 'N/A') + ' caracteres, tipo ' + (ref.tipo === 'NM' ? 'numérico' : 'alfanumérico'));
                    if (ref.tooltip && ref.tooltip !== 'NA') validaciones.push('• ' + ref.tooltip);
                    if (servicio.referencias.length > 1) {
                        var refExtra = servicio.referencias[1];
                        validaciones.push('• ' + refExtra.nombre + ': Opcional, ' + (refExtra.min || 0) + ' - ' + (refExtra.max || 'N/A') + ' caracteres');
                    }
                }

                if (servicio.monto === 0) {
                    if (servicio.monto_min > 0 && servicio.monto_max > 0) {
                        validaciones.push('• Monto variable: Mín: $' + servicio.monto_min.toFixed(2) + ', Máx: $' + servicio.monto_max.toFixed(2));
                    } else if (servicio.monto_min > 0) {
                        validaciones.push('• Monto mínimo: $' + servicio.monto_min.toFixed(2));
                    } else if (servicio.monto_max > 0) {
                        validaciones.push('• Monto máximo: $' + servicio.monto_max.toFixed(2));
                    }
                } else {
                    validaciones.push('• Monto fijo: $' + servicio.monto.toFixed(2));
                }

                validaciones.push('• InvoiceNo debe ser consecutivo (1-99999)');

                var html = '';
                for (var i = 0; i < validaciones.length; i++) {
                    html += '<li class="validacion-item"><i class="fas fa-check-circle text-success me-2"></i>' + validaciones[i] + '</li>';
                }
                listaValidaciones.innerHTML = html;
            }

            function verDetalleServicio(id) {
                var servicio = buscarServicioPorId(id);
                if (!servicio) {
                    mostrarNotificacion('No se encontró información del servicio', 'danger');
                    return;
                }
                servicioActual = servicio;

                var contenidoModal = generarContenidoDetalle(servicio);
                document.getElementById('detalleServicioContent').innerHTML = contenidoModal;
                document.getElementById('btnVenderDesdeModal').setAttribute('onclick', 'venderServicio(\'' + servicio.id + '\')');

                var modal = new bootstrap.Modal(document.getElementById('detalleServicioModal'));
                modal.show();
            }

            function generarContenidoDetalle(servicio) {
                var flowTypeBadge = '';
                if (servicio.flow_type === 'A') {
                    flowTypeBadge = '<span class="badge bg-success">Venta Directa</span>';
                } else if (servicio.flow_type === 'B') {
                    flowTypeBadge = '<span class="badge bg-warning">Consulta/Pago</span>';
                } else if (servicio.flow_type === 'K') {
                    flowTypeBadge = '<span class="badge bg-primary">Recarga</span>';
                } else if (servicio.flow_type === 'F') {
                    flowTypeBadge = '<span class="badge bg-info">Pago Servicios</span>';
                } else {
                    flowTypeBadge = '<span class="badge bg-secondary">' + servicio.flow_type + '</span>';
                }

                var paymentTypeMap = {
                    'Total': 'Pago Completo',
                    'Parciales': 'Pago Parcial',
                    'Parciales Totales y Vencidos': 'Pagos Parciales, Totales y Vencidos',
                    'fixed': 'Monto Fijo'
                };
                var paymentTypeText = paymentTypeMap[servicio.payment_type] || servicio.payment_type;

                var referenciasHtml = '';
                if (servicio.referencias && servicio.referencias.length > 0) {
                    referenciasHtml = '<div class="detail-section"><h6><i class="fas fa-list-alt me-2"></i>Campos de Referencia</h6>';
                    for (var idx = 0; idx < servicio.referencias.length; idx++) {
                        var ref = servicio.referencias[idx];
                        referenciasHtml += `
                        <div class="reference-card" onclick="verDetalleReferencia(${idx})">
                            <div class="reference-title"><i class="fas fa-tag me-2"></i>${escapeHtml(ref.nombre)}</div>
                            <div class="reference-detail">
                                <span><i class="fas fa-font me-1"></i>Tipo: ${ref.tipo === 'NM' ? 'Numérico' : 'Alfanumérico'}</span>
                                <span><i class="fas fa-arrows-alt-h me-1"></i>Longitud: ${ref.min || 0} - ${ref.max || 'N/A'}</span>
                            </div>
                            ${(ref.tooltip && ref.tooltip !== 'NA') ? '<small class="text-muted d-block mt-1"><i class="fas fa-info-circle me-1"></i>' + escapeHtml(ref.tooltip) + '</small>' : ''}
                            ${(ref.imagen && ref.imagen !== 'NA') ? '<img src="' + ref.imagen + '" class="reference-image" alt="Referencia" style="max-width:100%;max-height:60px;margin-top:8px;border-radius:4px;" onerror="this.style.display=\'none\'">' : ''}
                        </div>
                    `;
                    }
                    referenciasHtml += '</div>';
                }

                return `
                <div class="detail-section">
                    <h6><i class="fas fa-cube me-2"></i>Información General</h6>
                    <div class="detail-item"><span class="detail-label">ID del Servicio:</span><span class="detail-value"><span class="badge bg-secondary">${escapeHtml(servicio.id)}</span></span></div>
                    <div class="detail-item"><span class="detail-label">Nombre:</span><span class="detail-value"><strong>${escapeHtml(servicio.nombre)}</strong></span></div>
                    <div class="detail-item"><span class="detail-label">Categoría:</span><span class="detail-value"><span class="badge bg-info">${escapeHtml(servicio.categoria || 'N/A')}</span></span></div>
                    <div class="detail-item"><span class="detail-label">Subcategoría:</span><span class="detail-value">${escapeHtml(servicio.subcategoria || 'N/A')}</span></div>
                    <div class="detail-item"><span class="detail-label">Carrier/Proveedor:</span><span class="detail-value">${escapeHtml(servicio.carrier || 'N/A')}</span></div>
                </div>
                <div class="detail-section">
                    <h6><i class="fas fa-dollar-sign me-2"></i>Información de Precios</h6>
                    <div class="detail-item"><span class="detail-label">Monto Fijo:</span><span class="detail-value">${servicio.monto > 0 ? '<strong>$' + servicio.monto.toFixed(2) + '</strong>' : '<span class="text-muted">Variable</span>'}</span></div>
                    <div class="detail-item"><span class="detail-label">Monto Mínimo:</span><span class="detail-value">$${servicio.monto_min.toFixed(2)}</span></div>
                    <div class="detail-item"><span class="detail-label">Monto Máximo:</span><span class="detail-value">$${servicio.monto_max.toFixed(2)}</span></div>
                    <div class="detail-item"><span class="detail-label">Comisión:</span><span class="detail-value"><span class="text-success">$${servicio.comision.toFixed(2)}</span></span></div>
                    <div class="detail-item"><span class="detail-label">Moneda:</span><span class="detail-value">${escapeHtml(servicio.moneda || 'MXN')}</span></div>
                </div>
                <div class="detail-section">
                    <h6><i class="fas fa-cog me-2"></i>Configuración del Servicio</h6>
                    <div class="detail-item"><span class="detail-label">Tipo de Flujo:</span><span class="detail-value">${flowTypeBadge}</span></div>
                    <div class="detail-item"><span class="detail-label">Tipo de Pago:</span><span class="detail-value"><span class="badge bg-primary">${escapeHtml(paymentTypeText)}</span></span></div>
                </div>
                ${referenciasHtml}
                <div class="detail-section">
                    <h6><i class="fas fa-tags me-2"></i>Características</h6>
                    <div class="d-flex flex-wrap">
                        ${servicio.monto === 0 ? '<span class="badge-feature bg-light text-dark border"><i class="fas fa-minus-circle me-1"></i>Monto variable</span>' : ''}
                        ${servicio.referencias && servicio.referencias.length > 0 ? '<span class="badge-feature bg-light text-dark border"><i class="fas fa-list me-1"></i>Requiere referencias</span>' : ''}
                        ${servicio.flow_type === 'A' || servicio.flow_type === 'K' ? '<span class="badge-feature bg-light text-dark border"><i class="fas fa-bolt me-1"></i>Venta inmediata</span>' : ''}
                        ${servicio.flow_type === 'B' || servicio.flow_type === 'F' ? '<span class="badge-feature bg-light text-dark border"><i class="fas fa-search me-1"></i>Requiere consulta</span>' : ''}
                        ${servicio.comision > 0 ? '<span class="badge-feature bg-light text-dark border"><i class="fas fa-percentage me-1"></i>Genera comisión</span>' : ''}
                    </div>
                </div>
            `;
            }

            function verDetalleReferencia(index) {
                if (!servicioActual || !servicioActual.referencias || !servicioActual.referencias[index]) return;

                var ref = servicioActual.referencias[index];
                var contenido = `
                <div class="detail-section">
                    <h6>${escapeHtml(ref.nombre)}</h6>
                    <div class="detail-item"><span class="detail-label">Tipo de campo:</span><span class="detail-value">${ref.tipo === 'NM' ? 'Numérico' : 'Alfanumérico'}</span></div>
                    <div class="detail-item"><span class="detail-label">Longitud mínima:</span><span class="detail-value">${ref.min || 'No especificada'}</span></div>
                    <div class="detail-item"><span class="detail-label">Longitud máxima:</span><span class="detail-value">${ref.max || 'No especificada'}</span></div>
                    <div class="detail-item"><span class="detail-label">Prefijo:</span><span class="detail-value">${ref.prefijo && ref.prefijo !== 'NA' ? ref.prefijo : 'Sin prefijo'}</span></div>
            `;

                if (ref.tooltip && ref.tooltip !== 'NA') {
                    contenido += '<div class="detail-item"><span class="detail-label">Descripción:</span><span class="detail-value">' + escapeHtml(ref.tooltip) + '</span></div>';
                }

                if (ref.imagen && ref.imagen !== 'NA') {
                    contenido += '<div class="detail-item"><span class="detail-label">Imagen de referencia:</span><span class="detail-value"><img src="' + ref.imagen + '" alt="Referencia" style="max-width: 100%; border-radius: 4px;" onerror="this.style.display=\'none\'"></span></div>';
                }

                contenido += '</div>';
                document.getElementById('referenciasModalContent').innerHTML = contenido;

                var modal = new bootstrap.Modal(document.getElementById('referenciasModal'));
                modal.show();
            }

            function verReferencias(id) {
                var servicio = buscarServicioPorId(id);
                if (!servicio || !servicio.referencias || servicio.referencias.length === 0) {
                    mostrarNotificacion('Este servicio no tiene campos de referencia', 'info');
                    return;
                }

                servicioActual = servicio;
                var contenido = '<div class="list-group">';
                for (var idx = 0; idx < servicio.referencias.length; idx++) {
                    var ref = servicio.referencias[idx];
                    contenido += `
                    <a href="#" class="list-group-item list-group-item-action" onclick="verDetalleReferencia(${idx}); return false;">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">${escapeHtml(ref.nombre)}</h6>
                            <small class="text-muted">${ref.tipo === 'NM' ? 'Numérico' : 'Alfanumérico'}</small>
                        </div>
                        <small class="text-muted">Longitud: ${ref.min || 0} - ${ref.max || 'N/A'}</small>
                        ${(ref.tooltip && ref.tooltip !== 'NA') ? '<p class="mb-1 mt-2 small">' + escapeHtml(ref.tooltip.substring(0, 100)) + (ref.tooltip.length > 100 ? '...' : '') + '</p>' : ''}
                    </a>
                `;
                }
                contenido += '</div>';

                document.getElementById('referenciasModalContent').innerHTML = contenido;
                var modal = new bootstrap.Modal(document.getElementById('referenciasModal'));
                modal.show();
            }

            // =============================================
            // FUNCIONES DE PARÁMETROS Y CONFIGURACIÓN
            // =============================================

            function guardarParametros(event) {
                event.preventDefault();

                var parametros = {
                    terminalId: document.getElementById('terminalId').value,
                    clerkId: document.getElementById('clerkId').value,
                    urlPruebas: document.getElementById('urlPruebas').value,
                    urlProduccion: document.getElementById('urlProduccion').value,
                    comisionRecargas: document.getElementById('comisionRecargas').value,
                    comisionPagos: document.getElementById('comisionPagos').value,
                    comisionTiempoAire: document.getElementById('comisionTiempoAire').value,
                    timeoutInicial: document.getElementById('timeoutInicial').value,
                    intervaloConsulta: document.getElementById('intervaloConsulta').value,
                    numReintentos: document.getElementById('numReintentos').value,
                    horaApertura: document.getElementById('horaApertura').value,
                    horaCierre: document.getElementById('horaCierre').value,
                    modoPruebas: document.getElementById('modoPruebas').checked,
                    notificacionesEmail: document.getElementById('notificacionesEmail').checked,
                    registroTransacciones: document.getElementById('registroTransacciones').checked,
                    notificarStockBajo: document.getElementById('notificarStockBajo').checked,
                    montoMaximo: document.getElementById('montoMaximo').value,
                    montoMinimo: document.getElementById('montoMinimo').value,
                    saldoMinimo: document.getElementById('saldoMinimo').value
                };

                localStorage.setItem('parametrosEmida', JSON.stringify(parametros));
                mostrarNotificacion('Configuración guardada correctamente', 'success');
            }

            function cargarParametrosGuardados() {
                var guardados = localStorage.getItem('parametrosEmida');
                if (guardados) {
                    try {
                        var params = JSON.parse(guardados);
                        if (document.getElementById('terminalId')) document.getElementById('terminalId').value = params.terminalId || terminalId;
                        if (document.getElementById('clerkId')) document.getElementById('clerkId').value = params.clerkId || clerkId;
                        if (document.getElementById('urlPruebas')) document.getElementById('urlPruebas').value = params.urlPruebas || 'https://test.emida.com/api';
                        if (document.getElementById('urlProduccion')) document.getElementById('urlProduccion').value = params.urlProduccion || 'https://api.emida.com/v1';
                        if (document.getElementById('comisionRecargas')) document.getElementById('comisionRecargas').value = params.comisionRecargas || '0.00';
                        if (document.getElementById('comisionPagos')) document.getElementById('comisionPagos').value = params.comisionPagos || '0.00';
                        if (document.getElementById('comisionTiempoAire')) document.getElementById('comisionTiempoAire').value = params.comisionTiempoAire || '0.00';
                        if (document.getElementById('timeoutInicial')) document.getElementById('timeoutInicial').value = params.timeoutInicial || '30';
                        if (document.getElementById('intervaloConsulta')) document.getElementById('intervaloConsulta').value = params.intervaloConsulta || '5';
                        if (document.getElementById('numReintentos')) document.getElementById('numReintentos').value = params.numReintentos || '3';
                        if (document.getElementById('horaApertura')) document.getElementById('horaApertura').value = params.horaApertura || '09:00';
                        if (document.getElementById('horaCierre')) document.getElementById('horaCierre').value = params.horaCierre || '20:00';
                        if (document.getElementById('modoPruebas')) document.getElementById('modoPruebas').checked = params.modoPruebas !== undefined ? params.modoPruebas : true;
                        if (document.getElementById('notificacionesEmail')) document.getElementById('notificacionesEmail').checked = params.notificacionesEmail || false;
                        if (document.getElementById('registroTransacciones')) document.getElementById('registroTransacciones').checked = params.registroTransacciones !== undefined ? params.registroTransacciones : true;
                        if (document.getElementById('notificarStockBajo')) document.getElementById('notificarStockBajo').checked = params.notificarStockBajo || false;
                        if (document.getElementById('montoMaximo')) document.getElementById('montoMaximo').value = params.montoMaximo || '5000';
                        if (document.getElementById('montoMinimo')) document.getElementById('montoMinimo').value = params.montoMinimo || '10';
                        if (document.getElementById('saldoMinimo')) document.getElementById('saldoMinimo').value = params.saldoMinimo || '100';
                    } catch (e) {
                        console.error('Error cargando parámetros:', e);
                    }
                }
            }

            function cancelarParametros() {
                if (confirm('¿Cancelar cambios?')) {
                    cargarParametrosGuardados();
                    mostrarNotificacion('Cambios cancelados', 'info');
                }
            }

            function ayudaEmida() {
                window.open('https://ayuda.emida.com', '_blank');
            }

            function actualizarCatalogo() {
                if (confirm('¿Actualizar catálogo de servicios? Esto puede tomar unos momentos.')) {
                    mostrarNotificacion('Actualizando catálogo...', 'info');
                    location.reload();
                }
            }

            function verificarEstadoNotificaciones() {
                var statusDiv = document.getElementById('notificationStatus');
                if (!statusDiv) return;

                statusDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin me-2"></i>Verificando estado...</div>';

                fetch('../Emida/SubmitPaymentNotificationUtil.php?action=status')
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success && data.notification_status && data.notification_status.success) {
                            statusDiv.innerHTML = `
                            <div class="alert alert-success mb-0">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Notificaciones configuradas correctamente</strong><br>
                                <small>Última verificación: ${new Date().toLocaleString()}</small>
                            </div>
                        `;
                        } else {
                            statusDiv.innerHTML = `
                            <div class="alert alert-warning mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Notificaciones no configuradas</strong><br>
                                <small>${data.message || 'Se requiere configuración adicional'}</small>
                            </div>
                        `;
                        }
                    })
                    .catch(function(error) {
                        statusDiv.innerHTML = `
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-times-circle me-2"></i>
                            <strong>Error de conexión</strong><br>
                            <small>${error.message}</small>
                        </div>
                    `;
                    });
            }

            function cargarTransacciones() {
                mostrarNotificacion('Función en desarrollo - Cargar transacciones', 'info');
            }

            function exportarPDF() {
                mostrarNotificacion('Función en desarrollo - Exportar a PDF', 'info');
            }

            function exportarExcel() {
                mostrarNotificacion('Función en desarrollo - Exportar a Excel', 'info');
            }

            function imprimirReporte() {
                mostrarNotificacion('Función en desarrollo - Imprimir reporte', 'info');
            }

            function marcarTodosLeidos() {
                mostrarNotificacion('Función en desarrollo - Marcar todos como leídos', 'info');
            }

            function enviarMensaje() {
                mostrarNotificacion('Función en desarrollo - Enviar mensaje', 'info');
            }

            // =============================================
            // FUNCIONES DE NOTIFICACIONES DE PAGO
            // =============================================

            function enviarNotificacionPago(datosVenta) {
                console.log('Enviando notificación de pago:', datosVenta);
                if (!datosVenta.success) return;

                var notificationData = {
                    terminalId: terminalId,
                    clerkPassword: clerkId,
                    transactionId: datosVenta.transactionId || '',
                    amount: datosVenta.amount || 0,
                    accountId: datosVenta.accountId || ''
                };

                fetch('../Emida/SubmitPaymentNotificationUtil.php?action=send', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams(notificationData)
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success) console.log('Notificación de pago enviada con éxito');
                        else guardarNotificacionPendiente(notificationData);
                    })
                    .catch(function(error) {
                        console.error('Error enviando notificación:', error);
                        guardarNotificacionPendiente(notificationData);
                    });
            }

            function guardarNotificacionPendiente(datos) {
                var pendientes = JSON.parse(localStorage.getItem('notificacionesPendientes') || '[]');
                pendientes.push({
                    ...datos,
                    timestamp: new Date().toISOString(),
                    intentos: 0
                });
                localStorage.setItem('notificacionesPendientes', JSON.stringify(pendientes));
                actualizarIndicadorNotificaciones();
            }

            function reintentarNotificacionesPendientes() {
                var pendientes = JSON.parse(localStorage.getItem('notificacionesPendientes') || '[]');
                if (pendientes.length === 0) return;

                console.log('Reintentando ' + pendientes.length + ' notificaciones pendientes...');
                var nuevasPendientes = [];

                pendientes.forEach(function(notif) {
                    if (notif.intentos >= 3) return;
                    notif.intentos++;

                    fetch('../Emida/SubmitPaymentNotificationUtil.php?action=send', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: new URLSearchParams({
                                terminalId: notif.terminalId,
                                clerkPassword: notif.clerkPassword,
                                transactionId: notif.transactionId || '',
                                amount: notif.amount || 0,
                                accountId: notif.accountId || ''
                            })
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(data) {
                            if (!data.success) nuevasPendientes.push(notif);
                        })
                        .catch(function() {
                            nuevasPendientes.push(notif);
                        });
                });

                localStorage.setItem('notificacionesPendientes', JSON.stringify(nuevasPendientes));
                actualizarIndicadorNotificaciones();
            }

            function actualizarIndicadorNotificaciones() {
                var pendientes = JSON.parse(localStorage.getItem('notificacionesPendientes') || '[]');
                var badge = document.getElementById('notificacionesPendientesBadge');

                if (!badge && pendientes.length > 0) {
                    var navbar = document.querySelector('.navbar-nav');
                    if (navbar) {
                        navbar.insertAdjacentHTML('afterbegin', `
                        <li class="nav-item position-relative">
                            <span class="nav-link">
                                <i class="fas fa-bell"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notificacionesPendientesBadge">${pendientes.length}</span>
                            </span>
                        </li>
                    `);
                    }
                } else if (badge) {
                    badge.textContent = pendientes.length;
                    badge.style.display = pendientes.length > 0 ? 'inline' : 'none';
                }
            }

            // =============================================
            // INICIALIZACIÓN
            // =============================================

            document.addEventListener('DOMContentLoaded', function() {
                console.log('DOM cargado - Inicializando Emida Services con Timeout Handler');

                var sidebarToggle = document.getElementById('sidebarToggle');
                var sidebarBackdrop = document.getElementById('sidebarBackdrop');
                if (sidebarToggle) sidebarToggle.addEventListener('click', openSidebarAuto);
                if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebarAuto);

                var buscarInput = document.getElementById('buscarServicio');
                var filtroCategoria = document.getElementById('filtroCategoria');
                var filtroCarrier = document.getElementById('filtroCarrier');
                if (buscarInput) buscarInput.addEventListener('keyup', filtrarServicios);
                if (filtroCategoria) filtroCategoria.addEventListener('change', filtrarServicios);
                if (filtroCarrier) filtroCarrier.addEventListener('change', filtrarServicios);

                cargarParametrosGuardados();

                iniciarActualizacionAutomaticaSaldo();

                cargarEstadisticasEmida();

                var parametrosTab = document.getElementById('parametros-tab');
                if (parametrosTab) {
                    parametrosTab.addEventListener('shown.bs.tab', verificarEstadoNotificaciones);
                }

                setInterval(function() {
                    reintentarNotificacionesPendientes();
                }, 300000);
                setTimeout(function() {
                    reintentarNotificacionesPendientes();
                }, 10000);

                console.log('Inicialización completada');
            });
        </script>
    </body>

    </html>