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
    header("Location: login.php");
    exit();
}

// Configuración de conexión a la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = "juanc141_ventas";

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';
$empresa = null;
$id_empresa = isset($_GET['id']) ? intval($_GET['id']) : 0;

// API Key de Facturapi (deberías mover esto a una variable de entorno en producción)
//$FACTURAPI_SECRET_KEY = "sk_user_6e4yEtUNuCpW2bXDuwyFgadBLCzuCZMBjcvt73f6rT";
$FACTURAPI_SECRET_KEY = "sk_user_AmFgYy7Dw3Y48VKbQbLZUNJPJwuRjJggia6yafWSki";
// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// =====================================================================
// FUNCIÓN CORREGIDA: Contar facturas usando la API key específica de cada organización
// =====================================================================
function contarFacturasFacturapi($test_api_key, $nombre_organizacion = '')
{
    try {
        if (empty($test_api_key)) {
            return 0;
        }

        error_log("=== CONTANDO FACTURAS PARA: $nombre_organizacion ===");

        require_once dirname(__FILE__) . '/../vendor/autoload.php';
        $facturapi = new \Facturapi\Facturapi($test_api_key);

        $total_facturas_emitidas = 0;
        $page = 1;

        try {
            // Obtener todas las facturas paginadas
            while (true) {
                $response = $facturapi->Invoices->all([
                    'page' => $page,
                    'limit' => 100
                ]);

                // Verificar que la respuesta tenga la estructura esperada
                if (!is_object($response) || !property_exists($response, 'data') || !is_array($response->data)) {
                    break;
                }

                $facturas = $response->data;

                if (count($facturas) === 0) {
                    break;
                }

                // Contar facturas válidas/emitidas
                foreach ($facturas as $invoice) {
                    if (is_object($invoice)) {
                        $status = strtolower($invoice->status ?? '');

                        // En Facturapi, 'valid' = factura emitida y válida
                        // También considerar 'issued' por si acaso
                        if ($status === 'valid' || $status === 'issued') {
                            $total_facturas_emitidas++;
                        }
                    }
                }

                // Verificar si hay más páginas
                $total_pages = $response->total_pages ?? 1;
                if ($page >= $total_pages) {
                    break;
                }

                $page++;
            }
        } catch (Exception $e) {
            error_log("Error Facturapi: " . $e->getMessage());
            return 0;
        }

        error_log("✓ Facturas emitidas encontradas: $total_facturas_emitidas");
        return $total_facturas_emitidas;
    } catch (Exception $e) {
        return 0;
    }
}

// Obtener lista de giros comerciales
try {
    $sql_giros = "SELECT id, nombre FROM giro_comercial ORDER BY nombre";
    $result_giros = $conn->query($sql_giros);
    $giros_comerciales = [];

    if ($result_giros->num_rows > 0) {
        while ($row = $result_giros->fetch_assoc()) {
            $giros_comerciales[$row['id']] = $row['nombre'];
        }
    }
} catch (Exception $e) {
    $giros_comerciales = [];
}

// Cargar datos de la empresa si existe el ID
if ($id_empresa > 0) {
    try {
        $sql = "SELECT e.*, g.nombre as nombre_giro_comercial 
                FROM empresas e 
                LEFT JOIN giro_comercial g ON e.giro_comercial = g.id 
                WHERE e.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_empresa);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $empresa = $result->fetch_assoc();

            // =============================================
            // ACTUALIZACIÓN AUTOMÁTICA DE TIMBRES USADOS
            // =============================================
            // =============================================
            // ACTUALIZACIÓN AUTOMÁTICA DE TIMBRES USADOS
            // =============================================
            if (!empty($empresa['facturapi_organization_id']) && $empresa['plan'] !== 'prueba') {

                // PRIMERO: Obtener la API key de prueba de la organización
                $test_api_key = '';

                try {
                    require_once dirname(__FILE__) . '/../vendor/autoload.php';
                    $facturapi_system = new \Facturapi\Facturapi($FACTURAPI_SECRET_KEY);

                    $result_api_key = $facturapi_system->Organizations->getTestApiKey($empresa['facturapi_organization_id']);

                    // La API de Facturapi devuelve directamente la string de API key
                    if (is_string($result_api_key)) {
                        $test_api_key = $result_api_key;
                    } elseif (is_object($result_api_key) && property_exists($result_api_key, 'api_key')) {
                        $test_api_key = $result_api_key->api_key;
                    } elseif (is_array($result_api_key) && isset($result_api_key['api_key'])) {
                        $test_api_key = $result_api_key['api_key'];
                    }

                    if (!empty($test_api_key)) {
                        error_log("✓ API Key de prueba obtenida: " . substr($test_api_key, 0, 15) . "...");
                    }
                } catch (Exception $e) {
                    error_log("Error obteniendo API key: " . $e->getMessage());
                }

                // SEGUNDO: Contar facturas
                if (!empty($test_api_key)) {
                    $facturas_emitidas = contarFacturasFacturapi($test_api_key, $empresa['nombre_empresa']);

                    // Calcular timbres disponibles
                    $timbres_totales = $empresa['timbres_totales'] ?? 0;
                    $timbres_disponibles = max(0, $timbres_totales - $facturas_emitidas);

                    // Actualizar si hay cambios
                    if ($empresa['timbres_disponibles'] != $timbres_disponibles) {
                        $sql_update_timbres = "UPDATE empresas SET 
                                   timbres_disponibles = ?
                                   WHERE id = ?";

                        $stmt_update = $conn->prepare($sql_update_timbres);
                        $stmt_update->bind_param("ii", $timbres_disponibles, $id_empresa);

                        if ($stmt_update->execute()) {
                            $empresa['timbres_disponibles'] = $timbres_disponibles;

                            // Mostrar mensaje
                            if (empty($mensaje)) {
                                $mensaje = "Timbres actualizados automáticamente desde Facturapi.";
                                $tipo_mensaje = "info";
                            }
                        }
                        $stmt_update->close();
                    }

                    // Guardar datos
                    $empresa['facturas_emitidas'] = $facturas_emitidas;
                    $empresa['test_api_key'] = $test_api_key;
                } else {
                    $empresa['facturas_emitidas'] = 0;
                }
            } else {
                $empresa['facturas_emitidas'] = 0;
            }
            // =============================================
            // =============================================

            // Depurar: ver qué datos se cargaron
            error_log("=== DATOS EMPRESA CARGADA ===");
            error_log("ID Empresa: " . $empresa['id']);
            error_log("Nombre: " . $empresa['nombre_empresa']);
            error_log("Giro Comercial ID: " . $empresa['giro_comercial']);
            error_log("Nombre Giro Comercial: " . ($empresa['nombre_giro_comercial'] ?? 'NULL'));
            error_log("RFC: " . $empresa['rfc']);
            error_log("Plan: " . ($empresa['plan'] ?? 'No especificado'));
            error_log("Facturapi ID: " . ($empresa['facturapi_organization_id'] ?? 'No creado'));
            error_log("Facturas Emitidas: " . ($empresa['facturas_emitidas'] ?? '0'));
            error_log("Timbres Disponibles: " . ($empresa['timbres_disponibles'] ?? '0'));
            error_log("Timbres Totales: " . ($empresa['timbres_totales'] ?? '0'));
            if (isset($empresa['test_api_key'])) {
                error_log("Test API Key: " . substr($empresa['test_api_key'], 0, 15) . "...");
            }
            error_log("==============================");
        } else {
            $mensaje = "No se encontró la empresa con ID: $id_empresa";
            $tipo_mensaje = "warning";
        }

        $stmt->close();
    } catch (Exception $e) {
        $mensaje = "Error al cargar la empresa: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Procesar actualización de la empresa
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Recoger datos del formulario
    $nombre_empresa = $_POST['nombre_empresa'] ?? '';
    $giro_comercial = $_POST['giro_comercial'] ?? '';
    $rfc = $_POST['rfc'] ?? '';
    $telefono = $_POST['telefono'] ?? '';
    $email = $_POST['email'] ?? '';
    $direccion = $_POST['direccion'] ?? '';
    $nombre_contacto = $_POST['nombre_contacto'] ?? '';
    $estado_verificacion = $_POST['estado_verificacion'] ?? '';
    $observaciones_verificacion = $_POST['observaciones_verificacion'] ?? '';
    $plan = $_POST['plan'] ?? '';
    $activo = isset($_POST['activo']) ? 1 : 0;

    // Campos de archivos (mantener existentes si no se suben nuevos)
    $constancia_fiscal = $empresa['constancia_fiscal'] ?? '';
    $credencial_identificacion = $empresa['credencial_identificacion'] ?? '';
    $correo_enviado = isset($_POST['correo_enviado']) ? 1 : 0;

    // Variables para fechas de subida
    $fecha_subida_constancia = $empresa['fecha_subida_constancia'] ?? null;
    $fecha_subida_credencial = $empresa['fecha_subida_credencial'] ?? null;

    // Variables para timbres
    $timbres_disponibles = $empresa['timbres_disponibles'] ?? 0;
    $timbres_totales = $empresa['timbres_totales'] ?? 0;
    $fecha_activacion_timbres = $empresa['fecha_activacion_timbres'] ?? null;

    // Si estamos cambiando a premium por primera vez, asignar 500 timbres
    if ($plan === 'premium' && (!isset($empresa['plan']) || $empresa['plan'] !== 'premium')) {
        $cambiando_a_premium = true;
        $timbres_disponibles = 500;
        $timbres_totales = 500;
        $fecha_activacion_timbres = date('Y-m-d H:i:s');
    } elseif ($plan === 'premium' && isset($empresa['plan']) && $empresa['plan'] === 'premium') {
        // Si ya era premium, mantener los timbres existentes
        $timbres_disponibles = $empresa['timbres_disponibles'] ?? 0;
        $timbres_totales = $empresa['timbres_totales'] ?? 0;
        $fecha_activacion_timbres = $empresa['fecha_activacion_timbres'] ?? null;
    } else {
        // Para otros planes, mantener los valores existentes
        $timbres_disponibles = $empresa['timbres_disponibles'] ?? 0;
        $timbres_totales = $empresa['timbres_totales'] ?? 0;
        $fecha_activacion_timbres = $empresa['fecha_activacion_timbres'] ?? null;
    }

    // Crear directorios de uploads si no existen
    $uploads_dir = dirname(__FILE__) . '/../uploads/';
    $constancias_dir = $uploads_dir . 'constancias/';
    $credenciales_dir = $uploads_dir . 'credenciales/';

    // Crear directorios si no existen
    if (!file_exists($constancias_dir)) {
        mkdir($constancias_dir, 0777, true);
    }
    if (!file_exists($credenciales_dir)) {
        mkdir($credenciales_dir, 0777, true);
    }

    // Procesar archivos subidos
    if ($_FILES['constancia_fiscal']['error'] == 0) {
        $original_name = basename($_FILES['constancia_fiscal']['name']);
        $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $new_filename = uniqid() . '_' . date('Ymd_His') . '.' . $file_extension;

        $target_file = $constancias_dir . $new_filename;

        // Validar tipo de archivo
        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png'];
        $file_type = strtolower($file_extension);

        if (in_array($file_type, $allowed_types)) {
            if (move_uploaded_file($_FILES['constancia_fiscal']['tmp_name'], $target_file)) {
                $constancia_fiscal = $new_filename;
                $fecha_subida_constancia = date('Y-m-d H:i:s');
                $mensaje .= " Constancia fiscal subida correctamente.";
            } else {
                $mensaje .= " Error al subir la constancia fiscal.";
                $tipo_mensaje = "warning";
            }
        } else {
            $mensaje .= " Formato de archivo no permitido para constancia fiscal.";
            $tipo_mensaje = "warning";
        }
    }

    if ($_FILES['credencial_identificacion']['error'] == 0) {
        $original_name = basename($_FILES['credencial_identificacion']['name']);
        $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $new_filename = uniqid() . '_' . date('Ymd_His') . '.' . $file_extension;

        $target_file = $credenciales_dir . $new_filename;

        // Validar tipo de archivo
        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png'];
        $file_type = strtolower($file_extension);

        if (in_array($file_type, $allowed_types)) {
            if (move_uploaded_file($_FILES['credencial_identificacion']['tmp_name'], $target_file)) {
                $credencial_identificacion = $new_filename;
                $fecha_subida_credencial = date('Y-m-d H:i:s');
                $mensaje .= " Credencial de identificación subida correctamente.";
            } else {
                $mensaje .= " Error al subir la credencial de identificación.";
                $tipo_mensaje = "warning";
            }
        } else {
            $mensaje .= " Formato de archivo no permitido para credencial de identificación.";
            $tipo_mensaje = "warning";
        }
    }

    try {
        if ($id_empresa > 0) {
            // Determinar si se está cambiando a premium por primera vez
            $cambiando_a_premium = false;
            if ($plan === 'premium' && (!isset($empresa['plan']) || $empresa['plan'] !== 'premium')) {
                $cambiando_a_premium = true;
            }

            // Actualizar empresa existente
            $sql = "UPDATE empresas SET 
                    nombre_empresa = ?,
                    giro_comercial = ?,
                    rfc = ?,
                    telefono = ?,
                    email = ?,
                    direccion = ?,
                    nombre_contacto = ?,
                    constancia_fiscal = ?,
                    credencial_identificacion = ?,
                    fecha_subida_constancia = COALESCE(?, fecha_subida_constancia),
                    fecha_subida_credencial = COALESCE(?, fecha_subida_credencial),
                    estado_verificacion = ?,
                    observaciones_verificacion = ?,
                    correo_enviado = ?,
                    fecha_envio_correo = " . ($correo_enviado ? "NOW()" : "NULL") . ",
                    fecha_actualizacion = NOW(),
                    activo = ?,
                    plan = ?,
                    timbres_disponibles = ?,
                    timbres_totales = ?,
                    fecha_activacion_timbres = ?
                    WHERE id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "sssssssssssssiisissi",
                $nombre_empresa,
                $giro_comercial,
                $rfc,
                $telefono,
                $email,
                $direccion,
                $nombre_contacto,
                $constancia_fiscal,
                $credencial_identificacion,
                $fecha_subida_constancia,
                $fecha_subida_credencial,
                $estado_verificacion,
                $observaciones_verificacion,
                $correo_enviado,
                $activo,
                $plan,
                $timbres_disponibles,
                $timbres_totales,
                $fecha_activacion_timbres,
                $id_empresa
            );

            if ($stmt->execute()) {
                // =============================================
                // CREAR ORGANIZACIÓN EN FACTURAPI CON LÓGICA DE TIMBRES
                // =============================================
                if ($plan === 'premium' && $timbres_totales > 0 && empty($empresa['facturapi_organization_id'])) {
                    try {
                        require_once dirname(__FILE__) . '/../vendor/autoload.php';

                        $facturapi = new \Facturapi\Facturapi($FACTURAPI_SECRET_KEY);

                        $datos_organizacion = [
                            'name' => $nombre_empresa
                        ];

                        error_log("=== CREANDO ORGANIZACIÓN EN FACTURAPI ===");
                        error_log("Plan: " . $plan);
                        error_log("Timbres Totales: " . $timbres_totales);
                        error_log("Nombre: " . $nombre_empresa);
                        error_log("Empresa ID: " . $id_empresa);
                        error_log("=========================================");

                        $organizacion = $facturapi->Organizations->create($datos_organizacion);

                        $facturapi_id = $organizacion->id;

                        $sql_facturapi = "UPDATE empresas SET facturapi_organization_id = ? WHERE id = ?";
                        $stmt_facturapi = $conn->prepare($sql_facturapi);
                        $stmt_facturapi->bind_param("si", $facturapi_id, $id_empresa);
                        $stmt_facturapi->execute();
                        $stmt_facturapi->close();

                        $mensaje .= " Organización creada exitosamente en Facturapi (ID: " . $facturapi_id . ").";
                        $tipo_mensaje = "success";

                        error_log("✓ Organización creada en Facturapi. ID: " . $facturapi_id);
                    } catch (Exception $e) {
                        $error_msg = $e->getMessage();
                        error_log("✗ Error al crear organización en Facturapi: " . $error_msg);
                        $mensaje .= " Nota: La empresa se guardó, pero hubo un error al crear la organización en Facturapi: " . htmlspecialchars($error_msg);
                        if (empty($tipo_mensaje) || $tipo_mensaje != 'danger') {
                            $tipo_mensaje = "warning";
                        }
                    }
                }
                // =============================================

                if (empty($tipo_mensaje) || $tipo_mensaje == 'success') {
                    $mensaje = "Empresa actualizada correctamente" . $mensaje;
                    $tipo_mensaje = "success";
                }

                // Recargar datos actualizados
                $sql = "SELECT e.*, g.nombre as nombre_giro_comercial 
                        FROM empresas e 
                        LEFT JOIN giro_comercial g ON e.giro_comercial = g.id 
                        WHERE e.id = ?";
                $stmt_reload = $conn->prepare($sql);
                $stmt_reload->bind_param("i", $id_empresa);
                $stmt_reload->execute();
                $result = $stmt_reload->get_result();
                $empresa = $result->fetch_assoc();
                $stmt_reload->close();
            } else {
                $mensaje = "Error al actualizar la empresa: " . $stmt->error;
                $tipo_mensaje = "danger";
            }
        } else {
            // Insertar nueva empresa
            $sql = "INSERT INTO empresas (
                    nombre_empresa,
                    giro_comercial,
                    rfc,
                    telefono,
                    email,
                    direccion,
                    nombre_contacto,
                    constancia_fiscal,
                    credencial_identificacion,
                    fecha_subida_constancia,
                    fecha_subida_credencial,
                    estado_verificacion,
                    observaciones_verificacion,
                    correo_enviado,
                    fecha_creacion,
                    activo,
                    plan,
                    timbres_disponibles,
                    timbres_totales,
                    fecha_activacion_timbres
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "sssssssssssssisiiis",
                $nombre_empresa,
                $giro_comercial,
                $rfc,
                $telefono,
                $email,
                $direccion,
                $nombre_contacto,
                $constancia_fiscal,
                $credencial_identificacion,
                $fecha_subida_constancia,
                $fecha_subida_credencial,
                $estado_verificacion,
                $observaciones_verificacion,
                $correo_enviado,
                $activo,
                $plan,
                $timbres_disponibles,
                $timbres_totales,
                $fecha_activacion_timbres
            );

            if ($stmt->execute()) {
                $id_empresa = $stmt->insert_id;

                // =============================================
                // CREAR ORGANIZACIÓN EN FACTURAPI CON LÓGICA DE TIMBRES (PARA NUEVA EMPRESA)
                // =============================================
                if ($plan === 'premium' && $timbres_totales > 0) {
                    try {
                        require_once dirname(__FILE__) . '/../vendor/autoload.php';

                        $facturapi = new \Facturapi\Facturapi($FACTURAPI_SECRET_KEY);

                        $datos_organizacion = [
                            'name' => $nombre_empresa
                        ];

                        error_log("=== CREANDO ORGANIZACIÓN EN FACTURAPI (NUEVA EMPRESA) ===");
                        error_log("Plan: " . $plan);
                        error_log("Timbres Totales: " . $timbres_totales);
                        error_log("Nombre: " . $nombre_empresa);
                        error_log("Nueva Empresa ID: " . $id_empresa);
                        error_log("=======================================================");

                        $organizacion = $facturapi->Organizations->create($datos_organizacion);

                        $facturapi_id = $organizacion->id;

                        $sql_facturapi = "UPDATE empresas SET facturapi_organization_id = ? WHERE id = ?";
                        $stmt_facturapi = $conn->prepare($sql_facturapi);
                        $stmt_facturapi->bind_param("si", $facturapi_id, $id_empresa);
                        $stmt_facturapi->execute();
                        $stmt_facturapi->close();

                        $mensaje .= " Organización creada exitosamente en Facturapi (ID: " . $facturapi_id . ").";
                        $tipo_mensaje = "success";

                        error_log("✓ Organización creada en Facturapi para nueva empresa. ID: " . $facturapi_id);
                    } catch (Exception $e) {
                        $error_msg = $e->getMessage();
                        error_log("✗ Error al crear organización en Facturapi (nueva empresa): " . $error_msg);
                        $mensaje .= " Nota: La empresa se creó, pero hubo un error al crear la organización en Facturapi: " . htmlspecialchars($error_msg);
                        $tipo_mensaje = "warning";
                    }
                }
                // =============================================

                if (empty($tipo_mensaje) || $tipo_mensaje != 'danger') {
                    $mensaje = "Empresa creada correctamente" . $mensaje;
                    $tipo_mensaje = "success";
                }

                // Recargar datos
                $sql = "SELECT e.*, g.nombre as nombre_giro_comercial 
                        FROM empresas e 
                        LEFT JOIN giro_comercial g ON e.giro_comercial = g.id 
                        WHERE e.id = ?";
                $stmt_reload = $conn->prepare($sql);
                $stmt_reload->bind_param("i", $id_empresa);
                $stmt_reload->execute();
                $result = $stmt_reload->get_result();
                $empresa = $result->fetch_assoc();
                $stmt_reload->close();
            } else {
                $mensaje = "Error al crear la empresa: " . $stmt->error;
                $tipo_mensaje = "danger";
            }
        }

        if ($stmt) {
            $stmt->close();
        }
    } catch (Exception $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

$conn->close();

// Función para obtener la clase del estado
function claseEstado($estado)
{
    switch ($estado) {
        case 'pendiente':
            return 'warning';
        case 'en_revision':
            return 'info';
        case 'aprobado':
            return 'success';
        case 'rechazado':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Función para obtener el texto del estado
function textoEstado($estado)
{
    $estados = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Revisión',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado'
    ];
    return $estados[$estado] ?? $estado;
}

// Función para obtener el texto del plan
function textoPlan($plan)
{
    $planes = [
        'prueba' => 'Prueba',
        'basico' => 'Básico',
        'emprendedor' => 'Emprendedor',
        'premium' => 'Premium'
    ];
    return $planes[$plan] ?? $plan;
}

// Función para formatear fecha
function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

// Función para verificar si existe un archivo
function archivoExiste($tipo, $nombre_archivo)
{
    if (empty($nombre_archivo)) {
        return false;
    }

    $base_path = dirname(__FILE__) . '/../uploads/';

    if ($tipo == 'constancia') {
        $file_path = $base_path . 'constancias/' . $nombre_archivo;
    } else if ($tipo == 'credencial') {
        $file_path = $base_path . 'credenciales/' . $nombre_archivo;
    } else {
        return false;
    }

    return file_exists($file_path);
}

// Función para mostrar estadísticas de uso de timbres
function mostrarEstadisticasTimbres($empresa)
{
    if (!isset($empresa['plan']) || $empresa['plan'] === 'prueba') {
        return '';
    }

    $timbres_totales = $empresa['timbres_totales'] ?? 0;
    $timbres_disponibles = $empresa['timbres_disponibles'] ?? 0;
    $facturas_emitidas = $empresa['facturas_emitidas'] ?? ($timbres_totales - $timbres_disponibles);

    // Calcular porcentaje de uso
    $porcentaje_usado = $timbres_totales > 0 ? round(($facturas_emitidas / $timbres_totales) * 100, 1) : 0;

    // Determinar color según uso
    if ($porcentaje_usado >= 90) {
        $color_clase = 'danger';
        $icono = 'exclamation-triangle';
    } elseif ($porcentaje_usado >= 70) {
        $color_clase = 'warning';
        $icono = 'exclamation-circle';
    } else {
        $color_clase = 'success';
        $icono = 'check-circle';
    }

    return '
        <div class="card border-' . $color_clase . ' mb-3">
            <div class="card-header bg-' . $color_clase . ' bg-opacity-10 py-2">
                <h6 class="mb-0 text-' . $color_clase . '">
                    <i class="fas fa-chart-pie me-2"></i>Estadísticas de Uso
                </h6>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <h2 class="display-6 text-primary mb-1">' . $facturas_emitidas . '</h2>
                            <small class="text-muted">CFDI Emitidos</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <h2 class="display-6 text-' . $color_clase . ' mb-1">' . $porcentaje_usado . '%</h2>
                            <small class="text-muted">Uso Total</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <h2 class="display-6 text-success mb-1">' . $timbres_disponibles . '</h2>
                            <small class="text-muted">Disponibles</small>
                        </div>
                    </div>
                </div>
                
                <!-- Barra de progreso -->
                <div class="progress" style="height: 20px;">
                    <div class="progress-bar bg-' . $color_clase . '" 
                         role="progressbar" 
                         style="width: ' . $porcentaje_usado . '%"
                         aria-valuenow="' . $porcentaje_usado . '" 
                         aria-valuemin="0" 
                         aria-valuemax="100">
                        ' . $porcentaje_usado . '% usado
                    </div>
                </div>
                
                <div class="row mt-2">
                    <div class="col-6">
                        <small class="text-muted">
                            <i class="fas fa-file-invoice me-1"></i>
                            ' . $facturas_emitidas . ' de ' . $timbres_totales . ' timbres usados
                        </small>
                    </div>
                    <div class="col-6 text-end">
                        <small class="text-muted">
                            <i class="fas fa-sync-alt me-1"></i>
                            Actualizado automáticamente desde Facturapi
                        </small>
                    </div>
                </div>
            </div>
        </div>
    ';
}

// Depuración: verificar qué datos tenemos
if ($id_empresa > 0 && $empresa) {
    error_log("=== ANTES DE MOSTRAR HTML ===");
    error_log("ID: " . $empresa['id']);
    error_log("Nombre: " . $empresa['nombre_empresa']);
    error_log("Giro Comercial ID: " . $empresa['giro_comercial']);
    error_log("Giro Comercial Nombre: " . ($empresa['nombre_giro_comercial'] ?? 'NULL'));
    error_log("RFC: " . $empresa['rfc']);
    error_log("Plan: " . ($empresa['plan'] ?? 'No especificado'));
    error_log("Facturapi ID: " . ($empresa['facturapi_organization_id'] ?? 'No creado'));
    error_log("Facturas Emitidas: " . ($empresa['facturas_emitidas'] ?? '0'));
    error_log("Teléfono: " . $empresa['telefono']);
    error_log("Email: " . $empresa['email']);
    error_log("Timbres Disponibles: " . $empresa['timbres_disponibles']);
    error_log("Timbres Totales: " . $empresa['timbres_totales']);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $id_empresa > 0 ? 'Editar Empresa' : 'Nueva Empresa'; ?> - Panel de Administración</title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
            --primary-gradient: linear-gradient(135deg, #27ae60, #2ecc71);
            --secondary-gradient: linear-gradient(135deg, #3498db, #2980b9);
            --success-gradient: linear-gradient(135deg, #2ecc71, #27ae60);
            --warning-gradient: linear-gradient(135deg, #f39c12, #e67e22);
            --danger-gradient: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
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
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header.bg-gradient-primary {
            background: var(--primary-gradient) !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(39, 174, 96, 0.25);
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
            background-color: #28a745;
            border-color: #28a745;
        }

        .btn-success:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }

        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
        }

        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #d39e00;
        }

        .badge-estado {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .file-info {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
        }

        .section-title {
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 10px;
            margin-bottom: 20px;
            color: #2c3e50;
        }

        .file-input-group {
            display: flex;
            gap: 10px;
        }

        .file-input-group .form-control {
            flex: 1;
        }

        .btn-upload {
            white-space: nowrap;
        }

        .document-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .document-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .document-actions {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }

        .file-preview {
            max-width: 100%;
            margin-top: 10px;
        }

        .file-preview img {
            max-width: 200px;
            max-height: 150px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }

        /* ========================================= */
        /* ESTILOS CORREGIDOS PARA EL VISOR DE ARCHIVOS */
        /* ========================================= */

        #modalArchivo .modal-body {
            min-height: 500px;
            padding: 0 !important;
            position: relative;
        }

        #modalArchivo .modal-dialog {
            max-width: 90%;
            height: 90vh;
        }

        #modalArchivo .modal-content {
            height: 90vh;
        }

        #archivoCargando {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.9);
            z-index: 10;
        }

        #visorImagen {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            padding: 20px;
        }

        #visorImagen img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        #visorPDF {
            width: 100%;
            height: 100%;
            background-color: #f8f9fa;
        }

        #visorPDF iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        #visorError {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            padding: 20px;
        }

        /* ========================================= */
        /* ESTILOS MEJORADOS PARA EL NUEVO DISEÑO */
        /* ========================================= */

        .form-floating>.form-control:focus,
        .form-floating>.form-control:not(:placeholder-shown) {
            border-color: #27ae60;
            box-shadow: 0 0 0 0.25rem rgba(39, 174, 96, 0.15);
        }

        .form-floating>label {
            color: #6c757d;
            padding: 1rem 0.75rem;
        }

        .section-header {
            border-bottom: 3px solid;
            border-image: linear-gradient(90deg, currentColor, transparent) 1;
            padding-bottom: 10px;
        }

        .icon-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
        }

        .file-drop-zone {
            transition: all 0.3s ease;
            cursor: pointer;
            border-color: #dee2e6;
            border-width: 2px;
            border-style: dashed;
        }

        .file-drop-zone:hover {
            border-color: #27ae60;
            background-color: rgba(39, 174, 96, 0.05);
            transform: translateY(-2px);
        }

        .file-drop-zone.dragover {
            border-color: #27ae60;
            background-color: rgba(39, 174, 96, 0.1);
            border-style: solid;
        }

        .document-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }

        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .file-info-card {
            transition: all 0.3s ease;
        }

        .file-info-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .status-display {
            transition: all 0.3s ease;
        }

        .status-display:hover {
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .action-footer {
            transition: all 0.3s ease;
        }

        .badge {
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        .btn-outline-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.2);
        }

        .btn-outline-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-outline-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
        }

        /* Animaciones */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-body {
            animation: fadeIn 0.5s ease-out;
        }

        /* Scroll personalizado */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #219653, #27ae60);
        }

        /* Estilos para campos requeridos */
        .form-floating>.form-control:required+label::after {
            content: " *";
            color: #dc3545;
        }

        /* Estilo para información de Facturapi */
        .facturapi-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
        }

        .facturapi-info i {
            font-size: 1.2rem;
            margin-right: 10px;
        }

        /* Estilos para información de Timbres */
        .timbres-info {
            background: linear-gradient(135deg, #36d1dc 0%, #5b86e5 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
        }

        .timbres-info i {
            font-size: 1.2rem;
            margin-right: 10px;
        }

        /* Responsive adjustments */
        @media (max-width: 767.98px) {
            .sidebar {
                display: none;
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
            }

            .file-input-group {
                flex-direction: column;
            }

            .btn-group {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .btn-group>.btn {
                width: 100%;
            }

            .document-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            #modalArchivo .modal-dialog {
                max-width: 95%;
                height: 80vh;
                margin: 10px auto;
            }

            #modalArchivo .modal-content {
                height: 80vh;
            }

            .card-header {
                text-align: center;
            }

            .section-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .action-footer .d-flex {
                flex-direction: column;
                gap: 15px;
            }

            .file-drop-zone {
                padding: 2rem 1rem;
            }
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../images/LibertyfinBlanco.png" alt="Logo" class="me-2" style="height: 30px;">
                <span>Panel de Administración</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar d-none d-md-block">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="empresas.php">
                                <i class="fas fa-building"></i>
                                Empresas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="usuarios.php">
                                <i class="fas fa-user-cog"></i>
                                Usuarios Admin
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="configuracion.php">
                                <i class="fas fa-cogs"></i>
                                Configuración
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Mensajes -->
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-<?php echo $tipo_mensaje == 'success' ? 'check-circle' : ($tipo_mensaje == 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?> me-2"></i>
                            <div><?php echo $mensaje; ?></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Encabezado -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="h4 mb-1">
                            <i class="fas fa-building me-2"></i>
                            <?php echo $id_empresa > 0 ? 'Editar Empresa' : 'Nueva Empresa'; ?>
                        </h2>
                        <p class="text-muted mb-0">
                            <small>
                                <?php echo $id_empresa > 0 ? 'Modifica la información de la empresa registrada' : 'Completa el formulario para registrar una nueva empresa'; ?>
                            </small>
                        </p>
                    </div>
                    <div>
                        <a href="empresas.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Volver al Listado
                        </a>
                    </div>
                </div>

                <!-- Formulario de Empresa - Diseño Mejorado -->
                <div class="card border-0 shadow-lg">
                    <div class="card-header bg-gradient-primary text-white py-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-white p-2 rounded-circle me-3">
                                <i class="fas fa-building text-primary fa-lg"></i>
                            </div>
                            <div>
                                <h5 class="card-title mb-0 fw-bold">
                                    Información de la Empresa
                                </h5>
                                <p class="card-subtitle mb-0 text-white-50 small">
                                    <?php echo $id_empresa > 0 ? 'Editar empresa existente' : 'Registrar nueva empresa'; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <form method="POST" enctype="multipart/form-data" id="empresaForm" class="needs-validation" novalidate>
                            <!-- Información Básica -->
                            <div class="row g-4 mb-5">
                                <div class="col-12">
                                    <div class="section-header d-flex align-items-center mb-4">
                                        <div class="icon-wrapper bg-primary bg-opacity-10 p-3 rounded-circle me-3">
                                            <i class="fas fa-info-circle text-primary fa-lg"></i>
                                        </div>
                                        <h5 class="mb-0 text-primary fw-bold">Información Básica</h5>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="nombre_empresa" name="nombre_empresa"
                                            placeholder="Nombre de la Empresa" required
                                            value="<?php echo isset($empresa['nombre_empresa']) ? htmlspecialchars($empresa['nombre_empresa']) : ''; ?>">
                                        <label for="nombre_empresa">Nombre de la Empresa *</label>
                                        <div class="invalid-feedback">
                                            Por favor ingrese el nombre de la empresa.
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="giro_comercial" name="giro_comercial" required>
                                            <option value="">Seleccionar...</option>
                                            <?php foreach ($giros_comerciales as $id => $nombre): ?>
                                                <option value="<?php echo $id; ?>"
                                                    <?php echo (isset($empresa['giro_comercial']) && $empresa['giro_comercial'] == $id) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($nombre); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label for="giro_comercial">Giro Comercial *</label>
                                        <div class="invalid-feedback">
                                            Por favor seleccione un giro comercial.
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="rfc" name="rfc"
                                            placeholder="RFC"
                                            value="<?php echo isset($empresa['rfc']) ? htmlspecialchars($empresa['rfc']) : ''; ?>">
                                        <label for="rfc">RFC</label>
                                        <div class="form-text small text-muted mt-1">
                                            <i class="fas fa-info-circle me-1"></i>Campo opcional
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="plan" name="plan" required>
                                            <option value="">Seleccionar Plan...</option>
                                            <option value="prueba" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'prueba') ? 'selected' : ''; ?>>Prueba</option>
                                            <option value="basico" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'basico') ? 'selected' : ''; ?>>Básico</option>
                                            <option value="emprendedor" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'emprendedor') ? 'selected' : ''; ?>>Emprendedor</option>
                                            <option value="premium" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'premium') ? 'selected' : ''; ?>>Premium</option>
                                        </select>
                                        <label for="plan">Plan *</label>
                                        <div class="invalid-feedback">
                                            Por favor seleccione un plan.
                                        </div>
                                        <div class="form-text small text-muted mt-1">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <?php if ($id_empresa > 0 && $empresa['plan'] !== 'prueba'): ?>
                                                <strong>Nota:</strong> La organización en Facturapi se creará automáticamente al activar un paquete de timbres.
                                            <?php else: ?>
                                                Al seleccionar plan "Premium" se asignarán 500 timbres automáticamente.
                                                Para otros planes, deberá activar un paquete de timbres manualmente.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="tel" class="form-control" id="telefono" name="telefono"
                                            placeholder="Teléfono" required
                                            pattern="[0-9+\-\s]{10,15}"
                                            value="<?php echo isset($empresa['telefono']) ? htmlspecialchars($empresa['telefono']) : ''; ?>">
                                        <label for="telefono">Teléfono *</label>
                                        <div class="invalid-feedback">
                                            Por favor ingrese un número de teléfono válido.
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="email" class="form-control" id="email" name="email"
                                            placeholder="Correo Electrónico" required
                                            value="<?php echo isset($empresa['email']) ? htmlspecialchars($empresa['email']) : ''; ?>">
                                        <label for="email">Email *</label>
                                        <div class="invalid-feedback">
                                            Por favor ingrese un correo electrónico válido.
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="nombre_contacto" name="nombre_contacto"
                                            placeholder="Nombre del Contacto" required
                                            value="<?php echo isset($empresa['nombre_contacto']) ? htmlspecialchars($empresa['nombre_contacto']) : ''; ?>">
                                        <label for="nombre_contacto">Nombre del Contacto *</label>
                                        <div class="invalid-feedback">
                                            Por favor ingrese el nombre del contacto.
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="form-floating">
                                        <textarea class="form-control" id="direccion" name="direccion"
                                            placeholder="Dirección" style="height: 100px"><?php echo isset($empresa['direccion']) ? htmlspecialchars($empresa['direccion']) : ''; ?></textarea>
                                        <label for="direccion">Dirección</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Información de Timbres -->
                            <div class="row g-4 mb-4">
                                <div class="col-12">
                                    <div class="section-header d-flex align-items-center mb-3">
                                        <div class="icon-wrapper bg-info bg-opacity-10 p-3 rounded-circle me-3">
                                            <i class="fas fa-file-invoice text-info fa-lg"></i>
                                        </div>
                                        <h5 class="mb-0 text-info fw-bold">Control de Timbres CFDI</h5>
                                    </div>

                                    <!-- Mostrar estadísticas si la empresa tiene timbres -->
                                    <?php
                                    if ($id_empresa > 0 && isset($empresa['plan']) && $empresa['plan'] !== 'prueba' && $empresa['timbres_totales'] > 0) {
                                        echo mostrarEstadisticasTimbres($empresa);
                                    }
                                    ?>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="card border-info">
                                                <div class="card-header bg-info bg-opacity-10 py-2">
                                                    <h6 class="mb-0 text-info"><i class="fas fa-calculator me-2"></i>Timbres Disponibles</h6>
                                                </div>
                                                <div class="card-body text-center py-3">
                                                    <h2 class="display-5 text-info mb-0">
                                                        <?php echo isset($empresa['timbres_disponibles']) ? $empresa['timbres_disponibles'] : '0'; ?>
                                                    </h2>
                                                    <small class="text-muted">CFDI disponibles</small>
                                                    <?php if (isset($empresa['facturas_emitidas'])): ?>
                                                        <div class="mt-2">
                                                            <small class="text-muted">
                                                                <i class="fas fa-file-invoice me-1"></i>
                                                                <?php echo $empresa['facturas_emitidas']; ?> facturas emitidas
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="card border-primary">
                                                <div class="card-header bg-primary bg-opacity-10 py-2">
                                                    <h6 class="mb-0 text-primary"><i class="fas fa-chart-bar me-2"></i>Timbres Totales</h6>
                                                </div>
                                                <div class="card-body text-center py-3">
                                                    <h2 class="display-5 text-primary mb-0">
                                                        <?php echo isset($empresa['timbres_totales']) ? $empresa['timbres_totales'] : '0'; ?>
                                                    </h2>
                                                    <small class="text-muted">CFDI asignados</small>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="card border-success">
                                                <div class="card-header bg-success bg-opacity-10 py-2">
                                                    <h6 class="mb-0 text-success"><i class="fas fa-calendar-alt me-2"></i>Información</h6>
                                                </div>
                                                <div class="card-body py-3">
                                                    <div class="text-center">
                                                        <?php if (!empty($empresa['fecha_activacion_timbres'])): ?>
                                                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                                            <p class="mb-1">
                                                                <strong>Activado:</strong><br>
                                                                <?php echo formatearFecha($empresa['fecha_activacion_timbres']); ?>
                                                            </p>
                                                            <small class="text-muted">Sin fecha de vencimiento</small>
                                                        <?php else: ?>
                                                            <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                                                            <p class="mb-1">Timbres no activados</p>
                                                            <small class="text-muted">Activar con paquete de timbres</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Botones de acción para timbres -->
                                    <div class="text-center mt-4">
                                        <?php if ($id_empresa > 0 && $empresa['plan'] !== 'prueba'): ?>
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTimbres">
                                                <i class="fas fa-plus-circle me-2"></i>Administrar Paquete de Timbres
                                            </button>

                                            <button type="button" class="btn btn-outline-info" onclick="actualizarTimbresManual()">
                                                <i class="fas fa-sync-alt me-2"></i>Sincronizar con Facturapi
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($id_empresa > 0 && isset($empresa['plan']) && $empresa['plan'] !== 'prueba'): ?>
                                        <?php if ($empresa['timbres_disponibles'] == 0): ?>
                                            <div class="alert alert-warning mt-3">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <strong>Atención:</strong> Esta empresa ha agotado sus timbres.
                                                Active un nuevo paquete para continuar usando el servicio.
                                            </div>
                                        <?php elseif (($empresa['timbres_disponibles'] / $empresa['timbres_totales']) < 0.2): ?>
                                            <div class="alert alert-info mt-3">
                                                <i class="fas fa-info-circle me-2"></i>
                                                <strong>Nota:</strong> Quedan pocos timbres disponibles.
                                                Considere activar un nuevo paquete pronto.
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Documentación -->
                            <div class="row g-4 mb-5">
                                <div class="col-12">
                                    <div class="section-header d-flex align-items-center mb-4">
                                        <div class="icon-wrapper bg-warning bg-opacity-10 p-3 rounded-circle me-3">
                                            <i class="fas fa-file-alt text-warning fa-lg"></i>
                                        </div>
                                        <h5 class="mb-0 text-warning fw-bold">Documentación Requerida</h5>
                                    </div>
                                </div>

                                <!-- Constancia Fiscal -->
                                <div class="col-md-6">
                                    <div class="document-card card border h-100">
                                        <div class="card-header bg-light py-3">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center">
                                                    <div class="icon-wrapper bg-primary bg-opacity-10 p-2 rounded-circle me-3">
                                                        <i class="fas fa-file-invoice text-primary"></i>
                                                    </div>
                                                    <h6 class="mb-0 fw-bold">Constancia Fiscal</h6>
                                                </div>
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary">
                                                    <i class="fas fa-file-pdf me-1"></i>PDF, JPG, PNG
                                                </span>
                                            </div>
                                        </div>

                                        <div class="card-body">
                                            <div class="file-upload-area mb-4">
                                                <label class="form-label fw-bold mb-3">Subir Archivo</label>
                                                <div class="file-drop-zone border-2 border-dashed rounded-3 p-4 text-center bg-light"
                                                    id="constanciaDropZone">
                                                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-3"></i>
                                                    <p class="mb-2">Arrastra y suelta el archivo aquí</p>
                                                    <p class="text-muted small mb-3">o haz clic para seleccionar</p>
                                                    <input type="file" class="d-none" name="constancia_fiscal"
                                                        id="constancia_fiscal" accept=".pdf,.jpg,.jpeg,.png">
                                                    <button type="button" class="btn btn-primary"
                                                        onclick="document.getElementById('constancia_fiscal').click()">
                                                        <i class="fas fa-upload me-1"></i>Seleccionar Archivo
                                                    </button>
                                                    <div class="mt-2">
                                                        <small class="text-muted">
                                                            <i class="fas fa-info-circle me-1"></i>
                                                            Máx. 5MB • PDF, JPG, PNG
                                                        </small>
                                                    </div>
                                                </div>
                                                <div id="constancia_preview" class="mt-3"></div>
                                            </div>

                                            <?php if (!empty($empresa['constancia_fiscal'])): ?>
                                                <div class="current-file-section border-top pt-3">
                                                    <h6 class="fw-bold mb-3">Archivo Actual</h6>
                                                    <div class="file-info-card bg-light rounded-3 p-3">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <div>
                                                                <i class="fas fa-file me-2 text-primary"></i>
                                                                <span class="fw-medium"><?php echo htmlspecialchars($empresa['constancia_fiscal']); ?></span>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?php echo formatearFecha($empresa['fecha_subida_constancia']); ?>
                                                            </small>
                                                        </div>

                                                        <?php if (archivoExiste('constancia', $empresa['constancia_fiscal'])): ?>
                                                            <?php
                                                            $file_extension = strtolower(pathinfo($empresa['constancia_fiscal'], PATHINFO_EXTENSION));
                                                            $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif']);
                                                            $is_pdf = $file_extension == 'pdf';
                                                            $file_path = '../uploads/constancias/' . htmlspecialchars($empresa['constancia_fiscal']);
                                                            ?>

                                                            <div class="btn-group mt-2 w-100">
                                                                <?php if ($is_image || $is_pdf): ?>
                                                                    <button type="button" class="btn btn-outline-primary btn-sm ver-archivo"
                                                                        data-archivo="<?php echo $file_path; ?>"
                                                                        data-tipo="<?php echo $is_image ? 'imagen' : 'pdf'; ?>"
                                                                        data-nombre="<?php echo htmlspecialchars($empresa['constancia_fiscal']); ?>"
                                                                        data-titulo="Constancia Fiscal">
                                                                        <i class="fas fa-eye me-1"></i>
                                                                        Previsualizar
                                                                    </button>
                                                                <?php endif; ?>
                                                                <a href="<?php echo $file_path; ?>" download
                                                                    class="btn btn-outline-success btn-sm">
                                                                    <i class="fas fa-download me-1"></i>Descargar
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="alert alert-warning mt-2 mb-0 py-2">
                                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                                Archivo no encontrado en el servidor
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Credencial de Identificación -->
                                <div class="col-md-6">
                                    <div class="document-card card border h-100">
                                        <div class="card-header bg-light py-3">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center">
                                                    <div class="icon-wrapper bg-info bg-opacity-10 p-2 rounded-circle me-3">
                                                        <i class="fas fa-id-card text-info"></i>
                                                    </div>
                                                    <h6 class="mb-0 fw-bold">Credencial de Identificación</h6>
                                                </div>
                                                <span class="badge bg-info bg-opacity-10 text-info border border-info">
                                                    <i class="fas fa-file-image me-1"></i>PDF, JPG, PNG
                                                </span>
                                            </div>
                                        </div>

                                        <div class="card-body">
                                            <div class="file-upload-area mb-4">
                                                <label class="form-label fw-bold mb-3">Subir Archivo</label>
                                                <div class="file-drop-zone border-2 border-dashed rounded-3 p-4 text-center bg-light"
                                                    id="credencialDropZone">
                                                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-3"></i>
                                                    <p class="mb-2">Arrastra y suelta el archivo aquí</p>
                                                    <p class="text-muted small mb-3">o haz clic para seleccionar</p>
                                                    <input type="file" class="d-none" name="credencial_identificacion"
                                                        id="credencial_identificacion" accept=".pdf,.jpg,.jpeg,.png">
                                                    <button type="button" class="btn btn-info"
                                                        onclick="document.getElementById('credencial_identificacion').click()">
                                                        <i class="fas fa-upload me-1"></i>Seleccionar Archivo
                                                    </button>
                                                    <div class="mt-2">
                                                        <small class="text-muted">
                                                            <i class="fas fa-info-circle me-1"></i>
                                                            Máx. 5MB • PDF, JPG, PNG
                                                        </small>
                                                    </div>
                                                </div>
                                                <div id="credencial_preview" class="mt-3"></div>
                                            </div>

                                            <?php if (!empty($empresa['credencial_identificacion'])): ?>
                                                <div class="current-file-section border-top pt-3">
                                                    <h6 class="fw-bold mb-3">Archivo Actual</h6>
                                                    <div class="file-info-card bg-light rounded-3 p-3">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <div>
                                                                <i class="fas fa-file me-2 text-info"></i>
                                                                <span class="fw-medium"><?php echo htmlspecialchars($empresa['credencial_identificacion']); ?></span>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?php echo formatearFecha($empresa['fecha_subida_credencial']); ?>
                                                            </small>
                                                        </div>

                                                        <?php if (archivoExiste('credencial', $empresa['credencial_identificacion'])): ?>
                                                            <?php
                                                            $file_extension = strtolower(pathinfo($empresa['credencial_identificacion'], PATHINFO_EXTENSION));
                                                            $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif']);
                                                            $is_pdf = $file_extension == 'pdf';
                                                            $file_path = '../uploads/credenciales/' . htmlspecialchars($empresa['credencial_identificacion']);
                                                            ?>

                                                            <div class="btn-group mt-2 w-100">
                                                                <?php if ($is_image || $is_pdf): ?>
                                                                    <button type="button" class="btn btn-outline-info btn-sm ver-archivo"
                                                                        data-archivo="<?php echo $file_path; ?>"
                                                                        data-tipo="<?php echo $is_image ? 'imagen' : 'pdf'; ?>"
                                                                        data-nombre="<?php echo htmlspecialchars($empresa['credencial_identificacion']); ?>"
                                                                        data-titulo="Credencial de Identificación">
                                                                        <i class="fas fa-eye me-1"></i>
                                                                        Previsualizar
                                                                    </button>
                                                                <?php endif; ?>
                                                                <a href="<?php echo $file_path; ?>" download
                                                                    class="btn btn-outline-success btn-sm">
                                                                    <i class="fas fa-download me-1"></i>Descargar
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="alert alert-warning mt-2 mb-0 py-2">
                                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                                Archivo no encontrado en el servidor
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Verificación y Estado -->
                            <div class="row g-4 mb-5">
                                <div class="col-12">
                                    <div class="section-header d-flex align-items-center mb-4">
                                        <div class="icon-wrapper bg-success bg-opacity-10 p-3 rounded-circle me-3">
                                            <i class="fas fa-check-circle text-success fa-lg"></i>
                                        </div>
                                        <h5 class="mb-0 text-success fw-bold">Verificación y Estado</h5>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="estado_verificacion" name="estado_verificacion" required>
                                            <option value="pendiente" <?php echo (isset($empresa['estado_verificacion']) && $empresa['estado_verificacion'] == 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                                            <option value="en_revision" <?php echo (isset($empresa['estado_verificacion']) && $empresa['estado_verificacion'] == 'en_revision') ? 'selected' : ''; ?>>En Revisión</option>
                                            <option value="aprobado" <?php echo (isset($empresa['estado_verificacion']) && $empresa['estado_verificacion'] == 'aprobado') ? 'selected' : ''; ?>>Aprobado</option>
                                            <option value="rechazado" <?php echo (isset($empresa['estado_verificacion']) && $empresa['estado_verificacion'] == 'rechazado') ? 'selected' : ''; ?>>Rechazado</option>
                                        </select>
                                        <label for="estado_verificacion">Estado de Verificación *</label>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="status-display p-3 rounded-3 bg-light">
                                        <label class="form-label fw-bold mb-2">Estado Actual</label>
                                        <?php if (isset($empresa['estado_verificacion'])): ?>
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div>
                                                    <span class="badge bg-<?php echo claseEstado($empresa['estado_verificacion']); ?> px-3 py-2 rounded-pill fw-medium">
                                                        <i class="fas fa-<?php echo $empresa['estado_verificacion'] == 'aprobado' ? 'check' : ($empresa['estado_verificacion'] == 'rechazado' ? 'times' : 'clock'); ?> me-1"></i>
                                                        <?php echo textoEstado($empresa['estado_verificacion']); ?>
                                                    </span>

                                                    <!-- AGREGAR INFORMACIÓN DEL PLAN -->
                                                    <?php if (isset($empresa['plan'])): ?>
                                                        <div class="mt-2">
                                                            <span class="badge bg-secondary px-3 py-2 rounded-pill">
                                                                <i class="fas fa-crown me-1"></i>
                                                                Plan: <?php echo textoPlan($empresa['plan']); ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <!-- Información de Timbres si existen -->
                                                    <?php if (isset($empresa['timbres_disponibles']) && $empresa['timbres_disponibles'] > 0): ?>
                                                        <div class="mt-2">
                                                            <span class="badge bg-info px-3 py-2 rounded-pill">
                                                                <i class="fas fa-file-invoice me-1"></i>
                                                                Timbres: <?php echo $empresa['timbres_disponibles']; ?> disponibles
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($empresa['fecha_verificacion'])): ?>
                                                        <small class="text-muted d-block mt-2">
                                                            <i class="fas fa-calendar-alt me-1"></i>
                                                            Verificado: <?php echo formatearFecha($empresa['fecha_verificacion']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (isset($empresa['activo'])): ?>
                                                    <div class="text-end">
                                                        <span class="badge bg-<?php echo $empresa['activo'] ? 'success' : 'danger'; ?> px-3 py-2 rounded-pill">
                                                            <i class="fas fa-<?php echo $empresa['activo'] ? 'check-circle' : 'ban'; ?> me-1"></i>
                                                            <?php echo $empresa['activo'] ? 'Activa' : 'Inactiva'; ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>Sin estado definido
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="form-floating">
                                        <textarea class="form-control" id="observaciones_verificacion"
                                            name="observaciones_verificacion"
                                            placeholder="Observaciones de Verificación"
                                            style="height: 120px"><?php echo isset($empresa['observaciones_verificacion']) ? htmlspecialchars($empresa['observaciones_verificacion']) : ''; ?></textarea>
                                        <label for="observaciones_verificacion">Observaciones de Verificación</label>
                                        <div class="form-text mt-2" id="observaciones_help">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Las observaciones son obligatorias cuando el estado es "Rechazado"
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Botones de acción -->
                            <div class="row mt-5">
                                <div class="col-12">
                                    <div class="action-footer bg-light rounded-3 p-4 border">
                                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                                            <div class="order-md-2 d-flex gap-2">
                                                <a href="empresas.php" class="btn btn-outline-secondary px-4">
                                                    <i class="fas fa-times me-2"></i>Cancelar
                                                </a>
                                                <button type="submit" class="btn btn-primary px-4" id="btnGuardar">
                                                    <i class="fas fa-save me-2"></i>
                                                    <span id="btnGuardarTexto">
                                                        <?php echo $id_empresa > 0 ? 'Guardar Cambios' : 'Crear Empresa'; ?>
                                                    </span>
                                                </button>
                                            </div>

                                            <div class="order-md-1">
                                                <?php if ($id_empresa > 0): ?>
                                                    <?php if ($empresa['activo'] == 1): ?>
                                                        <button type="button" class="btn btn-outline-warning" onclick="confirmarDesactivacion()">
                                                            <i class="fas fa-ban me-2"></i>Desactivar Empresa
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-outline-success" onclick="confirmarActivacion()">
                                                            <i class="fas fa-check me-2"></i>Activar Empresa
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($id_empresa > 0 && isset($empresa['fecha_creacion'])): ?>
                                            <div class="text-center text-muted small mt-3">
                                                <i class="fas fa-clock me-1"></i>
                                                Creado el <?php echo formatearFecha($empresa['fecha_creacion']); ?>
                                                <?php if (!empty($empresa['fecha_actualizacion'])): ?>
                                                    • Última actualización: <?php echo formatearFecha($empresa['fecha_actualizacion']); ?>
                                                <?php endif; ?>
                                                <?php if (!empty($empresa['fecha_actualizacion_timbres'])): ?>
                                                    • Timbres actualizados: <?php echo formatearFecha($empresa['fecha_actualizacion_timbres']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para ver archivos -->
    <div class="modal fade" id="modalArchivo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalArchivoTitulo"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="min-height: 500px;">
                    <div class="d-flex justify-content-center align-items-center h-100" id="archivoCargando">
                        <div class="text-center">
                            <div class="spinner-border text-primary mb-3"></div>
                            <p>Cargando archivo...</p>
                        </div>
                    </div>
                    <div id="visorImagen" class="d-none text-center p-3">
                        <img id="imagenVisor" src="" alt="" class="img-fluid">
                    </div>
                    <div id="visorPDF" class="d-none h-100">
                        <iframe id="pdfVisor" src="" frameborder="0" class="w-100 h-100"></iframe>
                    </div>
                    <div id="visorError" class="d-none text-center p-5">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <h5>No se puede mostrar el archivo</h5>
                        <p class="text-muted">Puede descargarlo para verlo en su dispositivo</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto">
                        <span id="infoArchivo" class="text-muted small"></span>
                    </div>
                    <a href="#" id="descargarArchivo" class="btn btn-primary" download>
                        <i class="fas fa-download me-1"></i>Descargar
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Administrar Timbres -->
    <div class="modal fade" id="modalTimbres" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Administrar Paquete de Timbres
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formTimbres" method="POST" action="administrar_timbres.php">
                        <input type="hidden" name="empresa_id" value="<?php echo $id_empresa; ?>">
                        
                        <input type="hidden" name="accion_timbres" value="sumar">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Empresa:</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($empresa['nombre_empresa']); ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Plan Actual:</label>
                            <input type="text" class="form-control" value="<?php echo textoPlan($empresa['plan']); ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Timbres Disponibles:</label>
                            <input type="text" class="form-control" value="<?php echo $empresa['timbres_disponibles']; ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Timbres Totales:</label>
                            <input type="text" class="form-control" value="<?php echo $empresa['timbres_totales']; ?>" readonly>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Agregar Timbres CFDI *</label>
                            <div class="input-group">
                                <select class="form-select" id="cantidad_timbres" name="cantidad_timbres" required>
                                    <option value="" selected disabled>Seleccionar cantidad...</option>
                                    <option value="50">50 CFDI</option>
                                    <option value="100">100 CFDI</option>
                                    <option value="200">200 CFDI</option>
                                    <option value="300">300 CFDI</option>
                                    <option value="500">500 CFDI</option>
                                </select>
                                <span class="input-group-text">
                                    <i class="fas fa-file-invoice"></i>
                                </span>
                            </div>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                <span id="infoTimbres">
                                    <?php if ($empresa['plan'] == 'premium'): ?>
                                        Para plan Premium: Se sumarán los timbres seleccionados a los existentes
                                    <?php elseif ($empresa['plan'] == 'emprendedor'): ?>
                                        Para plan Emprendedor: Se sumarán los timbres seleccionados a los existentes
                                    <?php elseif ($empresa['plan'] == 'basico'): ?>
                                        Para plan Básico: Se sumarán los timbres seleccionados a los existentes
                                    <?php else: ?>
                                        Plan de prueba no incluye timbres
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-coins me-1"></i>
                                    Paquetes disponibles: 50, 100, 200, 300, 500 CFDI
                                </small>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Nota:</strong> Al agregar timbres:
                            <ul class="mb-0 mt-2">
                                <li>Se sumarán a los timbres disponibles existentes</li>
                                <li>Se registrará la fecha de activación</li>
                                <li>No tiene fecha de vencimiento</li>
                                <?php if (empty($empresa['facturapi_organization_id'])): ?>
                                    <li class="text-warning">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Se creará automáticamente la organización en Facturapi
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formTimbres" class="btn btn-primary" onclick="return confirmarAgregarTimbres()">
                        <i class="fas fa-check me-2"></i>Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // =====================================================================
        // FUNCIONES GLOBALES
        // =====================================================================

        // Función global para abrir archivos en el modal
        window.abrirArchivoModal = function(rutaArchivo, tipoArchivo, nombreArchivo, titulo) {
            console.log('Abriendo archivo:', {
                rutaArchivo,
                tipoArchivo,
                nombreArchivo,
                titulo
            });

            const modalElement = document.getElementById('modalArchivo');
            const modal = new bootstrap.Modal(modalElement);
            const modalTitulo = document.getElementById('modalArchivoTitulo');
            const cargando = document.getElementById('archivoCargando');
            const visorImagen = document.getElementById('visorImagen');
            const imagenVisor = document.getElementById('imagenVisor');
            const visorPDF = document.getElementById('visorPDF');
            const pdfVisor = document.getElementById('pdfVisor');
            const visorError = document.getElementById('visorError');
            const descargarBtn = document.getElementById('descargarArchivo');
            const infoArchivo = document.getElementById('infoArchivo');

            // Configurar modal
            modalTitulo.textContent = titulo;
            descargarBtn.href = rutaArchivo;
            descargarBtn.download = nombreArchivo;
            infoArchivo.textContent = nombreArchivo;

            // Resetear todos los visores
            cargando.classList.remove('d-none');
            visorImagen.classList.add('d-none');
            visorPDF.classList.add('d-none');
            visorError.classList.add('d-none');

            // Mostrar modal primero
            modal.show();

            // Ajustar el modal para que ocupe más espacio
            modalElement.addEventListener('shown.bs.modal', function onShown() {
                modalElement.removeEventListener('shown.bs.modal', onShown);

                // Obtener dimensiones del modal body
                const modalBody = modalElement.querySelector('.modal-body');
                const modalHeader = modalElement.querySelector('.modal-header');
                const modalFooter = modalElement.querySelector('.modal-footer');

                const headerHeight = modalHeader ? modalHeader.offsetHeight : 0;
                const footerHeight = modalFooter ? modalFooter.offsetHeight : 0;
                const windowHeight = window.innerHeight;
                const maxModalHeight = windowHeight * 0.9;
                const modalBodyHeight = maxModalHeight - headerHeight - footerHeight - 40;

                // Configurar visor según tipo de archivo
                if (tipoArchivo === 'imagen') {
                    // Precargar imagen
                    const img = new Image();
                    img.onload = function() {
                        imagenVisor.src = rutaArchivo;
                        cargando.classList.add('d-none');
                        visorImagen.classList.remove('d-none');

                        // Ajustar tamaño de la imagen
                        const maxWidth = modalBody.offsetWidth - 40;
                        const maxHeight = modalBodyHeight - 40;

                        if (this.width > maxWidth || this.height > maxHeight) {
                            const ratio = Math.min(maxWidth / this.width, maxHeight / this.height);
                            imagenVisor.style.width = (this.width * ratio) + 'px';
                            imagenVisor.style.height = (this.height * ratio) + 'px';
                        }

                        // Añadir información de tamaño
                        infoArchivo.textContent = `${nombreArchivo} (${this.width}×${this.height}px)`;
                    };
                    img.onerror = function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    };
                    img.src = rutaArchivo;

                } else if (tipoArchivo === 'pdf') {
                    // Configurar iframe para PDF
                    pdfVisor.src = rutaArchivo + '#view=fitH';
                    pdfVisor.style.height = modalBodyHeight + 'px';

                    // Evento cuando el PDF se carga
                    const onPDFLoad = function() {
                        cargando.classList.add('d-none');
                        visorPDF.classList.remove('d-none');
                    };

                    pdfVisor.onload = onPDFLoad;
                    pdfVisor.onerror = function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    };

                    // Timeout de seguridad
                    setTimeout(function() {
                        if (!cargando.classList.contains('d-none')) {
                            onPDFLoad();
                        }
                    }, 3000);
                } else {
                    // Tipo no reconocido
                    setTimeout(function() {
                        cargando.classList.add('d-none');
                        visorError.classList.remove('d-none');
                    }, 500);
                }

                // Forzar redibujado
                modalBody.style.display = 'none';
                modalBody.offsetHeight;
                modalBody.style.display = 'block';
            });
        };

        // Función para sincronización manual de timbres
        window.actualizarTimbresManual = function() {
            Swal.fire({
                title: 'Sincronizar Timbres',
                text: "¿Desea actualizar los timbres disponibles con la información más reciente de Facturapi?",
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, sincronizar',
                cancelButtonText: 'Cancelar',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch(`sincronizar_timbres.php?id=<?php echo $id_empresa; ?>`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Error en la sincronización');
                            }
                            return response.json();
                        })
                        .catch(error => {
                            Swal.showValidationMessage(
                                `Error: ${error}`
                            );
                        });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: '¡Sincronizado!',
                        text: 'Los timbres se han actualizado correctamente.',
                        icon: 'success',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        };

        // Función para copiar API Key
        window.copiarApiKey = function() {
            const apiKeyContainer = document.getElementById('apiKeyContainer');
            if (apiKeyContainer) {
                const apiKey = apiKeyContainer.textContent;
                navigator.clipboard.writeText(apiKey).then(function() {
                    const btn = event.target.closest('button');
                    const originalHTML = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copiado!';
                    btn.classList.remove('btn-outline-light');
                    btn.classList.add('btn-success');

                    setTimeout(function() {
                        btn.innerHTML = originalHTML;
                        btn.classList.remove('btn-success');
                        btn.classList.add('btn-outline-light');
                    }, 2000);
                });
            }
        };

        // Función para confirmar agregar timbres
        window.confirmarAgregarTimbres = function() {
            const cantidadSelect = document.getElementById('cantidad_timbres');
            const cantidad = cantidadSelect.value;
            
            if (!cantidad) {
                Swal.fire({
                    title: 'Error',
                    text: 'Por favor seleccione una cantidad de timbres',
                    icon: 'error',
                    confirmButtonColor: '#dc3545'
                });
                return false;
            }

            Swal.fire({
                title: '¿Agregar timbres?',
                html: `Se agregarán <strong>${cantidad} timbres CFDI</strong> a los existentes.<br><br>
                       Timbres disponibles actuales: <strong><?php echo $empresa['timbres_disponibles']; ?></strong><br>
                       Total después de agregar: <strong><?php echo $empresa['timbres_disponibles']; ?> + ${cantidad} = <?php echo $empresa['timbres_disponibles'] + $cantidad; ?></strong>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, agregar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('formTimbres').submit();
                }
            });
            
            return false;
        };

        // =====================================================================
        // FUNCIONES PARA DRAG AND DROP
        // =====================================================================

        function setupDragAndDrop(dropZoneId, fileInputId) {
            const dropZone = document.getElementById(dropZoneId);
            const fileInput = document.getElementById(fileInputId);

            if (!dropZone || !fileInput) return;

            // Prevenir comportamientos por defecto
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            // Efectos visuales
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, highlight, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, unhighlight, false);
            });

            function highlight() {
                dropZone.classList.add('dragover');
            }

            function unhighlight() {
                dropZone.classList.remove('dragover');
            }

            // Manejar archivos soltados
            dropZone.addEventListener('drop', handleDrop, false);

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;

                if (files.length > 0) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            }

            // Click en la zona
            dropZone.addEventListener('click', () => {
                fileInput.click();
            });
        }

        // =====================================================================
        // FUNCIONES PARA VISTA PREVIA DE ARCHIVOS
        // =====================================================================

        function setupFilePreview(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);

            if (!input || !preview) return;

            input.addEventListener('change', function(e) {
                preview.innerHTML = '';

                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    const fileSize = (file.size / (1024 * 1024)).toFixed(2);

                    // Validar tamaño
                    if (file.size > 5 * 1024 * 1024) {
                        showToast('error', `El archivo ${file.name} excede el límite de 5MB`);
                        this.value = '';
                        return;
                    }

                    // Mostrar información del archivo
                    const fileInfo = document.createElement('div');
                    fileInfo.className = 'alert alert-success alert-dismissible fade show';
                    fileInfo.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle me-2"></i>
                    <div class="flex-grow-1">
                        <strong class="d-block">${file.name}</strong>
                        <small class="text-muted">${fileSize} MB • ${file.type || 'Archivo'}</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
                    preview.appendChild(fileInfo);

                    // Vista previa para imágenes
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const imgWrapper = document.createElement('div');
                            imgWrapper.className = 'mt-3 text-center';
                            imgWrapper.innerHTML = `
                        <p class="text-muted small mb-2">Vista previa:</p>
                        <img src="${e.target.result}" 
                             class="img-thumbnail rounded" 
                             style="max-width: 200px; max-height: 150px;">
                    `;
                            preview.appendChild(imgWrapper);
                        };
                        reader.readAsDataURL(file);
                    }

                    // Auto-eliminar alerta después de 5 segundos
                    setTimeout(() => {
                        if (fileInfo.parentNode) {
                            fileInfo.remove();
                        }
                    }, 5000);
                }
            });
        }

        // =====================================================================
        // FUNCIONES PARA CONFIRMACIONES
        // =====================================================================

        window.confirmarDesactivacion = function() {
            Swal.fire({
                title: '¿Desactivar empresa?',
                text: "Los usuarios no podrán acceder a esta cuenta hasta que sea reactivada.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, desactivar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'cambiar_estado_empresa.php?id=<?php echo $id_empresa; ?>&accion=desactivar';
                }
            });
        };

        window.confirmarActivacion = function() {
            Swal.fire({
                title: '¿Activar empresa?',
                text: "Los usuarios podrán acceder nuevamente a esta cuenta.",
                icon: 'success',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, activar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'cambiar_estado_empresa.php?id=<?php echo $id_empresa; ?>&accion=activar';
                }
            });
        };

        // =====================================================================
        // FUNCIONES PARA TOAST NOTIFICATIONS
        // =====================================================================

        function showToast(type, message) {
            let toastContainer = document.getElementById('toastContainer');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'toastContainer';
                toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(toastContainer);
            }

            const toastId = 'toast-' + Date.now();
            const toast = document.createElement('div');
            toast.id = toastId;
            toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : 'success'} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'check-circle'} me-2"></i>
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;

            toastContainer.appendChild(toast);
            const bsToast = new bootstrap.Toast(toast, {
                autohide: true,
                delay: 5000
            });
            bsToast.show();

            toast.addEventListener('hidden.bs.toast', function() {
                toast.remove();
            });
        }

        // =====================================================================
        // FUNCIONES PARA MOSTRAR MENSAJES
        // =====================================================================

        function showMessage(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show mt-3`;
            alertDiv.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'check-circle'} me-2"></i>
            <div>${message}</div>
        </div>
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    `;

            const existingAlerts = document.querySelectorAll('.alert');
            if (existingAlerts.length > 0) {
                existingAlerts[existingAlerts.length - 1].after(alertDiv);
            } else {
                const form = document.getElementById('empresaForm');
                if (form) {
                    form.parentNode.insertBefore(alertDiv, form);
                }
            }

            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // =====================================================================
        // FUNCIONES PARA VALIDACIÓN DE OBSERVACIONES
        // =====================================================================

        function validateObservations(estadoSelect, observacionesField, observacionesHelp) {
            if (estadoSelect.value === 'rechazado') {
                observacionesField.required = true;
                observacionesHelp.innerHTML = '<i class="fas fa-exclamation-circle me-1 text-danger"></i>Las observaciones son obligatorias para empresas rechazadas';
                observacionesHelp.classList.add('text-danger');
                observacionesHelp.classList.remove('text-muted');
            } else {
                observacionesField.required = false;
                observacionesHelp.innerHTML = '<i class="fas fa-info-circle me-1"></i>Las observaciones son obligatorias cuando el estado es "Rechazado"';
                observacionesHelp.classList.remove('text-danger');
                observacionesHelp.classList.add('text-muted');
            }
        }

        // =====================================================================
        // FUNCIONES PARA INFORMACIÓN DE TIMBRES
        // =====================================================================

        function actualizarInfoTimbres(planSelect, infoTimbres) {
            const selectedPlan = planSelect.value;

            switch (selectedPlan) {
                case 'premium':
                    infoTimbres.innerHTML = 'Plan Premium incluye 500 timbres iniciales. Puede agregar más timbres después.';
                    break;
                case 'emprendedor':
                    infoTimbres.innerHTML = 'Debe activar un paquete de timbres manualmente para crear la organización en Facturapi.';
                    break;
                case 'basico':
                    infoTimbres.innerHTML = 'Debe activar un paquete de timbres manualmente para crear la organización en Facturapi.';
                    break;
                case 'prueba':
                    infoTimbres.innerHTML = 'Plan de prueba no incluye timbres CFDI.';
                    break;
                default:
                    infoTimbres.innerHTML = 'Seleccione un plan para ver información específica.';
            }
        }

        // =====================================================================
        // FUNCIÓN PARA INFORMACIÓN DE PAQUETES DE TIMBRES EN MODAL
        // =====================================================================

        function setupTimbresSelect() {
            const selectTimbres = document.getElementById('cantidad_timbres');
            const infoTimbres = document.getElementById('infoTimbres');

            if (selectTimbres && infoTimbres) {
                function actualizarInfoPaquete() {
                    const cantidad = selectTimbres.value;
                    if (cantidad) {
                        let mensaje = `Paquete de ${cantidad} CFDI seleccionado. `;

                        <?php if (isset($empresa['plan'])): ?>
                            if ('<?php echo $empresa['plan']; ?>' === 'premium') {
                                mensaje += 'Se sumarán a los timbres existentes.';
                            } else {
                                mensaje += 'Se sumarán a los timbres existentes y se creará la organización en Facturapi si no existe.';
                            }
                        <?php endif; ?>

                        infoTimbres.innerHTML = mensaje;
                    } else {
                        <?php if (isset($empresa['plan'])): ?>
                            <?php if ($empresa['plan'] == 'premium'): ?>
                                infoTimbres.innerHTML = 'Para plan Premium: Se sumarán los timbres seleccionados a los existentes';
                            <?php elseif ($empresa['plan'] == 'emprendedor'): ?>
                                infoTimbres.innerHTML = 'Para plan Emprendedor: Se sumarán los timbres seleccionados a los existentes';
                            <?php elseif ($empresa['plan'] == 'basico'): ?>
                                infoTimbres.innerHTML = 'Para plan Básico: Se sumarán los timbres seleccionados a los existentes';
                            <?php else: ?>
                                infoTimbres.innerHTML = 'Plan de prueba no incluye timbres';
                            <?php endif; ?>
                        <?php endif; ?>
                    }
                }

                selectTimbres.addEventListener('change', actualizarInfoPaquete);
            }
        }

        // =====================================================================
        // DOCUMENT READY - INICIALIZACIÓN PRINCIPAL
        // =====================================================================

        document.addEventListener('DOMContentLoaded', function() {

            // =========================================
            // 1. OBTENER ELEMENTOS DEL DOM
            // =========================================
            const estadoSelect = document.getElementById('estado_verificacion');
            const observacionesField = document.getElementById('observaciones_verificacion');
            const observacionesHelp = document.getElementById('observaciones_help');
            const btnGuardar = document.getElementById('btnGuardar');
            const btnGuardarTexto = document.getElementById('btnGuardarTexto');
            const planSelect = document.getElementById('plan');
            const infoTimbres = document.getElementById('infoTimbres');
            const empresaForm = document.getElementById('empresaForm');

            // =========================================
            // 2. INICIALIZAR COMPONENTES
            // =========================================

            // Drag and drop
            setupDragAndDrop('constanciaDropZone', 'constancia_fiscal');
            setupDragAndDrop('credencialDropZone', 'credencial_identificacion');

            // Vista previa de archivos
            setupFilePreview('constancia_fiscal', 'constancia_preview');
            setupFilePreview('credencial_identificacion', 'credencial_preview');

            // Configurar select de timbres
            setupTimbresSelect();

            // =========================================
            // 3. CONFIGURAR EVENT LISTENERS
            // =========================================

            // Validación de observaciones
            if (estadoSelect && observacionesField && observacionesHelp) {
                validateObservations(estadoSelect, observacionesField, observacionesHelp);
                estadoSelect.addEventListener('change', function() {
                    validateObservations(estadoSelect, observacionesField, observacionesHelp);
                });
            }

            // Información de timbres según plan
            if (planSelect && infoTimbres) {
                planSelect.addEventListener('change', function() {
                    actualizarInfoTimbres(planSelect, infoTimbres);
                });
                actualizarInfoTimbres(planSelect, infoTimbres);
            }

            // =========================================
            // 4. VALIDACIÓN DEL FORMULARIO PRINCIPAL
            // =========================================

            const forms = document.querySelectorAll('.needs-validation');
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', function(event) {
                    // Validar plan premium
                    if (planSelect && planSelect.value === 'premium') {
                        const confirmar = confirm('¿Está seguro de crear la organización en Facturapi para el plan Premium?\n\nSe creará una organización con el nombre de la empresa y se asignarán 500 timbres iniciales.');
                        if (!confirmar) {
                            event.preventDefault();
                            event.stopPropagation();
                            return false;
                        }
                    }

                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();

                        const invalidFields = form.querySelectorAll(':invalid');
                        if (invalidFields.length > 0) {
                            invalidFields[0].focus();
                        }
                    }

                    form.classList.add('was-validated');
                }, false);
            });

            // =========================================
            // 5. EVENTO SUBMIT DEL FORMULARIO
            // =========================================

            if (empresaForm) {
                empresaForm.addEventListener('submit', function(e) {
                    // Validar observaciones para estado rechazado
                    if (estadoSelect && observacionesField) {
                        const estado = estadoSelect.value;
                        const observaciones = observacionesField.value.trim();

                        if (estado === 'rechazado' && observaciones === '') {
                            e.preventDefault();
                            showMessage('error', 'Debe ingresar observaciones cuando el estado es "Rechazado"');
                            observacionesField.focus();
                            return false;
                        }
                    }

                    // Mostrar estado de guardado
                    if (btnGuardar && btnGuardarTexto) {
                        btnGuardar.disabled = true;
                        btnGuardar.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Guardando...';
                        btnGuardarTexto.textContent = 'Guardando...';
                    }

                    return true;
                });
            }

            // =========================================
            // 6. DELEGACIÓN DE EVENTOS PARA BOTONES DINÁMICOS
            // =========================================

            $(document).on('click', '.ver-archivo', function(e) {
                e.preventDefault();
                const ruta = $(this).data('archivo');
                const tipo = $(this).data('tipo');
                const nombre = $(this).data('nombre');
                const titulo = $(this).data('titulo');

                if (ruta && tipo && nombre && titulo) {
                    if (typeof window.abrirArchivoModal === 'function') {
                        window.abrirArchivoModal(ruta, tipo, nombre, titulo);
                    } else {
                        window.open(ruta, '_blank');
                    }
                }
            });

            // =========================================
            // 7. EVENTOS DEL MODAL DE ARCHIVOS
            // =========================================

            const modalArchivo = document.getElementById('modalArchivo');
            if (modalArchivo) {
                modalArchivo.addEventListener('hidden.bs.modal', function() {
                    const imagenVisor = document.getElementById('imagenVisor');
                    const pdfVisor = document.getElementById('pdfVisor');
                    const cargando = document.getElementById('archivoCargando');
                    const visorImagen = document.getElementById('visorImagen');
                    const visorPDF = document.getElementById('visorPDF');
                    const visorError = document.getElementById('visorError');

                    if (imagenVisor) {
                        imagenVisor.src = '';
                        imagenVisor.style.width = '';
                        imagenVisor.style.height = '';
                    }
                    if (pdfVisor) {
                        pdfVisor.src = '';
                    }

                    if (cargando) cargando.classList.remove('d-none');
                    if (visorImagen) visorImagen.classList.add('d-none');
                    if (visorPDF) visorPDF.classList.add('d-none');
                    if (visorError) visorError.classList.add('d-none');
                });
            }

            // =========================================
            // 8. AJUSTE DE ALTURA DEL MODAL AL REDIMENSIONAR
            // =========================================

            $(window).on('resize', function() {
                const modal = document.getElementById('modalArchivo');
                if (modal && modal.classList.contains('show')) {
                    const pdfVisor = document.getElementById('pdfVisor');
                    if (pdfVisor && !pdfVisor.classList.contains('d-none')) {
                        const modalBody = modal.querySelector('.modal-body');
                        const modalHeader = modal.querySelector('.modal-header');
                        const modalFooter = modal.querySelector('.modal-footer');

                        if (modalBody && modalHeader && modalFooter) {
                            const headerHeight = modalHeader.offsetHeight;
                            const footerHeight = modalFooter.offsetHeight;
                            const windowHeight = window.innerHeight;
                            const maxModalHeight = windowHeight * 0.9;
                            const modalBodyHeight = maxModalHeight - headerHeight - footerHeight - 40;

                            pdfVisor.style.height = modalBodyHeight + 'px';
                        }
                    }
                }
            });

            // =========================================
            // 9. AUTO-ELIMINAR MENSAJES DEL SERVIDOR
            // =========================================

            const serverMessage = document.querySelector('.alert');
            if (serverMessage) {
                setTimeout(() => {
                    serverMessage.remove();
                }, 10000);
            }

        });
    </script>
</body>

</html>