<?php
// =============================================
// GESTIONAR_EMPRESA.PHP - Gestión de Empresas
// =============================================

// Cargar configuración de sesión personalizada
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

// Variables para el navbar y sidebar
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Administrador';
$usuario_rol = $_SESSION['usuario_rol'] ?? 'admin';

// Cargar configuración de base de datos
require_once __DIR__ . '../../config/database.php';
require_once __DIR__ . '../../env_loader.php';

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';
$empresa = null;
$id_empresa = isset($_GET['id']) ? intval($_GET['id']) : 0;

// API Key de Facturapi
$FACTURAPI_SECRET_KEY = "sk_user_MD3D8JvfsNHvtiR65bGokbH34FQyXo7GU65w85z1qA";

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
            while (true) {
                $response = $facturapi->Invoices->all([
                    'page' => $page,
                    'limit' => 100
                ]);

                if (!is_object($response) || !property_exists($response, 'data') || !is_array($response->data)) {
                    break;
                }

                $facturas = $response->data;

                if (count($facturas) === 0) {
                    break;
                }

                foreach ($facturas as $invoice) {
                    if (is_object($invoice)) {
                        $status = strtolower($invoice->status ?? '');
                        if ($status === 'valid' || $status === 'issued') {
                            $total_facturas_emitidas++;
                        }
                    }
                }

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
$giros_comerciales = [];
try {
    $pdo = getDBConnection();
    $sql_giros = "SELECT id, nombre FROM giro_comercial ORDER BY nombre";
    $stmt = $pdo->query($sql_giros);
    $resultados = $stmt->fetchAll();
    
    foreach ($resultados as $row) {
        $giros_comerciales[$row['id']] = $row['nombre'];
    }
} catch (PDOException $e) {
    $giros_comerciales = [];
    error_log("Error al cargar giros comerciales: " . $e->getMessage());
}

// Procesar mensajes de retorno
if (isset($_GET['mensaje'])) {
    if ($_GET['mensaje'] == 'success' && isset($_GET['timbres'])) {
        $mensaje = "Paquete de " . $_GET['timbres'] . " timbres CFDI activado correctamente.";
        $tipo_mensaje = "success";
    } elseif ($_GET['mensaje'] == 'sucursales_success' && isset($_GET['cantidad'])) {
        $mensaje = $_GET['cantidad'] . " sucursal(es) extra(s) agregada(s) correctamente.";
        $tipo_mensaje = "success";
    } elseif ($_GET['mensaje'] == 'error') {
        $mensaje = "Error al procesar la transacción.";
        $tipo_mensaje = "danger";
    }
}

// Cargar datos de la empresa si existe el ID
if ($id_empresa > 0) {
    try {
        $pdo = getDBConnection();
        
        $sql = "SELECT e.*, g.nombre as nombre_giro_comercial 
                FROM empresas e 
                LEFT JOIN giro_comercial g ON e.giro_comercial = g.id 
                WHERE e.id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_empresa]);
        $empresa = $stmt->fetch();

        if ($empresa) {
            // =============================================
            // ACTUALIZACIÓN AUTOMÁTICA DE TIMBRES USADOS
            // =============================================
            if (!empty($empresa['facturapi_organization_id']) && $empresa['plan'] !== 'prueba') {
                $test_api_key = '';

                try {
                    require_once dirname(__FILE__) . '/../vendor/autoload.php';
                    $facturapi_system = new \Facturapi\Facturapi($FACTURAPI_SECRET_KEY);

                    $result_api_key = $facturapi_system->Organizations->getTestApiKey($empresa['facturapi_organization_id']);

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

                if (!empty($test_api_key)) {
                    $facturas_emitidas = contarFacturasFacturapi($test_api_key, $empresa['nombre_empresa']);

                    $timbres_totales = $empresa['timbres_totales'] ?? 0;
                    $timbres_disponibles = max(0, $timbres_totales - $facturas_emitidas);

                    if ($empresa['timbres_disponibles'] != $timbres_disponibles) {
                        $sql_update_timbres = "UPDATE empresas SET timbres_disponibles = ? WHERE id = ?";
                        $stmt_update = $pdo->prepare($sql_update_timbres);
                        $stmt_update->execute([$timbres_disponibles, $id_empresa]);

                        $empresa['timbres_disponibles'] = $timbres_disponibles;

                        if (empty($mensaje)) {
                            $mensaje = "Timbres actualizados automáticamente desde Facturapi.";
                            $tipo_mensaje = "info";
                        }
                    }

                    $empresa['facturas_emitidas'] = $facturas_emitidas;
                    $empresa['test_api_key'] = $test_api_key;
                } else {
                    $empresa['facturas_emitidas'] = 0;
                }
            } else {
                $empresa['facturas_emitidas'] = 0;
            }

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
            error_log("==============================");
        } else {
            $mensaje = "No se encontró la empresa con ID: $id_empresa";
            $tipo_mensaje = "warning";
        }
    } catch (PDOException $e) {
        $mensaje = "Error al cargar la empresa: " . $e->getMessage();
        $tipo_mensaje = "danger";
        error_log("Error al cargar empresa: " . $e->getMessage());
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
        
        $precio_sin_iva = 1299;
        $precio_con_iva = 1506.84;
    } elseif ($plan === 'basico' && (!isset($empresa['plan']) || $empresa['plan'] !== 'basico')) {
        $precio_sin_iva = 299;
        $precio_con_iva = 346.84;
    } elseif ($plan === 'starter' && (!isset($empresa['plan']) || $empresa['plan'] !== 'starter')) {
        $precio_sin_iva = 599;
        $precio_con_iva = 694.84;
    } elseif ($plan === 'emprendedor' && (!isset($empresa['plan']) || $empresa['plan'] !== 'emprendedor')) {
        $precio_sin_iva = 999;
        $precio_con_iva = 1158.84;
    }

    // Crear directorios de uploads si no existen
    $uploads_dir = dirname(__FILE__) . '/../uploads/';
    $constancias_dir = $uploads_dir . 'constancias/';
    $credenciales_dir = $uploads_dir . 'credenciales/';

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
        $pdo = getDBConnection();
        
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

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
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
            ]);

            // Registrar cambio de plan si aplica
            if (isset($precio_sin_iva) && isset($precio_con_iva) && $empresa['plan'] != $plan) {
                $sql_insert_plan = "INSERT INTO activaciones_plan 
                                   (empresa_id, plan_anterior, plan_nuevo, precio_sin_iva, precio_con_iva, fecha_activacion, usuario_activo) 
                                   VALUES (?, ?, ?, ?, ?, NOW(), ?)";
                $stmt_plan = $pdo->prepare($sql_insert_plan);
                $usuario = $_SESSION['usuario_nombre'] ?? 'admin';
                $plan_anterior = $empresa['plan'] ?? null;
                $stmt_plan->execute([$id_empresa, $plan_anterior, $plan, $precio_sin_iva, $precio_con_iva, $usuario]);
            }

            // =============================================
            // CREAR ORGANIZACIÓN EN FACTURAPI
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
                    $stmt_facturapi = $pdo->prepare($sql_facturapi);
                    $stmt_facturapi->execute([$facturapi_id, $id_empresa]);

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

            if (empty($tipo_mensaje) || $tipo_mensaje == 'success') {
                $mensaje = "Empresa actualizada correctamente" . $mensaje;
                $tipo_mensaje = "success";
            }

            // Recargar datos actualizados
            $sql = "SELECT e.*, g.nombre as nombre_giro_comercial 
                    FROM empresas e 
                    LEFT JOIN giro_comercial g ON e.giro_comercial = g.id 
                    WHERE e.id = ?";
            $stmt_reload = $pdo->prepare($sql);
            $stmt_reload->execute([$id_empresa]);
            $empresa = $stmt_reload->fetch();

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

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
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
            ]);

            $id_empresa = $pdo->lastInsertId();

            // Registrar activación de plan para nueva empresa
            if ($plan != 'prueba') {
                $precios_planes = [
                    'basico' => ['sin_iva' => 299, 'con_iva' => 346.84],
                    'starter' => ['sin_iva' => 599, 'con_iva' => 694.84],
                    'emprendedor' => ['sin_iva' => 999, 'con_iva' => 1158.84],
                    'premium' => ['sin_iva' => 1299, 'con_iva' => 1506.84]
                ];
                
                if (isset($precios_planes[$plan])) {
                    $sql_insert_plan = "INSERT INTO activaciones_plan 
                                       (empresa_id, plan_anterior, plan_nuevo, precio_sin_iva, precio_con_iva, fecha_activacion, usuario_activo) 
                                       VALUES (?, NULL, ?, ?, ?, NOW(), ?)";
                    $stmt_plan = $pdo->prepare($sql_insert_plan);
                    $usuario = $_SESSION['usuario_nombre'] ?? 'admin';
                    $precio_sin_iva = $precios_planes[$plan]['sin_iva'];
                    $precio_con_iva = $precios_planes[$plan]['con_iva'];
                    $stmt_plan->execute([$id_empresa, $plan, $precio_sin_iva, $precio_con_iva, $usuario]);
                }
            }

            // =============================================
            // CREAR ORGANIZACIÓN EN FACTURAPI (PARA NUEVA EMPRESA)
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
                    $stmt_facturapi = $pdo->prepare($sql_facturapi);
                    $stmt_facturapi->execute([$facturapi_id, $id_empresa]);

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

            if (empty($tipo_mensaje) || $tipo_mensaje != 'danger') {
                $mensaje = "Empresa creada correctamente" . $mensaje;
                $tipo_mensaje = "success";
            }

            // Recargar datos
            $sql = "SELECT e.*, g.nombre as nombre_giro_comercial 
                    FROM empresas e 
                    LEFT JOIN giro_comercial g ON e.giro_comercial = g.id 
                    WHERE e.id = ?";
            $stmt_reload = $pdo->prepare($sql);
            $stmt_reload->execute([$id_empresa]);
            $empresa = $stmt_reload->fetch();
        }
    } catch (PDOException $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
        error_log("Error en gestionar_empresa: " . $e->getMessage());
    }
}

// Funciones auxiliares
function claseEstado($estado)
{
    switch ($estado) {
        case 'pendiente': return 'warning';
        case 'en_revision': return 'info';
        case 'aprobado': return 'success';
        case 'rechazado': return 'danger';
        default: return 'secondary';
    }
}

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

function textoPlan($plan)
{
    $planes = [
        'prueba' => 'Prueba',
        'basico' => 'Básico',
        'starter' => 'Starter',
        'emprendedor' => 'Emprendedor',
        'premium' => 'Premium'
    ];
    return $planes[$plan] ?? $plan;
}

function formatearFecha($fecha)
{
    if (empty($fecha) || $fecha == '0000-00-00 00:00:00') {
        return 'No registrada';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

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

function mostrarEstadisticasTimbres($empresa)
{
    if (!isset($empresa['plan']) || $empresa['plan'] === 'prueba') {
        return '';
    }

    $timbres_totales = $empresa['timbres_totales'] ?? 0;
    $timbres_disponibles = $empresa['timbres_disponibles'] ?? 0;
    $facturas_emitidas = $empresa['facturas_emitidas'] ?? ($timbres_totales - $timbres_disponibles);
    $porcentaje_usado = $timbres_totales > 0 ? round(($facturas_emitidas / $timbres_totales) * 100, 1) : 0;

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

function getPrecioPlan($plan) {
    $precios = [
        'prueba' => ['sin_iva' => 0, 'con_iva' => 0],
        'basico' => ['sin_iva' => 299, 'con_iva' => 346.84],
        'starter' => ['sin_iva' => 599, 'con_iva' => 694.84],
        'emprendedor' => ['sin_iva' => 999, 'con_iva' => 1158.84],
        'premium' => ['sin_iva' => 1299, 'con_iva' => 1506.84]
    ];
    return $precios[$plan] ?? ['sin_iva' => 0, 'con_iva' => 0];
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
    
    <!-- CSS de componentes compartidos -->
    <link rel="stylesheet" href="assets/css/navbar.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    
    <!-- CSS específico de gestionar empresa -->
    <link rel="stylesheet" href="assets/css/gestionar_empresa.css">
</head>

<body>
    <!-- ========================================== -->
    <!-- NAVBAR COMPONENTE -->
    <!-- ========================================== -->
    <?php include 'assets/components/navbar.php'; ?>

    <!-- ========================================== -->
    <!-- SIDEBAR COMPONENTE -->
    <!-- ========================================== -->
    <?php include 'assets/components/sidebar.php'; ?>

    <!-- ========================================== -->
    <!-- CONTENIDO PRINCIPAL -->
    <!-- ========================================== -->
    <main>
        <div class="container-fluid">
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
                                        <option value="prueba" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'prueba') ? 'selected' : ''; ?>>
                                            Prueba - $0 MXN
                                        </option>
                                        <option value="basico" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'basico') ? 'selected' : ''; ?>>
                                            Básico - $299 MXN + IVA = $346.84 MXN
                                        </option>
                                        <option value="starter" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'starter') ? 'selected' : ''; ?>>
                                            Starter - $599 MXN + IVA = $694.84 MXN
                                        </option>
                                        <option value="emprendedor" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'emprendedor') ? 'selected' : ''; ?>>
                                            Emprendedor - $999 MXN + IVA = $1,158.84 MXN
                                        </option>
                                        <option value="premium" <?php echo (isset($empresa['plan']) && $empresa['plan'] == 'premium') ? 'selected' : ''; ?>>
                                            Premium - $1,299 MXN + IVA = $1,506.84 MXN
                                        </option>
                                    </select>
                                    <label for="plan">Plan *</label>
                                    <div class="invalid-feedback">
                                        Por favor seleccione un plan.
                                    </div>
                                </div>
                                <!-- Mostrar resumen de precios -->
                                <div id="planPriceInfo" class="mt-2 p-3 bg-light rounded-3" style="display: <?php echo (isset($empresa['plan']) && $empresa['plan'] != '') ? 'block' : 'none'; ?>;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold">Subtotal:</span>
                                        <span id="planSubtotal">$0 MXN</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold">IVA (16%):</span>
                                        <span id="planIVA">$0 MXN</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center text-primary fw-bold">
                                        <span>Total:</span>
                                        <span id="planTotal">$0 MXN</span>
                                    </div>
                                </div>
                                <div class="form-text small text-muted mt-1">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <?php if ($id_empresa > 0 && isset($empresa['plan']) && $empresa['plan'] !== 'prueba'): ?>
                                        <strong>Nota:</strong> La organización en Facturapi se creará automáticamente al activar un paquete de timbres.
                                    <?php else: ?>
                                        Al seleccionar plan "Premium" se asignarán 500 timbres automáticamente.
                                        Para otros planes, deberá activar un paquete de timbres manualmente.
                                    <?php endif; ?>
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

                                <!-- Botones de acción para timbres y sucursales -->
                                <div class="text-center mt-4">
                                    <div class="btn-group flex-wrap" role="group">
                                        <?php if ($id_empresa > 0 && isset($empresa['plan']) && $empresa['plan'] !== 'prueba'): ?>
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTimbres">
                                                <i class="fas fa-plus-circle me-2"></i>Comprar Paquete de Timbres
                                            </button>

                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalSucursales">
                                                <i class="fas fa-store me-2"></i>Agregar Sucursal Extra
                                            </button>

                                            <button type="button" class="btn btn-outline-info" onclick="actualizarTimbresManual()">
                                                <i class="fas fa-sync-alt me-2"></i>Sincronizar con Facturapi
                                            </button>
                                        <?php endif; ?>
                                    </div>
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

                                                <?php if (isset($empresa['plan'])): ?>
                                                    <div class="mt-2">
                                                        <span class="badge bg-secondary px-3 py-2 rounded-pill">
                                                            <i class="fas fa-crown me-1"></i>
                                                            Plan: <?php echo textoPlan($empresa['plan']); ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>

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
        </div>
    </main>

    <!-- ========================================== -->
    <!-- MODALES -->
    <!-- ========================================== -->

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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Administrar Paquete de Timbres CFDI
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formTimbres" method="POST" action="administrar_timbres.php">
                        <input type="hidden" name="empresa_id" value="<?php echo $id_empresa; ?>">
                        <input type="hidden" name="accion_timbres" value="sumar">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Empresa:</label>
                                    <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($empresa['nombre_empresa']); ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Plan Actual:</label>
                                    <input type="text" class="form-control bg-light" value="<?php echo textoPlan($empresa['plan']); ?>" readonly>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Timbres Disponibles:</label>
                                    <input type="text" class="form-control bg-light" value="<?php echo $empresa['timbres_disponibles']; ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Timbres Totales:</label>
                                    <input type="text" class="form-control bg-light" value="<?php echo $empresa['timbres_totales']; ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="card border-primary mt-3">
                            <div class="card-header bg-primary bg-opacity-10">
                                <h6 class="mb-0 text-primary">
                                    <i class="fas fa-shopping-cart me-2"></i>Seleccionar Paquete de Timbres
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Cantidad</th>
                                                        <th class="text-end">Precio sin IVA</th>
                                                        <th class="text-end">IVA (16%)</th>
                                                        <th class="text-end">Precio con IVA</th>
                                                        <th class="text-center">Seleccionar</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td><strong>50 CFDI</strong></td>
                                                        <td class="text-end">$100.00</td>
                                                        <td class="text-end">$16.00</td>
                                                        <td class="text-end text-primary fw-bold">$116.00</td>
                                                        <td class="text-center">
                                                            <div class="form-check">
                                                                <input class="form-check-input precio-timbres" type="radio" name="cantidad_timbres" 
                                                                       value="50" data-precio="100" data-precio-iva="116" required>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>100 CFDI</strong></td>
                                                        <td class="text-end">$150.00</td>
                                                        <td class="text-end">$24.00</td>
                                                        <td class="text-end text-primary fw-bold">$174.00</td>
                                                        <td class="text-center">
                                                            <div class="form-check">
                                                                <input class="form-check-input precio-timbres" type="radio" name="cantidad_timbres" 
                                                                       value="100" data-precio="150" data-precio-iva="174">
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>200 CFDI</strong></td>
                                                        <td class="text-end">$200.00</td>
                                                        <td class="text-end">$32.00</td>
                                                        <td class="text-end text-primary fw-bold">$232.00</td>
                                                        <td class="text-center">
                                                            <div class="form-check">
                                                                <input class="form-check-input precio-timbres" type="radio" name="cantidad_timbres" 
                                                                       value="200" data-precio="200" data-precio-iva="232">
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>300 CFDI</strong></td>
                                                        <td class="text-end">$250.00</td>
                                                        <td class="text-end">$40.00</td>
                                                        <td class="text-end text-primary fw-bold">$290.00</td>
                                                        <td class="text-center">
                                                            <div class="form-check">
                                                                <input class="form-check-input precio-timbres" type="radio" name="cantidad_timbres" 
                                                                       value="300" data-precio="250" data-precio-iva="290">
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>500 CFDI</strong></td>
                                                        <td class="text-end">$300.00</td>
                                                        <td class="text-end">$48.00</td>
                                                        <td class="text-end text-primary fw-bold">$348.00</td>
                                                        <td class="text-center">
                                                            <div class="form-check">
                                                                <input class="form-check-input precio-timbres" type="radio" name="cantidad_timbres" 
                                                                       value="500" data-precio="300" data-precio-iva="348">
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Resumen de compra -->
                                <div id="resumenTimbres" class="mt-4 p-3 bg-light rounded-3" style="display: none;">
                                    <h6 class="fw-bold mb-3">Resumen de la compra:</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-2">Paquete seleccionado: <span id="paqueteSeleccionado" class="fw-bold">-</span></p>
                                            <p class="mb-2">Precio sin IVA: <span id="precioSinIVA" class="fw-bold">$0.00</span></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-2">IVA (16%): <span id="ivaTimbres" class="fw-bold">$0.00</span></p>
                                            <p class="mb-0 text-primary fw-bold">Total a pagar: <span id="totalTimbres" class="fs-5">$0.00</span></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Información:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>Los timbres no tienen fecha de vencimiento</li>
                                            <li>Se sumarán a los timbres disponibles existentes</li>
                                            <?php if (empty($empresa['facturapi_organization_id'])): ?>
                                                <li class="text-warning">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                                    Se creará automáticamente la organización en Facturapi
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formTimbres" class="btn btn-primary" onclick="return confirmarAgregarTimbres()">
                        <i class="fas fa-check me-2"></i>Confirmar y Procesar Pago
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Sucursales Extra -->
    <div class="modal fade" id="modalSucursales" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-store me-2"></i>
                        Agregar Sucursal Extra
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formSucursales" method="POST" action="administrar_sucursales.php">
                        <input type="hidden" name="empresa_id" value="<?php echo $id_empresa; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Empresa:</label>
                            <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($empresa['nombre_empresa']); ?>" readonly>
                        </div>

                        <div class="card border-success mb-4">
                            <div class="card-body text-center">
                                <h3 class="text-success mb-2">$499 MXN</h3>
                                <p class="text-muted mb-0">+ IVA = $578.84 MXN</p>
                                <small class="text-muted">Precio por sucursal extra</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Número de Sucursales a Agregar *</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="num_sucursales" name="num_sucursales" 
                                       min="1" max="10" value="1" required>
                                <span class="input-group-text">sucursal(es)</span>
                            </div>
                        </div>

                        <!-- Resumen dinámico -->
                        <div class="p-3 bg-light rounded-3">
                            <h6 class="fw-bold mb-3">Resumen de la compra:</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal:</span>
                                <span id="subtotalSucursales">$499.00</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>IVA (16%):</span>
                                <span id="ivaSucursales">$79.84</span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold text-success">
                                <span>Total:</span>
                                <span id="totalSucursales">$578.84</span>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formSucursales" class="btn btn-success" onclick="return confirmarAgregarSucursales()">
                        <i class="fas fa-check me-2"></i>Confirmar y Procesar Pago
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SCRIPTS -->
    <!-- ========================================== -->

    <!-- jQuery (necesario para AJAX) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- JS de navbar y sidebar (separados) -->
    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/sidebar.js"></script>
    
    <!-- JS específico de gestionar empresa -->
    <script src="assets/js/gestionar_empresa.js"></script>
</body>
</html>